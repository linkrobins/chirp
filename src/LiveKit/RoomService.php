<?php

namespace LinkRobins\Chirp\LiveKit;

use GuzzleHttp\Client;
use LinkRobins\Chirp\Channel;
use LinkRobins\Chirp\ChirpClient;
use Psr\Log\LoggerInterface;

/**
 * Thin client for the LiveKit server APIs the extension needs (twirp over
 * HTTPS on the signaling host): counting current publishers for speaker-slot
 * enforcement, moderation, and deleting the room when the host ends it
 * (disconnects every participant immediately).
 *
 * Every forum now shares one media server, so the room NAME carries the tenant
 * and this class never chooses one. It passes a discussion id to the service,
 * which derives the name from the channel and returns it along with a
 * sixty-second admin grant scoped to exactly that room. That is what stops one
 * forum from moderating another's room: we could not name it if we tried.
 *
 * Fail-soft on reads: if the count call fails we return null and the caller
 * decides — for slot enforcement that means fail-CLOSED (treat as full) so a
 * flaky link can't oversubscribe the stage past what the channel is paying for.
 */
class RoomService
{
    /** Grants are good for a minute; one lookup serves a burst of calls. */
    private array $grants = [];

    public function __construct(
        protected ChirpClient $service,
        protected Client $http,
        protected LoggerInterface $log,
    ) {
    }

    /** Number of participants currently allowed to publish, or null on failure. */
    public function publisherCount(Channel $channel, int $discussionId): ?int
    {
        if (!$grant = $this->grant($channel, $discussionId)) {
            return null;
        }

        $data = $this->call($channel, $grant, 'ListParticipants', ['room' => $grant['room']]);
        if ($data === null) {
            return null;
        }

        $count = 0;
        foreach (($data['participants'] ?? []) as $p) {
            if (!empty($p['permission']['can_publish']) || !empty($p['permission']['canPublish'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Best-effort room delete — kicks every participant. Goes through the
     * service, because DeleteRoom needs an instance-wide grant that must not be
     * handed to a forum on a shared server.
     */
    public function deleteRoom(Channel $channel, int $discussionId): void
    {
        $this->service->roomOp($channel, $discussionId, 'delete');
    }

    /** Stage moderation: revoke the publish grant — the server unpublishes
     *  their tracks and the client can't re-take the mic. */
    public function revokePublish(Channel $channel, int $discussionId, string $identity): void
    {
        if (!$grant = $this->grant($channel, $discussionId)) {
            return;
        }

        $this->call($channel, $grant, 'UpdateParticipant', [
            'room'       => $grant['room'],
            'identity'   => $identity,
            'permission' => ['can_subscribe' => true, 'can_publish' => false, 'can_publish_data' => true],
        ]);
    }

    /** Stage moderation: remove a participant from the room entirely. */
    public function removeParticipant(Channel $channel, int $discussionId, string $identity): void
    {
        if ($grant = $this->grant($channel, $discussionId)) {
            $this->call($channel, $grant, 'RemoveParticipant', ['room' => $grant['room'], 'identity' => $identity]);
        }
    }

    /**
     * Voice-channel moderation: server-side mute of every audio track the
     * participant is publishing (MutePublishedTrack needs track sids, so we
     * look them up first). Deliberately NOT a publish revoke — they can
     * unmute themselves and keep talking like a person, and a repeat
     * offender gets kicked instead.
     */
    public function muteAudio(Channel $channel, int $discussionId, string $identity): void
    {
        if (!$grant = $this->grant($channel, $discussionId)) {
            return;
        }

        $data = $this->call($channel, $grant, 'ListParticipants', ['room' => $grant['room']]);
        foreach (($data['participants'] ?? []) as $p) {
            if (($p['identity'] ?? '') !== $identity) {
                continue;
            }
            foreach (($p['tracks'] ?? []) as $track) {
                if (strtoupper((string) ($track['type'] ?? '')) === 'AUDIO' && !empty($track['sid'])) {
                    $this->call($channel, $grant, 'MutePublishedTrack', [
                        'room'      => $grant['room'],
                        'identity'  => $identity,
                        'track_sid' => $track['sid'],
                        'muted'     => true,
                    ]);
                }
            }
        }
    }

    /**
     * Is this room actually live on the server? true/false, or null when the
     * API can't answer (caller decides the failure posture).
     */
    public function roomExists(Channel $channel, int $discussionId): ?bool
    {
        $data = $this->service->roomOp($channel, $discussionId, 'exists');

        return $data === null ? null : (bool) ($data['exists'] ?? false);
    }

    /**
     * Pre-create the room so it carries metadata from its very first webhook
     * (rooms auto-created by the first join have none). Idempotent on the
     * LiveKit side; fail-soft here — a failed call means the room simply
     * starts unrecorded, never that going live breaks.
     */
    public function createRoom(Channel $channel, int $discussionId, array $metadata): void
    {
        $this->service->roomOp($channel, $discussionId, 'create', $metadata);
    }

    /**
     * The room name and admin token for one discussion, from the service.
     * Memoized for the request so a burst of moderation calls costs one round
     * trip rather than one each.
     *
     * @return array{endpoint:string,room:string,token:string}|null
     */
    protected function grant(Channel $channel, int $discussionId): ?array
    {
        $key = $channel->handle . ':' . $discussionId;

        if (!array_key_exists($key, $this->grants)) {
            $this->grants[$key] = $this->service->mintToken($channel, $discussionId, 'admin');
        }

        return $this->grants[$key];
    }

    protected function call(Channel $channel, array $grant, string $method, array $body): ?array
    {
        $base = $channel->httpEndpoint();
        if ($base === '') {
            return null;
        }

        try {
            $response = $this->http->post($base . '/twirp/livekit.RoomService/' . $method, [
                'json'            => $body,
                'headers'         => [
                    'Authorization' => 'Bearer ' . $grant['token'],
                    'Accept'        => 'application/json',
                ],
                'connect_timeout' => 3,
                'timeout'         => 5,
                'http_errors'     => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->log->warning('Chirp: room API call failed', ['method' => $method, 'status' => $response->getStatusCode()]);

                return null;
            }

            $data = json_decode((string) $response->getBody(), true);

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            $this->log->warning('Chirp: room API call threw', ['method' => $method, 'error' => $e->getMessage()]);

            return null;
        }
    }
}

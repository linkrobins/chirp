<?php

namespace LinkRobins\Chirp\LiveKit;

use GuzzleHttp\Client;
use LinkRobins\Chirp\Channel;
use Psr\Log\LoggerInterface;

/**
 * Thin client for the LiveKit server APIs the extension needs (twirp over
 * HTTPS on the signaling host): counting current publishers for speaker-
 * slot enforcement, moderation, and deleting the room when the host ends it
 * (disconnects every participant immediately).
 *
 * Multi-channel: every call is scoped to the Channel the room lives on —
 * different channels are different servers with different credentials.
 *
 * Fail-soft on reads: if the count call fails we return null and the caller
 * decides — for slot enforcement that means fail-CLOSED (treat as full) so a
 * flaky link can't oversubscribe the stage past what the channel is paying for.
 */
class RoomService
{
    public function __construct(
        protected AccessToken $tokens,
        protected Client $http,
        protected LoggerInterface $log,
    ) {
    }

    /** Number of participants currently allowed to publish, or null on failure. */
    public function publisherCount(Channel $channel, string $room): ?int
    {
        $data = $this->call($channel, 'ListParticipants', $room, ['room' => $room]);
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

    /** Best-effort room delete — kicks every participant. */
    public function deleteRoom(Channel $channel, string $room): void
    {
        $this->call($channel, 'DeleteRoom', $room, ['room' => $room]);
    }

    /** Stage moderation: revoke the publish grant — the server unpublishes
     *  their tracks and the client can't re-take the mic. */
    public function revokePublish(Channel $channel, string $room, string $identity): void
    {
        $this->call($channel, 'UpdateParticipant', $room, [
            'room'       => $room,
            'identity'   => $identity,
            'permission' => ['can_subscribe' => true, 'can_publish' => false, 'can_publish_data' => true],
        ]);
    }

    /** Stage moderation: remove a participant from the room entirely. */
    public function removeParticipant(Channel $channel, string $room, string $identity): void
    {
        $this->call($channel, 'RemoveParticipant', $room, ['room' => $room, 'identity' => $identity]);
    }

    /**
     * Voice-channel moderation: server-side mute of every audio track the
     * participant is publishing (MutePublishedTrack needs track sids, so we
     * look them up first). Deliberately NOT a publish revoke — they can
     * unmute themselves and keep talking like a person, and a repeat
     * offender gets kicked instead.
     */
    public function muteAudio(Channel $channel, string $room, string $identity): void
    {
        $data = $this->call($channel, 'ListParticipants', $room, ['room' => $room]);
        foreach (($data['participants'] ?? []) as $p) {
            if (($p['identity'] ?? '') !== $identity) {
                continue;
            }
            foreach (($p['tracks'] ?? []) as $track) {
                if (strtoupper((string) ($track['type'] ?? '')) === 'AUDIO' && !empty($track['sid'])) {
                    $this->call($channel, 'MutePublishedTrack', $room, [
                        'room'      => $room,
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
    public function roomExists(Channel $channel, string $room): ?bool
    {
        $data = $this->call($channel, 'ListRooms', $room, ['names' => [$room]]);
        if ($data === null) {
            return null;
        }

        foreach (($data['rooms'] ?? []) as $r) {
            if (($r['name'] ?? '') === $room) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pre-create the room so it carries metadata from its very first webhook
     * (rooms auto-created by the first join have none). Idempotent on the
     * LiveKit side; fail-soft here — a failed call means the room simply
     * starts unrecorded, never that going live breaks.
     */
    public function createRoom(Channel $channel, string $room, array $metadata): void
    {
        $this->call($channel, 'CreateRoom', $room, [
            'name'     => $room,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
        ]);
    }

    protected function call(Channel $channel, string $method, string $room, array $body): ?array
    {
        $base = $channel->httpEndpoint();
        if ($base === '') {
            return null;
        }

        try {
            $response = $this->http->post($base . '/twirp/livekit.RoomService/' . $method, [
                'json'            => $body,
                'headers'         => [
                    'Authorization' => 'Bearer ' . $this->tokens->forRoomAdmin($channel, $room),
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

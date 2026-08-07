<?php

namespace LinkRobins\Chirp\LiveKit;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * Thin client for the two LiveKit server APIs the extension needs (twirp over
 * HTTPS on the same signaling host): counting current publishers for speaker-
 * slot enforcement, and deleting the room when the host ends it (disconnects
 * every participant immediately).
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
    public function publisherCount(string $room): ?int
    {
        $data = $this->call('ListParticipants', $room, ['room' => $room]);
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
    public function deleteRoom(string $room): void
    {
        $this->call('DeleteRoom', $room, ['room' => $room]);
    }

    /**
     * Pre-create the room so it carries metadata from its very first webhook
     * (rooms auto-created by the first join have none). Idempotent on the
     * LiveKit side; fail-soft here — a failed call means the room simply
     * starts unrecorded, never that going live breaks.
     */
    public function createRoom(string $room, array $metadata): void
    {
        $this->call('CreateRoom', $room, [
            'name'     => $room,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
        ]);
    }

    protected function call(string $method, string $room, array $body): ?array
    {
        $base = $this->tokens->httpEndpoint();
        if ($base === '') {
            return null;
        }

        try {
            $response = $this->http->post($base . '/twirp/livekit.RoomService/' . $method, [
                'json'            => $body,
                'headers'         => [
                    'Authorization' => 'Bearer ' . $this->tokens->forRoomAdmin($room),
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

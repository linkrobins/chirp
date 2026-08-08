<?php

namespace LinkRobins\Chirp\LiveKit;

use LinkRobins\Chirp\Channel;

/**
 * Mints LiveKit-compatible access tokens (HS256 JWTs) locally with a
 * channel's API key/secret from the config exchange — the hosted service is
 * never in the join hot path. Hand-rolled on purpose: it's ~30 lines of
 * RFC 7519, and pulling in a JWT library for one signature would be the
 * extension's only heavyweight dependency.
 *
 * Multi-channel: every mint is scoped to an explicit Channel (a forum may
 * run several — each room lives on exactly one). "Is Chirp configured at
 * all" questions belong to the Channels repository, not here.
 */
class AccessToken
{
    /**
     * A participant join token. $identity must be unique per participant in
     * the room (LiveKit replaces an existing connection with the same
     * identity — which is exactly right for a user rejoining after a drop).
     */
    public function forParticipant(Channel $channel, string $room, string $identity, string $name, bool $canPublish, int $ttlSeconds = 21600): string
    {
        return $this->sign($channel, [
            'sub'   => $identity,
            'name'  => $name,
            'video' => [
                'room'           => $room,
                'roomJoin'       => true,
                'canPublish'     => $canPublish,
                'canSubscribe'   => true,
                // Data channel for lightweight in-room signals (hand-raise
                // later); harmless for listeners.
                'canPublishData' => true,
            ],
        ], $ttlSeconds);
    }

    /** A short-lived server token for room-admin API calls (list/delete/create).
     *  roomCreate rides along so CreateRoom (pre-creating a room to attach
     *  metadata, e.g. the record flag) works with the same token. */
    public function forRoomAdmin(Channel $channel, string $room): string
    {
        return $this->sign($channel, [
            'sub'   => 'chirp-server',
            'video' => [
                'room'       => $room,
                'roomAdmin'  => true,
                'roomCreate' => true,
                // ListRooms (the stale-room liveness probe) checks roomList,
                // not roomAdmin — without it the probe 401s and the reconcile
                // stays fail-closed busy forever.
                'roomList'   => true,
            ],
        ], 60);
    }

    protected function sign(Channel $channel, array $claims, int $ttlSeconds): string
    {
        $now    = time();
        $claims = array_merge($claims, [
            'iss' => $channel->apiKey,
            'nbf' => $now - 10,
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ]);

        $encode  = fn (array $part) => $this->b64(json_encode($part, JSON_UNESCAPED_SLASHES));
        $payload = $encode(['alg' => 'HS256', 'typ' => 'JWT']) . '.' . $encode($claims);
        $sig     = hash_hmac('sha256', $payload, $channel->apiSecret, true);

        return $payload . '.' . $this->b64($sig);
    }

    protected function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

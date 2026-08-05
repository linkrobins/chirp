<?php

namespace LinkRobins\Chirp\LiveKit;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Mints LiveKit-compatible access tokens (HS256 JWTs) locally with the
 * channel's API key/secret from the config exchange — the hosted service is
 * never in the join hot path. Hand-rolled on purpose: it's ~30 lines of
 * RFC 7519, and pulling in a JWT library for one signature would be the
 * extension's only heavyweight dependency.
 */
class AccessToken
{
    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    public function configured(): bool
    {
        return $this->settings->get('linkrobins-chirp.connected') === '1'
            && (string) $this->settings->get('linkrobins-chirp.api-secret') !== '';
    }

    /** wss:// signaling endpoint for the browser client. */
    public function endpoint(): string
    {
        return (string) $this->settings->get('linkrobins-chirp.endpoint');
    }

    /** https:// form of the endpoint, for server-to-server room API calls. */
    public function httpEndpoint(): string
    {
        return preg_replace('/^wss:/', 'https:', $this->endpoint()) ?? '';
    }

    public function speakerSlots(): int
    {
        return max(1, (int) $this->settings->get('linkrobins-chirp.speaker-slots', 1));
    }

    /**
     * A participant join token. $identity must be unique per participant in
     * the room (LiveKit replaces an existing connection with the same
     * identity — which is exactly right for a user rejoining after a drop).
     */
    public function forParticipant(string $room, string $identity, string $name, bool $canPublish, int $ttlSeconds = 21600): string
    {
        return $this->sign([
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

    /** A short-lived server token for room-admin API calls (list/delete). */
    public function forRoomAdmin(string $room): string
    {
        return $this->sign([
            'sub'   => 'chirp-server',
            'video' => [
                'room'      => $room,
                'roomAdmin' => true,
            ],
        ], 60);
    }

    protected function sign(array $claims, int $ttlSeconds): string
    {
        $now    = time();
        $claims = array_merge($claims, [
            'iss' => (string) $this->settings->get('linkrobins-chirp.api-key'),
            'nbf' => $now - 10,
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ]);

        $encode  = fn (array $part) => $this->b64(json_encode($part, JSON_UNESCAPED_SLASHES));
        $payload = $encode(['alg' => 'HS256', 'typ' => 'JWT']) . '.' . $encode($claims);
        $sig     = hash_hmac('sha256', $payload, (string) $this->settings->get('linkrobins-chirp.api-secret'), true);

        return $payload . '.' . $this->b64($sig);
    }

    protected function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

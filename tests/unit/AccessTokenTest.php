<?php

/*
 * This file is part of linkrobins/flarum-chirp.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Chirp\Tests\unit;

use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Chirp\LiveKit\AccessToken;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PHPUnit\Framework\Attributes\Test;

class AccessTokenTest extends MockeryTestCase
{
    private function tokens(array $settings = []): AccessToken
    {
        $settings += [
            'linkrobins-chirp.connected'     => '1',
            'linkrobins-chirp.endpoint'      => 'wss://chirp-me.linkrobins.com',
            'linkrobins-chirp.api-key'       => 'LKtestkey',
            'linkrobins-chirp.api-secret'    => 'sssssssssssssssssssssssssssssssssssssssssss',
            'linkrobins-chirp.speaker-slots' => '6',
        ];

        $repo = m::mock(SettingsRepositoryInterface::class);
        $repo->shouldReceive('get')->andReturnUsing(
            fn ($key, $default = null) => $settings[$key] ?? $default
        );

        return new AccessToken($repo);
    }

    private function decode(string $jwt): array
    {
        [$header, $payload, $sig] = explode('.', $jwt);
        $b64 = fn (string $s) => json_decode(base64_decode(strtr($s, '-_', '+/')), true);

        return [$b64($header), $b64($payload), $sig];
    }

    #[Test]
    public function a_participant_token_is_a_valid_livekit_jwt(): void
    {
        $jwt = $this->tokens()->forParticipant('d42', 'u7', 'Karl', canPublish: false);

        [$header, $payload, $sig] = $this->decode($jwt);

        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $header);
        $this->assertSame('LKtestkey', $payload['iss']);
        $this->assertSame('u7', $payload['sub']);
        $this->assertSame('Karl', $payload['name']);
        $this->assertSame('d42', $payload['video']['room']);
        $this->assertTrue($payload['video']['roomJoin']);
        $this->assertFalse($payload['video']['canPublish']);
        $this->assertTrue($payload['video']['canSubscribe']);
        $this->assertGreaterThan(time(), $payload['exp']);

        // Signature verifies against the secret (round-trip HMAC).
        [$h, $p] = explode('.', $jwt);
        $expected = rtrim(strtr(base64_encode(hash_hmac(
            'sha256',
            $h . '.' . $p,
            'sssssssssssssssssssssssssssssssssssssssssss',
            true
        )), '+/', '-_'), '=');
        $this->assertSame($expected, $sig);
    }

    #[Test]
    public function a_speaker_token_carries_the_publish_grant(): void
    {
        $jwt = $this->tokens()->forParticipant('d42', 'u7', 'Karl', canPublish: true);

        [, $payload] = $this->decode($jwt);

        $this->assertTrue($payload['video']['canPublish']);
    }

    #[Test]
    public function the_admin_token_is_room_scoped_and_short_lived(): void
    {
        $jwt = $this->tokens()->forRoomAdmin('d42');

        [, $payload] = $this->decode($jwt);

        $this->assertTrue($payload['video']['roomAdmin']);
        $this->assertSame('d42', $payload['video']['room']);
        $this->assertLessThanOrEqual(time() + 60, $payload['exp']);
    }

    #[Test]
    public function unconfigured_when_disconnected_or_missing_secret(): void
    {
        $this->assertTrue($this->tokens()->configured());
        $this->assertFalse($this->tokens(['linkrobins-chirp.connected' => '0'])->configured());
        $this->assertFalse($this->tokens(['linkrobins-chirp.api-secret' => ''])->configured());
    }

    #[Test]
    public function the_http_endpoint_is_the_wss_endpoint_reschemed(): void
    {
        $this->assertSame('https://chirp-me.linkrobins.com', $this->tokens()->httpEndpoint());
    }
}

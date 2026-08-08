<?php

/*
 * This file is part of linkrobins/flarum-chirp.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Chirp\Tests\unit;

use LinkRobins\Chirp\Channel;
use LinkRobins\Chirp\LiveKit\AccessToken;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PHPUnit\Framework\Attributes\Test;

class AccessTokenTest extends MockeryTestCase
{
    private function channel(array $overrides = []): Channel
    {
        return Channel::fromArray($overrides + [
            'handle'        => 'ch-test',
            'endpoint'      => 'wss://chirp-me.linkrobins.com',
            'api_key'       => 'LKtestkey',
            'api_secret'    => 'sssssssssssssssssssssssssssssssssssssssssss',
            'speaker_slots' => 6,
            'recordings'    => false,
            'connected'     => true,
        ]);
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
        $jwt = (new AccessToken())->forParticipant($this->channel(), 'd42', 'u7', 'Karl', canPublish: false);

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

        // Signature verifies against the channel's secret (round-trip HMAC).
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
        $jwt = (new AccessToken())->forParticipant($this->channel(), 'd42', 'u7', 'Karl', canPublish: true);

        [, $payload] = $this->decode($jwt);

        $this->assertTrue($payload['video']['canPublish']);
    }

    #[Test]
    public function the_admin_token_is_room_scoped_and_short_lived(): void
    {
        $jwt = (new AccessToken())->forRoomAdmin($this->channel(), 'd42');

        [, $payload] = $this->decode($jwt);

        $this->assertTrue($payload['video']['roomAdmin']);
        $this->assertSame('d42', $payload['video']['room']);
        $this->assertLessThanOrEqual(time() + 60, $payload['exp']);
    }

    #[Test]
    public function tokens_are_scoped_to_the_channel_that_minted_them(): void
    {
        $a = (new AccessToken())->forParticipant($this->channel(), 'd42', 'u7', 'Karl', canPublish: false);
        $b = (new AccessToken())->forParticipant(
            $this->channel(['api_key' => 'LKother', 'api_secret' => str_repeat('z', 40)]),
            'd42',
            'u7',
            'Karl',
            canPublish: false
        );

        [, $payloadA] = $this->decode($a);
        [, $payloadB] = $this->decode($b);

        $this->assertSame('LKtestkey', $payloadA['iss']);
        $this->assertSame('LKother', $payloadB['iss']);
        $this->assertNotSame(explode('.', $a)[2], explode('.', $b)[2]);
    }

    #[Test]
    public function the_http_endpoint_is_the_wss_endpoint_reschemed(): void
    {
        $this->assertSame('https://chirp-me.linkrobins.com', $this->channel()->httpEndpoint());
    }
}

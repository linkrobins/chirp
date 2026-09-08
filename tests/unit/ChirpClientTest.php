<?php

/*
 * This file is part of linkrobins/flarum-chirp.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Chirp\Tests\unit;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LinkRobins\Chirp\ChirpClient;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

class ChirpClientTest extends MockeryTestCase
{
    /** @var array<int, array{request: Request}> */
    private array $history = [];

    private function client(array $responses, ?string $serviceUrl = null): ChirpClient
    {
        $this->history = [];

        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $settings = m::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')
            ->with('linkrobins-chirp.service-url')
            ->andReturn($serviceUrl);

        return new ChirpClient(
            $settings,
            new NullLogger(),
            new Client(['handler' => $stack]),
            new \Flarum\Foundation\Config(['url' => 'https://forum.example.test'])
        );
    }

    #[Test]
    public function a_successful_exchange_returns_normalised_config(): void
    {
        $client = $this->client([
            new Response(200, [], json_encode([
                'endpoint'      => 'wss://chirp.linkrobins.com',
                'token_minting' => 'service',
                'speaker_slots' => 6,
            ])),
        ]);

        $config = $client->fetchConfig('CHANNEL-KEY');

        $this->assertSame([
            // No handle in the response → stable digest of the endpoint
            // stands in.
            'handle'        => substr(sha1('wss://chirp.linkrobins.com'), 0, 12),
            'endpoint'      => 'wss://chirp.linkrobins.com',
            // Kept so later token requests can authenticate as this channel.
            'setup_token'   => 'CHANNEL-KEY',
            'speaker_slots' => 6,
        ], $config);

        // The key travels as a form param to the default service URL.
        $request = $this->history[0]['request'];
        $this->assertSame('https://linkrobins.com/chirp/config', (string) $request->getUri());
        $this->assertStringContainsString('token=CHANNEL-KEY', (string) $request->getBody());
    }

    #[Test]
    public function missing_slots_default_to_one(): void
    {
        $client = $this->client([
            new Response(200, [], json_encode(['endpoint' => 'wss://x'])),
        ]);

        $this->assertSame(1, $client->fetchConfig('KEY')['speaker_slots']);
    }

    #[Test]
    public function a_non_200_is_null(): void
    {
        $client = $this->client([new Response(404, [], '{"error":"invalid or inactive token"}')]);

        $this->assertNull($client->fetchConfig('BAD-KEY'));
    }

    #[Test]
    public function a_response_missing_an_endpoint_is_null(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['handle' => 'me']))]);

        $this->assertNull($client->fetchConfig('KEY'));
    }

    #[Test]
    public function a_service_still_handing_out_a_signing_secret_is_refused(): void
    {
        // That answer comes from a service still running per-channel media
        // servers. This build has no local signer, so connecting would leave
        // the forum addressing a container that no longer exists.
        $client = $this->client([
            new Response(200, [], json_encode([
                'endpoint'   => 'wss://chirp-me.linkrobins.com',
                'api_key'    => 'LKabc',
                'api_secret' => 'secret',
            ])),
        ]);

        $this->assertNull($client->fetchConfig('KEY'));
    }

    #[Test]
    public function a_transport_failure_is_null_not_a_throw(): void
    {
        $client = $this->client([
            new ConnectException('refused', new Request('POST', '/chirp/config')),
        ]);

        $this->assertNull($client->fetchConfig('KEY'));
    }

    #[Test]
    public function a_blank_key_never_makes_a_request(): void
    {
        $client = $this->client([]);

        $this->assertNull($client->fetchConfig('   '));
        $this->assertCount(0, $this->history);
    }

    #[Test]
    public function the_service_url_setting_overrides_the_default(): void
    {
        $client = $this->client([
            new Response(200, [], json_encode(['endpoint' => 'wss://x', 'api_key' => 'k', 'api_secret' => 's'])),
        ], 'https://staging.example.test/');

        $client->fetchConfig('KEY');

        $this->assertSame('https://staging.example.test/chirp/config', (string) $this->history[0]['request']->getUri());
    }

    #[Test]
    public function minting_sends_a_discussion_id_and_never_a_room_name(): void
    {
        $client = $this->client([
            new Response(200, [], json_encode([
                'endpoint' => 'wss://chirp.linkrobins.com',
                'room'     => 'acme-x1y2z-d152',
                'token'    => 'JWT',
            ])),
        ]);

        $grant = $client->mintToken($this->channel(), 152, 'participant', [
            'identity' => 'u7', 'name' => 'Karl', 'publish' => true,
        ]);

        $this->assertSame([
            'endpoint' => 'wss://chirp.linkrobins.com',
            'room'     => 'acme-x1y2z-d152',
            'token'    => 'JWT',
        ], $grant);

        $body = (string) $this->history[0]['request']->getBody();
        $this->assertSame('https://linkrobins.com/chirp/token', (string) $this->history[0]['request']->getUri());
        $this->assertStringContainsString('discussion=152', $body);
        $this->assertStringContainsString('token=SETUP', $body);
        // The tenant boundary: the service picks the room, we never propose one.
        $this->assertStringNotContainsString('room=', $body);
    }

    #[Test]
    public function minting_without_a_setup_token_does_not_call_the_service(): void
    {
        $client = $this->client([]);
        $channel = new \LinkRobins\Chirp\Channel('acme', 'wss://x', '', 6, false, true);

        $this->assertNull($client->mintToken($channel, 152));
        $this->assertCount(0, $this->history);
    }

    #[Test]
    public function a_mint_response_without_a_room_is_null(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['token' => 'JWT']))]);

        $this->assertNull($client->mintToken($this->channel(), 152));
    }

    private function channel(): \LinkRobins\Chirp\Channel
    {
        return new \LinkRobins\Chirp\Channel('acme', 'wss://chirp.linkrobins.com', 'SETUP', 6, false, true);
    }
}

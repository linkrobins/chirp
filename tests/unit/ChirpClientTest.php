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

        return new ChirpClient($settings, new NullLogger(), new Client(['handler' => $stack]));
    }

    #[Test]
    public function a_successful_exchange_returns_normalised_config(): void
    {
        $client = $this->client([
            new Response(200, [], json_encode([
                'endpoint'      => 'wss://chirp-me.linkrobins.com',
                'api_key'       => 'LKabc',
                'api_secret'    => 'secret',
                'speaker_slots' => 6,
            ])),
        ]);

        $config = $client->fetchConfig('CHANNEL-KEY');

        $this->assertSame([
            'endpoint'      => 'wss://chirp-me.linkrobins.com',
            'api_key'       => 'LKabc',
            'api_secret'    => 'secret',
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
            new Response(200, [], json_encode([
                'endpoint'   => 'wss://x',
                'api_key'    => 'k',
                'api_secret' => 's',
            ])),
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
    public function a_response_missing_credentials_is_null(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['endpoint' => 'wss://x']))]);

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
}

<?php

/*
 * This file is part of linkrobins/flarum-chirp.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Chirp\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The recordings receiver (HMAC auth chain, fail-closed) and the streaming
 * endpoint's visibility gate. The happy delivery path needs a live recorder
 * URL, so it belongs to the bench E2E — here we prove every door is locked.
 */
class RecordingsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const SECRET = 'ssssssssssssssssssssssssssssssssssssssss';

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-chirp');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'Show night', 'slug' => 'show-night', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>hi</p></t>'],
            ],
            'chirp_recordings' => [
                ['id' => 5, 'discussion_id' => 1, 'user_id' => 1, 'status' => 'delivered', 'path' => 'does-not-exist.m4a', 'created_at' => Carbon::now(), 'delivered_at' => Carbon::now()],
            ],
        ]);
    }

    private function configure(): void
    {
        $this->setting('linkrobins-chirp.connected', '1');
        $this->setting('linkrobins-chirp.api-key', 'LKtest');
        $this->setting('linkrobins-chirp.api-secret', self::SECRET);
    }

    private function deliver(string $body, ?string $sig = null, string $key = 'LKtest')
    {
        $stream = new \Laminas\Diactoros\Stream('php://temp', 'wb+');
        $stream->write($body);
        $stream->rewind();

        return $this->send(
            $this->request('POST', '/api/chirp/recordings')
                ->withHeader('X-Chirp-Key', $key)
                ->withHeader('X-Chirp-Signature', $sig ?? hash_hmac('sha256', $body, self::SECRET))
                ->withHeader('Content-Type', 'application/json')
                ->withBody($stream)
        );
    }

    #[Test]
    public function unconfigured_forum_rejects_deliveries(): void
    {
        $response = $this->deliver(json_encode(['room' => 'd1']));

        $this->assertEquals(404, $response->getStatusCode());
    }

    #[Test]
    public function wrong_signature_is_rejected(): void
    {
        $this->configure();

        $response = $this->deliver(json_encode(['room' => 'd1']), 'not-the-right-signature');

        $this->assertEquals(401, $response->getStatusCode());
    }

    #[Test]
    public function wrong_key_is_rejected(): void
    {
        $this->configure();

        $response = $this->deliver(json_encode(['room' => 'd1']), null, 'LKother');

        $this->assertEquals(401, $response->getStatusCode());
    }

    #[Test]
    public function bad_room_name_is_rejected_even_signed(): void
    {
        $this->configure();

        $response = $this->deliver(json_encode(['room' => '../etc', 'download_url' => 'https://x.test/f']));

        $this->assertEquals(422, $response->getStatusCode());
    }

    #[Test]
    public function non_https_download_url_is_rejected(): void
    {
        $this->configure();

        $response = $this->deliver(json_encode(['room' => 'd1', 'download_url' => 'http://internal/f']));

        $this->assertEquals(422, $response->getStatusCode());
    }

    #[Test]
    public function streaming_missing_file_404s_but_visibility_gate_runs_first(): void
    {
        $this->configure();

        // Visible discussion + delivered row but the file is gone → 404.
        $response = $this->send($this->request('GET', '/api/chirp/recordings/5/audio', ['authenticatedAs' => 2]));
        $this->assertEquals(404, $response->getStatusCode());

        // Unknown recording → 404 (not an error page).
        $response = $this->send($this->request('GET', '/api/chirp/recordings/999/audio', ['authenticatedAs' => 2]));
        $this->assertEquals(404, $response->getStatusCode());
    }
}

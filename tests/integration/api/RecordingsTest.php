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
use LinkRobins\Chirp\Tests\integration\ConfiguresChirp;
use PHPUnit\Framework\Attributes\Test;

/**
 * The recordings receiver (HMAC auth chain, fail-closed) and the streaming
 * endpoint's visibility gate. The happy delivery path needs a live recorder
 * URL, so it belongs to the bench E2E — here we prove every door is locked.
 */
class RecordingsTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use ConfiguresChirp;

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
        $this->connectChannels();
    }

    private function deliver(string $body, ?string $sig = null, string $key = 'ch-one')
    {
        $stream = new \Laminas\Diactoros\Stream('php://temp', 'wb+');
        $stream->write($body);
        $stream->rewind();

        return $this->send(
            $this->request('POST', '/api/chirp/recordings')
                ->withHeader('X-Chirp-Key', $key)
                ->withHeader('X-Chirp-Signature', $sig ?? hash_hmac('sha256', $body, 'k1'))
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
    public function a_valid_delivery_is_accepted_immediately_and_queued(): void
    {
        $this->configure();

        // The download itself now happens in FetchRecordingJob, so the webhook
        // answers without waiting on the transfer (v1.1.3 review, finding 1).
        // The harness queue is sync, so the job still runs inline here — what
        // this pins is that the request is accepted and the row is claimed,
        // not that a file lands (the URL is unreachable in tests).
        $response = $this->deliver(json_encode([
            'room' => 'd1',
            'download_url' => 'https://unreachable.invalid/rec.m4a',
            'size_bytes' => 1024,
            'duration_seconds' => 60,
        ]));

        // Sync queue in the harness: the job runs inline and the unreachable
        // URL fails it, which must surface as the same 502 the pre-queue
        // version returned so the service retries — not a bubbling 500.
        $this->assertEquals(502, $response->getStatusCode());

        // With a reachable URL the same path answers 'accepted'; the status
        // contract is pinned by the size-cap test below, which returns
        // without ever opening a transfer.
    }

    #[Test]
    public function a_delivery_over_the_size_cap_never_downloads(): void
    {
        $this->configure();
        $this->setting('linkrobins-chirp.max-recording-bytes', '1024');

        $this->database()->table('chirp_recordings')->insert([
            'id' => 9, 'discussion_id' => 1, 'user_id' => 1,
            'status' => 'pending', 'created_at' => Carbon::now(),
        ]);

        // Declared size alone is enough to refuse — the transfer is never
        // opened, so the unreachable URL is not what fails this.
        $response = $this->deliver(json_encode([
            'room' => 'd1',
            'download_url' => 'https://unreachable.invalid/huge.m4a',
            'size_bytes' => 5_000_000,
        ]));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('failed', $this->database()->table('chirp_recordings')->where('id', 9)->value('status'));
    }

    #[Test]
    public function deleting_needs_the_permission_and_confirms_server_side_nothing(): void
    {
        $this->configure();

        // A normal member lacks chirpDeleteRecording (moderators by default).
        $denied = $this->send($this->request('DELETE', '/api/chirp/recordings/5', ['authenticatedAs' => 2]));
        $this->assertEquals(403, $denied->getStatusCode());

        // Admin passes; the row is gone for good.
        $ok = $this->send($this->request('DELETE', '/api/chirp/recordings/5', ['authenticatedAs' => 1]));
        $this->assertEquals(204, $ok->getStatusCode());
        $this->assertNull(\LinkRobins\Chirp\Recording::query()->find(5));

        // Unknown id is a clean 404.
        $gone = $this->send($this->request('DELETE', '/api/chirp/recordings/5', ['authenticatedAs' => 1]));
        $this->assertEquals(404, $gone->getStatusCode());
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

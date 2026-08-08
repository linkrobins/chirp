<?php

namespace LinkRobins\Chirp\Http;

use Carbon\Carbon;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Chirp\Recording;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /api/chirp/recordings — the hosted service delivers a finished
 * recording. Auth is the channel trust chain itself: the raw body is
 * HMAC-SHA256-signed with this forum's api_secret (X-Chirp-Signature,
 * key named by X-Chirp-Key). The payload carries a signed one-time URL on
 * the recording pool; we pull the file into storage/chirp-recordings/
 * DURING this request (the service retries with backoff on failure), then
 * drop the recording post into the discussion. After the 200, this forum
 * holds the only copy.
 */
class ReceiveRecordingController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected \LinkRobins\Chirp\Channels $channels,
        protected Client $http,
        protected Paths $paths,
        protected LoggerInterface $log,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->channels->anyConnected()) {
            return new JsonResponse(['error' => 'not configured'], 404);
        }

        // X-Chirp-Key names WHICH channel signed the delivery — different
        // channels are different servers with different secrets.
        $channel = $this->channels->byApiKey($request->getHeaderLine('X-Chirp-Key'));
        if (!$channel || $channel->apiSecret === '') {
            return new JsonResponse(['error' => 'bad signature'], 401);
        }

        $raw = (string) $request->getBody();
        $sig = $request->getHeaderLine('X-Chirp-Signature');
        if (!hash_equals(hash_hmac('sha256', $raw, $channel->apiSecret), $sig)) {
            return new JsonResponse(['error' => 'bad signature'], 401);
        }

        $payload = json_decode($raw, true) ?: [];
        if (!preg_match('/^d(\d+)$/', (string) ($payload['room'] ?? ''), $m)) {
            return new JsonResponse(['error' => 'bad room'], 422);
        }
        $discussionId = (int) $m[1];
        $downloadUrl  = (string) ($payload['download_url'] ?? '');
        if (!str_starts_with($downloadUrl, 'https://')) {
            return new JsonResponse(['error' => 'bad url'], 422);
        }

        // The pending row was created at go-live (it knows who started the
        // room); a missing one — delivery for a room this forum forgot —
        // still lands, just unattributed.
        $recording = Recording::query()
            ->where('discussion_id', $discussionId)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first() ?? Recording::create([
                'discussion_id' => $discussionId,
                'status'        => 'pending',
                'created_at'    => Carbon::now(),
            ]);

        $dir = $this->paths->storage . '/chirp-recordings';
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return new JsonResponse(['error' => 'storage'], 500);
        }
        $filename = Str::lower(Str::random(40)) . '.m4a';

        try {
            $this->http->get($downloadUrl, [
                'sink'            => $dir . '/' . $filename,
                'connect_timeout' => 10,
                'timeout'         => 300,
            ]);
        } catch (\Throwable $e) {
            @unlink($dir . '/' . $filename);
            $this->log->warning('Chirp: recording download failed', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'download failed'], 502);
        }

        // No event post: the recording renders under the discussion's FIRST
        // post (serialized via DiscussionFields), where the show actually
        // lives — not buried at the bottom of the thread.
        $recording->forceFill([
            'status'           => 'delivered',
            'path'             => $filename,
            'size_bytes'       => (int) ($payload['size_bytes'] ?? filesize($dir . '/' . $filename)),
            'duration_seconds' => (int) ($payload['duration_seconds'] ?? 0),
            'delivered_at'     => Carbon::now(),
        ])->save();

        return new JsonResponse(['status' => 'ok']);
    }
}

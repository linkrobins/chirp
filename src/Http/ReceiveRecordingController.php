<?php

namespace LinkRobins\Chirp\Http;

use Carbon\Carbon;
use Illuminate\Contracts\Bus\Dispatcher;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Chirp\Job\FetchRecordingJob;
use LinkRobins\Chirp\Recording;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /api/chirp/recordings — the hosted service delivers a finished
 * recording. Auth is the channel trust chain itself: the raw body is
 * HMAC-SHA256-signed with this forum's api_secret (X-Chirp-Signature,
 * key named by X-Chirp-Key).
 *
 * This handler only verifies and queues: the actual pull of the audio (a
 * large file, up to a five-minute transfer) happens in {@see FetchRecordingJob}
 * so a delivery can't hold a PHP-FPM worker hostage and starve a small pool
 * (v1.1.3 review, finding 1).
 *
 * Retry is preserved on BOTH paths: with a real queue driver it is the job's
 * $tries/$backoff, and on the default `sync` driver — where the job still runs
 * inline — a failure is caught below and answered 502, exactly the signal the
 * pre-queue version gave the service. After the job runs, this forum holds the
 * only copy.
 */
class ReceiveRecordingController implements RequestHandlerInterface
{
    public function __construct(
        protected \LinkRobins\Chirp\Channels $channels,
        protected Dispatcher $bus,
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

        // Deliberately NOT claiming the row with an in-progress status: there
        // is no updated_at here to expire one with, so a worker killed
        // mid-download would strand the row and make every later re-delivery
        // a no-op. A duplicate delivery instead queues a second job, which
        // discards its own copy when it finds the row already delivered.
        try {
            $this->bus->dispatch(new FetchRecordingJob(
                (int) $recording->id,
                $downloadUrl,
                (int) ($payload['size_bytes'] ?? 0),
                (int) ($payload['duration_seconds'] ?? 0),
            ));
        } catch (\Throwable $e) {
            // On the default `sync` driver the job runs INSIDE this call, so a
            // download failure lands here rather than in the queue's retry
            // machinery. Answer 502 exactly as the pre-queue version did, so
            // the service retries with backoff — a bubbling 500 would both
            // log an unhandled exception and lose that contract. On a real
            // queue driver dispatch returns immediately and retries are the
            // job's own ($tries/$backoff).
            $this->log->warning('Chirp: inline recording fetch failed', [
                'recording' => $recording->id,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'download failed'], 502);
        }

        return new JsonResponse(['status' => 'accepted']);
    }
}

<?php

namespace LinkRobins\Chirp\Job;

use Carbon\Carbon;
use Flarum\Foundation\Paths;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use LinkRobins\Chirp\Recording;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Pulls a finished recording from the service's signed one-time URL into
 * storage/chirp-recordings/ and marks the row delivered.
 *
 * Why a job: the download is the only slow thing in the delivery path (a
 * two-hour show is a large file), and it used to run inline in the webhook
 * request — holding a PHP-FPM worker for up to five minutes per delivery,
 * which starves a small pool. The webhook now verifies, records intent, and
 * returns 200 immediately.
 *
 * RETRY SEMANTICS MOVED WITH IT. The inline version answered 502 so the
 * SERVICE would retry with backoff; once the webhook returns 200 that signal
 * is gone, so retry lives here — three attempts with growing backoff, and a
 * failed() hook that puts the row back to 'pending' so a later re-delivery of
 * the same room is still accepted rather than shadowed by a stuck row. On the
 * default `sync` driver this runs inline as before and the controller turns a
 * throw back into that same 502, so forums without a worker lose nothing.
 */
class FetchRecordingJob extends AbstractJob
{
    public int $tries = 3;

    /** Comfortably past the download timeout below. */
    public int $timeout = 360;

    /** 1m, 5m, 15m — a pool hiccup or a cold file gets time to resolve. */
    public array $backoff = [60, 300, 900];

    /** Ceiling for an undeclared or oversized file (2 GB) unless overridden. */
    public const DEFAULT_MAX_BYTES = 2147483648;

    public function __construct(
        protected int $recordingId,
        protected string $downloadUrl,
        protected int $declaredBytes = 0,
        protected int $durationSeconds = 0,
    ) {
        parent::__construct();
    }

    public function handle(
        Client $http,
        Paths $paths,
        SettingsRepositoryInterface $settings,
        LoggerInterface $log,
    ): void {
        /** @var Recording|null $recording */
        $recording = Recording::query()->find($this->recordingId);

        // Row deleted between dispatch and pickup (admin cleared it): nothing
        // to attach the file to, so don't fetch it.
        if (!$recording || $recording->status === 'delivered') {
            return;
        }

        $maxBytes = (int) ($settings->get('linkrobins-chirp.max-recording-bytes') ?: self::DEFAULT_MAX_BYTES);

        // Refuse before writing a byte when the sender already declared more
        // than we accept — the cheapest possible rejection.
        if ($this->declaredBytes > 0 && $this->declaredBytes > $maxBytes) {
            $log->warning('Chirp: recording rejected, declared size over cap', [
                'recording' => $recording->id,
                'declared'  => $this->declaredBytes,
                'max'       => $maxBytes,
            ]);
            $recording->forceFill(['status' => 'failed'])->save();

            return;
        }

        $dir = $paths->storage . '/chirp-recordings';
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Chirp: recording storage directory is not writable: ' . $dir);
        }

        $filename = Str::lower(Str::random(40)) . '.m4a';
        $target = $dir . '/' . $filename;

        try {
            $http->get($this->downloadUrl, [
                'sink'            => $target,
                'connect_timeout' => 10,
                'timeout'         => 300,
                // Second gate: the declared size is the sender's word, this is
                // the transfer's own. Throwing from on_headers aborts before
                // the body streams to disk.
                'on_headers'      => function (ResponseInterface $response) use ($maxBytes) {
                    $length = (int) $response->getHeaderLine('Content-Length');
                    if ($length > $maxBytes) {
                        throw new \RuntimeException("recording exceeds the {$maxBytes}-byte cap ({$length})");
                    }
                },
            ]);
        } catch (\Throwable $e) {
            $this->cleanUp($target, $log);

            // Rethrow so the queue applies $backoff and, after $tries, calls
            // failed() — swallowing here would drop the recording silently.
            throw $e;
        }

        // A transfer with no Content-Length can still overshoot the cap; the
        // file on disk is the last word.
        $actual = (int) @filesize($target);
        if ($actual > $maxBytes) {
            $this->cleanUp($target, $log);
            $log->warning('Chirp: recording rejected, downloaded size over cap', [
                'recording' => $recording->id,
                'bytes'     => $actual,
                'max'       => $maxBytes,
            ]);
            $recording->forceFill(['status' => 'failed'])->save();

            return;
        }

        // A duplicate delivery can put two jobs on the same row. If the other
        // one finished while this was downloading, throw this copy away rather
        // than overwriting a good file and orphaning theirs on disk.
        if ($recording->fresh()?->status === 'delivered') {
            $this->cleanUp($target, $log);

            return;
        }

        // No event post: the recording renders under the discussion's FIRST
        // post (serialized via DiscussionFields), where the show actually
        // lives — not buried at the bottom of the thread.
        $recording->forceFill([
            'status'           => 'delivered',
            'path'             => $filename,
            'size_bytes'       => $this->declaredBytes ?: $actual,
            'duration_seconds' => $this->durationSeconds,
            'delivered_at'     => Carbon::now(),
        ])->save();
    }

    /**
     * Every attempt is spent. Leave the row 'pending' rather than 'failed' so
     * a later re-delivery for the same room still finds a row to claim.
     */
    public function failed(\Throwable $e): void
    {
        Recording::query()->where('id', $this->recordingId)
            ->where('status', '!=', 'delivered')
            ->update(['status' => 'pending']);
    }

    private function cleanUp(string $target, LoggerInterface $log): void
    {
        if (is_file($target) && !@unlink($target)) {
            // Not fatal — but a partial file nobody deletes is how a disk
            // fills up quietly.
            $log->warning('Chirp: could not remove a partial recording download', ['path' => $target]);
        }
    }
}

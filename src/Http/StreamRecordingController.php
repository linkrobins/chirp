<?php

namespace LinkRobins\Chirp\Http;

use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Filesystem\Factory;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use LinkRobins\Chirp\Recording;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/chirp/recordings/{id}/audio — stream a recording to anyone who
 * can SEE its discussion (guests included on public forums): the recording
 * inherits the discussion's visibility exactly, which is the whole privacy
 * model. Serves bounded windows for Range requests (max 8 MB per response —
 * a 206 may legally return less than asked; players just re-request), so
 * seeking works everywhere (Safari requires ranges for media) without ever
 * buffering a whole file in PHP memory.
 */
class StreamRecordingController implements RequestHandlerInterface
{
    private const WINDOW = 8 * 1024 * 1024;

    public function __construct(protected Factory $filesystem)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        $recording = Recording::query()
            ->where('id', (int) \Illuminate\Support\Arr::get($request->getQueryParams(), 'id'))
            ->where('status', 'delivered')
            ->first();
        if (!$recording || !$recording->path || str_contains($recording->path, '/')) {
            return new Response\EmptyResponse(404);
        }

        // Visibility gate — the reason this endpoint exists at all.
        Discussion::whereVisibleTo($actor)->findOrFail($recording->discussion_id);

        $disk = $this->filesystem->disk('chirp-recordings');
        if (!$disk->exists($recording->path)) {
            return new Response\EmptyResponse(404);
        }
        $size = (int) $disk->size($recording->path);

        $start = 0;
        $end   = min($size, self::WINDOW) - 1;
        $isRange = preg_match('/^bytes=(\d+)-(\d*)$/', $request->getHeaderLine('Range'), $m) === 1;
        if ($isRange) {
            $start = (int) $m[1];
            if ($start >= $size) {
                return (new Response\EmptyResponse(416))->withHeader('Content-Range', "bytes */{$size}");
            }
            $end = min($m[2] !== '' ? (int) $m[2] : $size - 1, $start + self::WINDOW - 1, $size - 1);
        }

        // readStream, not read: the whole point of the window is never
        // holding a multi-hour file in memory to serve 8 MB of it.
        $source = $disk->readStream($recording->path);

        // The exists() check above is not a guarantee — a concurrent delete
        // between there and here leaves $source false, and seeking/reading a
        // false handle emits warnings into the response before headers flush
        // (v1.1.4 review, finding 5).
        if (!is_resource($source)) {
            return new Response\EmptyResponse(404);
        }

        fseek($source, $start);
        $body = new Stream('php://temp', 'wb+');
        $body->write((string) fread($source, $end - $start + 1));
        fclose($source);
        $body->rewind();

        $response = (new Response($body, $isRange || $end < $size - 1 ? 206 : 200))
            ->withHeader('Content-Type', 'audio/mp4')
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Content-Length', (string) ($end - $start + 1))
            ->withHeader('Cache-Control', 'private, max-age=3600');

        if ($isRange || $end < $size - 1) {
            $response = $response->withHeader('Content-Range', "bytes {$start}-{$end}/{$size}");
        }

        return $response;
    }
}

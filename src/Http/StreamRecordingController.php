<?php

namespace LinkRobins\Chirp\Http;

use Flarum\Discussion\Discussion;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
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

    public function __construct(protected Paths $paths)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        $recording = Recording::query()
            ->where('id', (int) ($request->getAttribute('routeParameters')['id'] ?? 0))
            ->where('status', 'delivered')
            ->first();
        if (!$recording || !$recording->path || str_contains($recording->path, '/')) {
            return new Response\EmptyResponse(404);
        }

        // Visibility gate — the reason this endpoint exists at all.
        Discussion::whereVisibleTo($actor)->findOrFail($recording->discussion_id);

        $file = $this->paths->storage . '/chirp-recordings/' . $recording->path;
        $size = @filesize($file);
        if ($size === false) {
            return new Response\EmptyResponse(404);
        }

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

        $fh = fopen($file, 'rb');
        fseek($fh, $start);
        $body = new Stream('php://temp', 'wb+');
        $body->write((string) fread($fh, $end - $start + 1));
        fclose($fh);
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

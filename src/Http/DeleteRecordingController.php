<?php

namespace LinkRobins\Chirp\Http;

use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use LinkRobins\Chirp\Recording;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * DELETE /chirp/recordings/{id} — remove a recording for good. Gated on its
 * own permission (chirpDeleteRecording, moderators by default): the forum
 * holds the ONLY copy of the audio, so this is genuinely irreversible — the
 * front end confirms before calling, and the file goes with the row.
 */
class DeleteRecordingController implements RequestHandlerInterface
{
    public function __construct(
        protected Factory $filesystem,
        protected LoggerInterface $log,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        /** @var Recording|null $recording */
        $recording = Recording::query()->find((int) Arr::get($request->getQueryParams(), 'id'));
        if (!$recording) {
            return new EmptyResponse(404);
        }

        /** @var Discussion $discussion */
        $discussion = Discussion::whereVisibleTo($actor)->findOrFail($recording->discussion_id);
        $actor->assertCan('chirpDeleteRecording', $discussion);

        // The forum holds the ONLY copy, so a file we fail to delete is a
        // leak nobody would otherwise hear about — log it rather than
        // swallowing the error. The row still goes: a stranded file is
        // better than a listing that can't be removed.
        if ($recording->path && !str_contains($recording->path, '/')) {
            $disk = $this->filesystem->disk('chirp-recordings');
            if ($disk->exists($recording->path) && !$disk->delete($recording->path)) {
                $this->log->warning('Chirp: could not delete a recording file', [
                    'recording' => $recording->id,
                    'file' => $recording->path,
                ]);
            }
        }
        $recording->delete();

        return new EmptyResponse(204);
    }
}

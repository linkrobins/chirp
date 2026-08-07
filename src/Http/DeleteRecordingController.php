<?php

namespace LinkRobins\Chirp\Http;

use Flarum\Discussion\Discussion;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use LinkRobins\Chirp\Recording;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /chirp/recordings/{id} — remove a recording for good. Gated on its
 * own permission (chirpDeleteRecording, moderators by default): the forum
 * holds the ONLY copy of the audio, so this is genuinely irreversible — the
 * front end confirms before calling, and the file goes with the row.
 */
class DeleteRecordingController implements RequestHandlerInterface
{
    public function __construct(protected Paths $paths)
    {
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

        if ($recording->path && !str_contains($recording->path, '/')) {
            @unlink($this->paths->storage . '/chirp-recordings/' . $recording->path);
        }
        $recording->delete();

        return new EmptyResponse(204);
    }
}

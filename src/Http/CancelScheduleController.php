<?php

namespace LinkRobins\Chirp\Http;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use LinkRobins\Chirp\Schedule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /chirp/rooms/{id}/schedule — withdraw the announcement. The person
 * who scheduled it or anyone holding chirpStart (moderation always can).
 */
class CancelScheduleController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $discussionId = (int) Arr::get($request->getQueryParams(), 'id');
        /** @var Schedule $schedule */
        $schedule = Schedule::query()->where('discussion_id', $discussionId)->firstOrFail();

        if ($schedule->user_id !== $actor->id) {
            $actor->assertCan('chirpStart', $schedule->discussion);
        }

        $schedule->delete();

        return new EmptyResponse(204);
    }
}

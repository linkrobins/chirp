<?php

namespace LinkRobins\Chirp\Http;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\UserState;
use Flarum\Http\RequestUtil;
use Flarum\Notification\NotificationSyncer;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Chirp\Notification\RoomScheduledBlueprint;
use LinkRobins\Chirp\Schedule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /chirp/rooms/{id}/schedule {startsAt} — announce a future room:
 * "going live Friday 8pm". chirpStart holders only (the same people who can
 * actually go live). Re-scheduling replaces the previous announcement, and
 * followers get a heads-up notification so they can plan to show up — the
 * whole point of scheduling over just going live.
 */
class ScheduleRoomController implements RequestHandlerInterface
{
    public function __construct(protected NotificationSyncer $notifications)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $discussionId = (int) Arr::get($request->getQueryParams(), 'id');
        /** @var Discussion $discussion */
        $discussion = Discussion::whereVisibleTo($actor)->findOrFail($discussionId);

        $actor->assertCan('chirpStart', $discussion);

        try {
            $startsAt = Carbon::parse((string) Arr::get($request->getParsedBody(), 'startsAt'));
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'bad date'], 422);
        }
        if ($startsAt->isPast() || $startsAt->gt(Carbon::now()->addYear())) {
            return new JsonResponse(['error' => 'bad date'], 422);
        }

        Schedule::query()->updateOrCreate(
            ['discussion_id' => $discussion->id],
            ['user_id' => $actor->id, 'starts_at' => $startsAt, 'created_at' => Carbon::now()]
        );

        // Heads-up to followers — fail-soft, a notification hiccup must never
        // block scheduling (and forums without flarum/subscriptions have no
        // follow state at all).
        try {
            $followers = UserState::query()
                ->where('discussion_id', $discussion->id)
                ->where('subscription', 'follow')
                ->where('user_id', '!=', $actor->id)
                ->with('user')
                ->get()
                ->pluck('user')
                ->filter()
                ->all();
            if ($followers) {
                $this->notifications->sync(new RoomScheduledBlueprint($discussion, $actor, $startsAt->toIso8601String()), $followers);
            }
        } catch (\Throwable) {
            // Silence is the contract.
        }

        return new JsonResponse(['status' => 'ok', 'startsAt' => $startsAt->toIso8601String()]);
    }
}

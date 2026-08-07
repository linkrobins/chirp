<?php

namespace LinkRobins\Chirp\Http;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Chirp\Hand;
use LinkRobins\Chirp\Room;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /chirp/rooms/{id}/hand — raise a hand in the live room on discussion
 * {id} (policy 'hand'). Registered users who can see the discussion and hold
 * the base chirpSpeak permission; a previously declined hand can be raised
 * again (people change their minds mid-show, hosts do too).
 */
class RaiseHandController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $discussionId = (int) Arr::get($request->getQueryParams(), 'id');
        /** @var Discussion $discussion */
        $discussion = Discussion::whereVisibleTo($actor)->findOrFail($discussionId);
        $actor->assertCan('chirpSpeak', $discussion);

        /** @var Room $room */
        $room = Room::query()->where('discussion_id', $discussion->id)->firstOrFail();
        if ($room->speak_policy !== 'hand') {
            return new JsonResponse(['error' => 'policy'], 409);
        }

        $hand = Hand::query()->firstOrNew(['room_id' => $room->id, 'user_id' => $actor->id]);
        if ($hand->status !== 'approved') {
            $hand->status = 'pending';
        }
        $hand->created_at = $hand->created_at ?? Carbon::now();
        $hand->save();

        return new JsonResponse(['status' => $hand->status]);
    }
}

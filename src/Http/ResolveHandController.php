<?php

namespace LinkRobins\Chirp\Http;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Chirp\Hand;
use LinkRobins\Chirp\Room;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /chirp/rooms/{id}/hand/{userId} {action: approve|decline} — the host
 * resolves a raised hand. Approval is what the token endpoint checks; the
 * host's data-channel ping just tells the requester to go grab the mic now.
 */
class ResolveHandController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $params       = $request->getQueryParams();
        $discussionId = (int) Arr::get($params, 'id');
        $userId       = (int) Arr::get($params, 'userId');

        /** @var Room $room */
        $room = Room::query()->where('discussion_id', $discussionId)->firstOrFail();
        if ($room->user_id !== $actor->id) {
            $actor->assertCan('chirpStart', $room->discussion);
        }

        $action = (string) Arr::get($request->getParsedBody(), 'action');
        if (!in_array($action, ['approve', 'decline'], true)) {
            return new JsonResponse(['error' => 'bad action'], 422);
        }

        $hand = Hand::query()->where('room_id', $room->id)->where('user_id', $userId)->firstOrFail();
        $hand->status = $action === 'approve' ? 'approved' : 'declined';
        $hand->save();

        return new JsonResponse(['status' => $hand->status]);
    }
}

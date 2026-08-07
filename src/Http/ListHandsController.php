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
 * GET /chirp/rooms/{id}/hands — pending hands for the host's bar (initial
 * render; live updates ride the data channel). Host/chirpStart only.
 */
class ListHandsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $discussionId = (int) Arr::get($request->getQueryParams(), 'id');
        /** @var Room $room */
        $room = Room::query()->where('discussion_id', $discussionId)->firstOrFail();

        if ($room->user_id !== $actor->id) {
            $actor->assertCan('chirpStart', $room->discussion);
        }

        $hands = [];
        foreach (Hand::query()->with('user')->where('room_id', $room->id)->where('status', 'pending')->orderBy('created_at')->get() as $hand) {
            /** @var Hand $hand */
            $user    = $hand->user;
            $hands[] = [
                'userId' => (int) $hand->user_id,
                'name'   => $user instanceof \Flarum\User\User ? $user->display_name : '?',
            ];
        }

        return new JsonResponse(['hands' => $hands]);
    }
}

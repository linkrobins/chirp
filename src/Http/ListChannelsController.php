<?php

namespace LinkRobins\Chirp\Http;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Chirp\Room;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /chirp/channels — the designated voice channels, for the admin panel's
 * management list (designate/remove lives there; removal = the same DELETE
 * as ending a room).
 */
class ListChannelsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $channels = Room::query()
            ->where('mode', 'persistent')
            ->with('discussion:id,title,slug')
            ->orderBy('id')
            ->get()
            ->map(fn (Room $room) => [
                'discussionId' => (int) $room->discussion_id,
                'title'        => (string) ($room->discussion->title ?? '#' . $room->discussion_id),
                'slug'         => (string) ($room->discussion->slug ?? $room->discussion_id),
            ])
            ->values()
            ->all();

        return new JsonResponse(['channels' => $channels]);
    }
}

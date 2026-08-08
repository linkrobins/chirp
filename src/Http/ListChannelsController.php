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
    public function __construct(protected \LinkRobins\Chirp\Channels $channels)
    {
    }

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

        // Occupancy for the panel: each purchased channel powers one
        // standing voice channel; when used == total the next designation
        // needs another channel.
        [$used, $total] = $this->channels->persistentSlots();

        // Per-key exchange status for the keys panel, in the admin's key
        // order (the exchange stores entries positionally). Handles are
        // service-side names, safe to show an admin.
        $keys = array_map(fn ($c) => [
            'connected' => $c->connected,
            'handle'    => $c->handle,
        ], $this->channels->all());

        return new JsonResponse([
            'channels' => $channels,
            'slots'    => ['used' => $used, 'total' => $total],
            'keys'     => $keys,
        ]);
    }
}

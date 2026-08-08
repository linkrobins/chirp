<?php

namespace LinkRobins\Chirp\Http;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use LinkRobins\Chirp\Channels;
use LinkRobins\Chirp\LiveKit\RoomService;
use LinkRobins\Chirp\Room;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /chirp/rooms/{id} — end the live room on discussion {id}. Allowed
 * for the person who went live and anyone holding chirpStart on the
 * discussion (moderation must always be able to pull the plug). Deletes the
 * LiveKit room too (best-effort) so every participant disconnects now, not
 * at token expiry.
 */
class EndRoomController implements RequestHandlerInterface
{
    public function __construct(
        protected RoomService $rooms,
        protected Channels $channels,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $discussionId = (int) Arr::get($request->getQueryParams(), 'id');
        /** @var Room $room */
        $room = Room::query()->where('discussion_id', $discussionId)->firstOrFail();

        if ($room->mode === 'persistent') {
            // A designated voice channel is admin infrastructure — only an
            // admin removes the designation (from the admin panel or here).
            $actor->assertAdmin();
        } elseif ($room->user_id !== $actor->id) {
            $actor->assertCan('chirpStart', $room->discussion);
        }

        // Resolve the channel BEFORE deleting the row (forRoom reads it).
        $channel = $this->channels->forRoom($room);

        $room->delete();
        if ($channel) {
            $this->rooms->deleteRoom($channel, Room::nameFor($discussionId));
        }

        return new EmptyResponse(204);
    }
}

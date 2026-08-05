<?php

namespace LinkRobins\Chirp\Http;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Chirp\Exception\ChannelBusyException;
use LinkRobins\Chirp\Exception\NotConfiguredException;
use LinkRobins\Chirp\LiveKit\AccessToken;
use LinkRobins\Chirp\Room;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /chirp/rooms {discussionId} — go live on a discussion. Gated on the
 * chirpStart permission + being able to see the discussion; enforces the
 * channel-wide one-live-room rule atomically (the unique discussion_id index
 * plus a transaction-scoped existence check make a double-click or a race
 * between two moderators fail loudly, not doubly). Returns the host's join
 * token (publish) so going live and being on stage are one action.
 */
class StartRoomController implements RequestHandlerInterface
{
    public function __construct(protected AccessToken $tokens)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if (!$this->tokens->configured()) {
            throw new NotConfiguredException();
        }

        $discussionId = (int) Arr::get($request->getParsedBody(), 'discussionId');
        /** @var Discussion $discussion */
        $discussion = Discussion::whereVisibleTo($actor)->findOrFail($discussionId);

        $actor->assertCan('chirpStart', $discussion);

        $room = Room::query()->getConnection()->transaction(function () use ($discussion, $actor) {
            if (Room::query()->lockForUpdate()->exists()) {
                throw new ChannelBusyException();
            }

            return Room::create([
                'discussion_id' => $discussion->id,
                'user_id'       => $actor->id,
                'created_at'    => Carbon::now(),
            ]);
        });

        return new JsonResponse([
            'endpoint' => $this->tokens->endpoint(),
            'token'    => $this->tokens->forParticipant(
                Room::nameFor($discussion->id),
                'u' . $actor->id,
                $actor->display_name,
                canPublish: true,
            ),
            'roomId'   => $room->id,
        ]);
    }
}

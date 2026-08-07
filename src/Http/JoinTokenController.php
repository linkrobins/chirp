<?php

namespace LinkRobins\Chirp\Http;

use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Chirp\Exception\NotConfiguredException;
use LinkRobins\Chirp\Exception\SlotsFullException;
use LinkRobins\Chirp\Exception\SpeakDeniedException;
use LinkRobins\Chirp\Hand;
use LinkRobins\Chirp\LiveKit\AccessToken;
use LinkRobins\Chirp\LiveKit\RoomService;
use LinkRobins\Chirp\Room;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /chirp/rooms/{id}/token — a join token for the live room on
 * discussion {id}. Anyone who can SEE the discussion can listen (guests
 * included — unlimited listeners is the product's headline). The mic is
 * granted when the caller asks to speak, holds chirpSpeak (or chirpStart) on
 * the discussion, and a slot is free — counted live against the channel's
 * speaker_slots. The count is fail-CLOSED: if the room API can't answer, no
 * publish grant, because oversubscribing the stage past what the channel pays
 * for is worse than one retry.
 */
class JoinTokenController implements RequestHandlerInterface
{
    public function __construct(
        protected AccessToken $tokens,
        protected RoomService $rooms,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        if (!$this->tokens->configured()) {
            throw new NotConfiguredException();
        }

        $discussionId = (int) Arr::get($request->getQueryParams(), 'id');
        /** @var Discussion $discussion */
        $discussion = Discussion::whereVisibleTo($actor)->findOrFail($discussionId);

        // No live room → nothing to join.
        /** @var Room $room */
        $room = Room::query()->where('discussion_id', $discussion->id)->firstOrFail();

        $roomName    = Room::nameFor($discussion->id);
        $wantsToTalk = (bool) Arr::get($request->getParsedBody(), 'speak', false);
        $canPublish  = false;

        if ($wantsToTalk && !$actor->isGuest()
            && ($actor->can('chirpSpeak', $discussion) || $actor->can('chirpStart', $discussion))) {
            // The room's speaker policy gates the grant BEFORE the slot
            // count. Hosts (the person who went live) and chirpStart holders
            // pass every policy — moderation must always be able to talk.
            // VOICE CHANNELS have no policies at all: joining IS speaking
            // (Discord-shaped) — moderation there is mute/kick, not a gate.
            $isHost = $room->user_id === $actor->id || $actor->can('chirpStart', $discussion);
            if (!$isHost && $room->mode !== 'persistent') {
                if ($room->speak_policy === 'op' && $actor->id !== (int) $discussion->user_id) {
                    throw new SpeakDeniedException();
                }
                if ($room->speak_policy === 'hand'
                    && !Hand::query()->where('room_id', $room->id)->where('user_id', $actor->id)->where('status', 'approved')->exists()) {
                    throw new SpeakDeniedException();
                }
            }

            $publishers = $this->rooms->publisherCount($roomName);
            if ($publishers === null || $publishers >= $this->tokens->speakerSlots()) {
                throw new SlotsFullException();
            }
            $canPublish = true;
        }

        // Identity: stable per user (a rejoin replaces the dropped session);
        // random per guest (two guests must never displace each other).
        $identity = $actor->isGuest() ? 'g' . Str::lower(Str::random(12)) : 'u' . $actor->id;
        $name     = $actor->isGuest() ? 'Guest' : $actor->display_name;

        return new JsonResponse([
            'endpoint'   => $this->tokens->endpoint(),
            'token'      => $this->tokens->forParticipant($roomName, $identity, $name, $canPublish),
            'canPublish' => $canPublish,
        ]);
    }
}

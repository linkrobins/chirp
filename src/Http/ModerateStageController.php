<?php

namespace LinkRobins\Chirp\Http;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Chirp\Channels;
use LinkRobins\Chirp\Hand;
use LinkRobins\Chirp\LiveKit\RoomService;
use LinkRobins\Chirp\Room;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /chirp/rooms/{id}/stage {identity, action} — the host moderates the
 * stage. Two unambiguous actions (issue: live audio without host moderation
 * leaves "end the whole room" as the only tool against a disruptive
 * speaker):
 *   unstage — revoke the publish grant server-side; they drop to listener
 *             and, in hand mode, their approval is declined so the mic
 *             doesn't come straight back.
 *   kick    — remove them from the room entirely.
 * Both are LiveKit admin calls — the client can't ignore them.
 */
class ModerateStageController implements RequestHandlerInterface
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

        if ($room->user_id !== $actor->id) {
            $actor->assertCan('chirpStart', $room->discussion);
        }

        $identity = (string) Arr::get($request->getParsedBody(), 'identity');
        $action   = (string) Arr::get($request->getParsedBody(), 'action');
        if (!preg_match('/^u\d+$/', $identity) || !in_array($action, ['unstage', 'kick', 'mute'], true)) {
            return new JsonResponse(['error' => 'bad request'], 422);
        }
        if ($identity === 'u' . $actor->id) {
            return new JsonResponse(['error' => 'not yourself'], 422); // Leave/mute cover self
        }

        $channel = $this->channels->forRoom($room);
        if (!$channel) {
            return new JsonResponse(['error' => 'not configured'], 409);
        }

        $roomName = Room::nameFor($discussionId);
        if ($action === 'kick') {
            $this->rooms->removeParticipant($channel, $roomName, $identity);
        } elseif ($action === 'mute') {
            // Voice channels: a soft hand — server-side track mute.
            $this->rooms->muteAudio($channel, $roomName, $identity);
        } else {
            $this->rooms->revokePublish($channel, $roomName, $identity);
        }

        // Hand mode: an approved hand would put the mic straight back —
        // moderation decision wins.
        Hand::query()->where('room_id', $room->id)
            ->where('user_id', (int) substr($identity, 1))
            ->update(['status' => 'declined']);

        return new JsonResponse(['status' => 'ok']);
    }
}

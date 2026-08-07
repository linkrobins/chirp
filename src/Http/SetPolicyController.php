<?php

namespace LinkRobins\Chirp\Http;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Chirp\Room;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /chirp/rooms/{id}/policy {policy} — the host flips the room's speaker
 * policy live from the bar ({id} = discussion id, like end-room). Allowed
 * for whoever went live and anyone holding chirpStart. Existing speakers
 * keep their mic on a tighten — the policy gates NEW grants, and the host's
 * mute/end controls cover the rest.
 */
class SetPolicyController implements RequestHandlerInterface
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

        $policy = (string) Arr::get($request->getParsedBody(), 'policy');
        if (!in_array($policy, ['open', 'hand', 'op'], true)) {
            return new JsonResponse(['error' => 'bad policy'], 422);
        }

        $room->speak_policy = $policy;
        $room->save();

        return new JsonResponse(['status' => 'ok', 'policy' => $policy]);
    }
}

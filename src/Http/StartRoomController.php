<?php

namespace LinkRobins\Chirp\Http;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Flarum\Discussion\UserState;
use Flarum\Notification\NotificationSyncer;
use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Chirp\Exception\ChannelBusyException;
use LinkRobins\Chirp\Exception\NotConfiguredException;
use LinkRobins\Chirp\LiveKit\AccessToken;
use LinkRobins\Chirp\LiveKit\RoomService;
use LinkRobins\Chirp\Notification\RoomStartedBlueprint;
use LinkRobins\Chirp\Recording;
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
    public function __construct(
        protected AccessToken $tokens,
        protected RoomService $rooms,
        protected SettingsRepositoryInterface $settings,
        protected NotificationSyncer $notifications,
    ) {
    }

    /** Recording is on when the channel PAYS for it and the admin wants it. */
    private function recordingActive(): bool
    {
        return $this->settings->get('linkrobins-chirp.recordings-available') === '1'
            && $this->settings->get('linkrobins-chirp.record-rooms') !== '0';
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

        // 'live' = the event shape (starts, ends, leaves a recording);
        // 'persistent' = a Discord-style voice channel that stays open until
        // the host closes it. Both occupy the channel: one container, one
        // room at a time.
        $mode = Arr::get($request->getParsedBody(), 'mode') === 'persistent' ? 'persistent' : 'live';

        $room = Room::query()->getConnection()->transaction(function () use ($discussion, $actor, $mode) {
            $existing = Room::query()->lockForUpdate()->first();
            if ($existing) {
                // A PERSISTENT room legitimately outlives its LiveKit room
                // (empty = the server reaped it; rejoining recreates it), so
                // it occupies the channel until the host closes it — never
                // reconciled away.
                if ($existing->mode === 'persistent') {
                    throw new ChannelBusyException();
                }
                // A LIVE room that ends NATURALLY (everyone leaves, the
                // server's departure timeout closes it) never passes through
                // EndRoom, so its row lingers and would wedge the channel
                // with 409s forever. Ask the server whether that room is
                // really still live; only a confirmed-dead room clears the
                // row (API failure stays fail-closed = busy).
                if ($this->rooms->roomExists(Room::nameFor($existing->discussion_id)) !== false) {
                    throw new ChannelBusyException();
                }
                $existing->delete();
            }

            return Room::create([
                'discussion_id' => $discussion->id,
                'user_id'       => $actor->id,
                'created_at'    => Carbon::now(),
                'mode'          => $mode,
                // Forum-wide default; the host can flip it live from the bar.
                'speak_policy'  => in_array($p = (string) $this->settings->get('linkrobins-chirp.default-speak-policy', 'open'), ['open', 'hand', 'op'], true) ? $p : 'open',
            ]);
        });

        // Recording: pre-create the LiveKit room so its metadata carries the
        // record flag into the service's room_started webhook, and stash a
        // pending row NOW — it's the only moment the starter is known (the
        // room row is deleted at end, the file arrives minutes later).
        // Voice channels are places, not shows — they are never recorded.
        if ($mode === 'live' && $this->recordingActive()) {
            $this->rooms->createRoom(Room::nameFor($discussion->id), ['record' => true]);
            Recording::create([
                'discussion_id' => $discussion->id,
                'user_id'       => $actor->id,
                'status'        => 'pending',
                'created_at'    => Carbon::now(),
            ]);
        }

        // Tell the discussion's followers the room is on — fail-soft: a
        // notification hiccup must never block going live.
        try {
            $followers = UserState::query()
                ->where('discussion_id', $discussion->id)
                ->where('subscription', 'follow')
                ->where('user_id', '!=', $actor->id)
                ->with('user')
                ->get()
                ->pluck('user')
                ->filter()
                ->all();
            if ($followers) {
                $this->notifications->sync(new RoomStartedBlueprint($discussion, $actor, $mode), $followers);
            }
        } catch (\Throwable $e) {
            // Logged by Flarum's handler if it cares; the room is live either way.
        }

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

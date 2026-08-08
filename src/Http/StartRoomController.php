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
use LinkRobins\Chirp\Channel;
use LinkRobins\Chirp\Channels;
use LinkRobins\Chirp\Exception\ChannelBusyException;
use LinkRobins\Chirp\Exception\ChannelsExhaustedException;
use LinkRobins\Chirp\Exception\NotConfiguredException;
use LinkRobins\Chirp\LiveKit\AccessToken;
use LinkRobins\Chirp\LiveKit\RoomService;
use LinkRobins\Chirp\Notification\RoomStartedBlueprint;
use LinkRobins\Chirp\Recording;
use LinkRobins\Chirp\Schedule;
use LinkRobins\Chirp\Room;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /chirp/rooms {discussionId} — go live on a discussion. Gated on the
 * chirpStart permission + being able to see the discussion.
 *
 * Multi-channel: every purchased channel powers ONE designated voice channel
 * (mode 'persistent') plus ONE live broadcast at a time. Starting a room
 * claims a free channel's slot atomically (transaction-scoped existence
 * checks + the unique discussion_id index make a double-click or a race
 * between two moderators fail loudly, not doubly); no free channel = the
 * "add another channel" moment, as a clean 409. Returns the host's join
 * token (publish) so going live and being on stage are one action.
 */
class StartRoomController implements RequestHandlerInterface
{
    public function __construct(
        protected AccessToken $tokens,
        protected RoomService $rooms,
        protected Channels $channels,
        protected SettingsRepositoryInterface $settings,
        protected NotificationSyncer $notifications,
    ) {
    }

    /** Recording is on when THIS channel pays for it and the admin wants it. */
    private function recordingActive(Channel $channel): bool
    {
        return $channel->recordings
            && $this->settings->get('linkrobins-chirp.record-rooms') !== '0';
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if (!$this->channels->anyConnected()) {
            throw new NotConfiguredException();
        }

        $discussionId = (int) Arr::get($request->getParsedBody(), 'discussionId');
        /** @var Discussion $discussion */
        $discussion = Discussion::whereVisibleTo($actor)->findOrFail($discussionId);

        $actor->assertCan('chirpStart', $discussion);

        // 'live' = the event shape (starts, ends, leaves a recording);
        // 'persistent' = an ADMIN-designated voice channel — a standing
        // Discord-style place that stays open until an admin removes the
        // designation. The two occupy SEPARATE slots on a channel: a
        // channel's standing place and its live show coexist.
        $mode = Arr::get($request->getParsedBody(), 'mode') === 'persistent' ? 'persistent' : 'live';

        if ($mode === 'persistent') {
            $actor->assertAdmin();
        }

        [$room, $channel] = Room::query()->getConnection()->transaction(function () use ($discussion, $actor, $mode) {
            // One bar per discussion, whatever its shape: an existing room
            // here (live show or designated channel) blocks another.
            if (Room::query()->lockForUpdate()->where('discussion_id', $discussion->id)->exists()) {
                throw new ChannelBusyException();
            }

            if ($mode === 'live') {
                // Claim a channel with no live broadcast. A LIVE room that
                // ended NATURALLY (everyone left, the server's departure
                // timeout closed it) never passes through EndRoom, so its
                // row lingers and would wedge its channel with 409s forever
                // — before giving up, probe each busy channel's room and
                // clear the confirmed-dead ones (API failure stays
                // fail-closed = busy).
                $channel = $this->channels->freeForLive();

                if (!$channel) {
                    foreach (Room::query()->lockForUpdate()->where('mode', 'live')->get() as $existing) {
                        // Grace window: a JUST-started room has no server-side
                        // presence until its host's WebRTC connect lands (the
                        // media server only creates rooms on first join unless
                        // recording pre-created it) — indistinguishable from a
                        // dead room to the probe. Don't reconcile rooms younger
                        // than a minute or a racing second host can silently
                        // delete a live-in-a-moment room. (Found by the 2ch
                        // drill: an API-only start with no join was eaten.)
                        if ($existing->created_at->gt(Carbon::now()->subMinute())) {
                            continue;
                        }
                        $existingChannel = $this->channels->forRoom($existing);
                        if ($existingChannel
                            && $this->rooms->roomExists($existingChannel, Room::nameFor($existing->discussion_id)) === false) {
                            $existing->delete();
                        }
                    }
                    $channel = $this->channels->freeForLive();
                }

                if (!$channel) {
                    throw new ChannelBusyException();
                }
            } else {
                // A new standing voice channel needs a channel that isn't
                // already powering one — that's exactly what a channel buys.
                $channel = $this->channels->freeForPersistent();
                if (!$channel) {
                    throw new ChannelsExhaustedException();
                }
            }

            $room = Room::create([
                'discussion_id' => $discussion->id,
                'user_id'       => $actor->id,
                'created_at'    => Carbon::now(),
                'mode'          => $mode,
                'channel'       => $channel->handle,
                // Forum-wide default; the host can flip it live from the bar.
                'speak_policy'  => in_array($p = (string) $this->settings->get('linkrobins-chirp.default-speak-policy', 'open'), ['open', 'hand', 'op'], true) ? $p : 'open',
            ]);

            return [$room, $channel];
        });

        // The show the schedule announced is now ON — consume it.
        try {
            Schedule::query()->where('discussion_id', $discussion->id)->delete();
        } catch (\Throwable) {
            // Never let schedule bookkeeping block going live.
        }

        // Recording: pre-create the LiveKit room so its metadata carries the
        // record flag into the service's room_started webhook, and stash a
        // pending row NOW — it's the only moment the starter is known (the
        // room row is deleted at end, the file arrives minutes later).
        // Voice channels are places, not shows — they are never recorded.
        if ($mode === 'live' && $this->recordingActive($channel)) {
            $this->rooms->createRoom($channel, Room::nameFor($discussion->id), ['record' => true]);
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
            'endpoint' => $channel->endpoint,
            'token'    => $this->tokens->forParticipant(
                $channel,
                Room::nameFor($discussion->id),
                'u' . $actor->id,
                $actor->display_name,
                canPublish: true,
            ),
            'roomId'   => $room->id,
        ]);
    }
}

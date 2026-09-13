<?php

namespace LinkRobins\Chirp\Api;

use Flarum\Api\Serializer\AbstractSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Chirp\Room;
use LinkRobins\Chirp\Schedule;

/**
 * Chirp state on every discussion payload. Fail-closed per the contracts: a
 * throw in any getter degrades to false rather than 500ing the whole
 * discussion list.
 *
 * 1.x shape: an `Extend\ApiSerializer->attributes()` mutator rather than 2.0's
 * typed field objects, so this is one invokable returning the whole block
 * instead of one closure per field. The actor comes from the serializer
 * instead of an API context, and that is the only behavioural difference —
 * every value below is computed exactly as it is on the 2.x line, and the two
 * must stay that way or the shared frontend diverges between majors.
 *
 * N+1 note: rooms are ONE live show forum-wide plus a handful of designated
 * voice channels, so instead of a per-row relationship read we load the whole
 * (tiny) table once per request and index by discussion id — one query per
 * request, zero per row. The memo lives on this instance (container-resolved
 * per request), never in a static, per the persistent-runtime rule.
 */
class DiscussionFields
{
    /** @var array<int, Room>|null keyed by discussion_id; null = not fetched */
    private ?array $rooms = null;

    /** @var array<int, string>|null discussion_id => ISO starts_at; null = not fetched */
    private ?array $schedules = null;

    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function __invoke(AbstractSerializer $serializer, Discussion $discussion, array $attributes): array
    {
        $actor = $serializer->getActor();
        $id = (int) $discussion->id;

        $attributes['chirpIsLive'] = $this->guard(fn () => $this->roomFor($id) !== null, false);

        $attributes['canChirpStart'] = $this->guard(
            fn () => $this->settings->get('linkrobins-chirp.connected') === '1'
                && $actor->can('chirpStart', $discussion),
            false
        );

        $attributes['canChirpSpeak'] = $this->guard(
            fn () => ! $actor->isGuest()
                && ($actor->can('chirpSpeak', $discussion) || $actor->can('chirpStart', $discussion)),
            false
        );

        // Live-room speaker policy trio — null/false everywhere except THE
        // live discussion. chirpSpeakEligible is the policy-aware "may this
        // actor pursue the mic right now" (in 'hand' mode it means "may raise
        // a hand"); the token endpoint re-enforces.
        $attributes['chirpSpeakPolicy'] = $this->guard(function () use ($id) {
            $room = $this->roomFor($id);

            return $room ? (string) $room->speak_policy : null;
        }, null);

        $attributes['chirpRoomMode'] = $this->guard(function () use ($id) {
            $room = $this->roomFor($id);

            return $room ? (string) $room->mode : null;
        }, null);

        $attributes['chirpRoomHostId'] = $this->guard(function () use ($id) {
            $room = $this->roomFor($id);

            return $room ? (int) $room->user_id : null;
        }, null);

        $attributes['chirpSpeakEligible'] = $this->guard(function () use ($id, $actor, $discussion) {
            $room = $this->roomFor($id);
            if (! $room) {
                return false;
            }
            if ($actor->isGuest()) {
                return false;
            }
            if ($room->user_id === $actor->id || $actor->can('chirpStart', $discussion)) {
                return true;
            }
            // Voice channels have no speaker policies — joining is speaking
            // for anyone holding chirpSpeak.
            if ($room->mode !== 'persistent' && $room->speak_policy === 'op') {
                return $actor->id === (int) $discussion->user_id;
            }

            return $actor->can('chirpSpeak', $discussion);
        }, false);

        // The announced future room, if any — a stale one (host never showed)
        // ages out after a 3h grace instead of lingering forever.
        $attributes['chirpScheduledAt'] = $this->guard(fn () => $this->scheduleFor($id), null);

        return $attributes;
    }

    /**
     * Fail-closed wrapper. 2.0 gets this per field from the schema's own
     * try/catch; here one helper keeps every attribute degrading to its safe
     * value rather than taking the whole discussion list down with it.
     *
     * @template T
     * @param callable(): T $compute
     * @param T $fallback
     * @return T
     */
    private function guard(callable $compute, mixed $fallback): mixed
    {
        try {
            return $compute();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /** Upcoming (grace-windowed) schedules, one query per request. */
    private function scheduleFor(int $discussionId): ?string
    {
        if ($this->schedules === null) {
            $this->schedules = [];
            $rows = Schedule::query()
                ->where('starts_at', '>', \Carbon\Carbon::now()->subHours(3))
                ->get(['discussion_id', 'starts_at']);
            foreach ($rows as $row) {
                $this->schedules[(int) $row->discussion_id] = $row->starts_at->toIso8601String();
            }
        }

        return $this->schedules[$discussionId] ?? null;
    }

    /** This discussion's room (live show or voice channel), from the once-
     *  per-request map of the whole tiny table. */
    private function roomFor(int $discussionId): ?Room
    {
        if ($this->rooms === null) {
            $this->rooms = [];
            foreach (Room::query()->get() as $room) {
                $this->rooms[(int) $room->discussion_id] = $room;
            }
        }

        return $this->rooms[$discussionId] ?? null;
    }
}

<?php

namespace LinkRobins\Chirp\Api;

use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Chirp\Recording;
use LinkRobins\Chirp\Schedule;
use LinkRobins\Chirp\Room;

/**
 * Chirp state on every discussion payload. Fail-closed per the contracts: a
 * throw in any getter degrades to false rather than 500ing the whole
 * discussion list.
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

    /** @var array<int, list<array{id: int, duration: int, recordedAt: string}>>|null */
    private ?array $recordings = null;

    /** @var array<int, string>|null discussion_id => ISO starts_at; null = not fetched */
    private ?array $schedules = null;

    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('chirpIsLive')
                ->get(function ($discussion) {
                    try {
                        return $this->roomFor((int) $discussion->id) !== null;
                    } catch (\Throwable) {
                        return false;
                    }
                }),

            Schema\Boolean::make('canChirpStart')
                ->get(function ($discussion, $context) {
                    try {
                        return $this->settings->get('linkrobins-chirp.connected') === '1'
                            && $context->getActor()->can('chirpStart', $discussion);
                    } catch (\Throwable) {
                        return false;
                    }
                }),

            Schema\Boolean::make('canChirpSpeak')
                ->get(function ($discussion, $context) {
                    try {
                        $actor = $context->getActor();

                        return !$actor->isGuest()
                            && ($actor->can('chirpSpeak', $discussion) || $actor->can('chirpStart', $discussion));
                    } catch (\Throwable) {
                        return false;
                    }
                }),
            // Live-room speaker policy trio — null/false everywhere except
            // THE live discussion. chirpSpeakEligible is the policy-aware
            // "may this actor pursue the mic right now" (in 'hand' mode it
            // means "may raise a hand"); the token endpoint re-enforces.
            Schema\Str::make('chirpSpeakPolicy')
                ->nullable()
                ->get(function ($discussion) {
                    try {
                        $room = $this->roomFor((int) $discussion->id);

                        return $room ? (string) $room->speak_policy : null;
                    } catch (\Throwable) {
                        return null;
                    }
                }),

            Schema\Str::make('chirpRoomMode')
                ->nullable()
                ->get(function ($discussion) {
                    try {
                        $room = $this->roomFor((int) $discussion->id);

                        return $room ? (string) $room->mode : null;
                    } catch (\Throwable) {
                        return null;
                    }
                }),

            Schema\Integer::make('chirpRoomHostId')
                ->nullable()
                ->get(function ($discussion) {
                    try {
                        $room = $this->roomFor((int) $discussion->id);

                        return $room ? (int) $room->user_id : null;
                    } catch (\Throwable) {
                        return null;
                    }
                }),

            Schema\Boolean::make('chirpSpeakEligible')
                ->get(function ($discussion, $context) {
                    try {
                        $room = $this->roomFor((int) $discussion->id);
                        if (!$room) {
                            return false;
                        }
                        $actor = $context->getActor();
                        if ($actor->isGuest()) {
                            return false;
                        }
                        if ($room->user_id === $actor->id || $actor->can('chirpStart', $discussion)) {
                            return true;
                        }
                        // Voice channels have no speaker policies — joining
                        // is speaking for anyone holding chirpSpeak.
                        if ($room->mode !== 'persistent' && $room->speak_policy === 'op') {
                            return $actor->id === (int) $discussion->user_id;
                        }

                        return $actor->can('chirpSpeak', $discussion);
                    } catch (\Throwable) {
                        return false;
                    }
                }),

            // The announced future room, if any — a stale one (host never
            // showed) ages out after a 3h grace instead of lingering forever.
            Schema\Str::make('chirpScheduledAt')
                ->nullable()
                ->get(function ($discussion) {
                    try {
                        return $this->scheduleFor((int) $discussion->id);
                    } catch (\Throwable) {
                        return null;
                    }
                }),

            Schema\Boolean::make('chirpCanDeleteRecordings')
                ->get(function ($discussion, $context) {
                    try {
                        return $context->getActor()->can('chirpDeleteRecording', $discussion);
                    } catch (\Throwable) {
                        return false;
                    }
                }),

            Schema\Arr::make('chirpRecordings')
                ->get(function ($discussion) {
                    try {
                        return $this->recordingsFor((int) $discussion->id);
                    } catch (\Throwable) {
                        return [];
                    }
                }),
        ];
    }

    /**
     * Delivered recordings per discussion — the front end renders them under
     * the FIRST post ("the discussion keeps the show"). Same memo shape as
     * liveId(): ONE indexed query per request, zero per row, so the
     * discussion index never goes N+1. Rows are (id, duration, timestamp)
     * only — a few thousand recordings is still a trivial read.
     */
    private function recordingsFor(int $discussionId): array
    {
        if ($this->recordings === null) {
            $this->recordings = [];
            $rows = Recording::query()
                ->where('status', 'delivered')
                ->orderBy('id')
                ->get(['id', 'discussion_id', 'duration_seconds', 'delivered_at']);
            foreach ($rows as $row) {
                $this->recordings[(int) $row->discussion_id][] = [
                    'id'         => (int) $row->id,
                    'duration'   => (int) $row->duration_seconds,
                    'recordedAt' => optional($row->delivered_at)->toIso8601String(),
                ];
            }
        }

        return $this->recordings[$discussionId] ?? [];
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

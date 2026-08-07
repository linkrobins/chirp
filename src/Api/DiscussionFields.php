<?php

namespace LinkRobins\Chirp\Api;

use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Chirp\Recording;
use LinkRobins\Chirp\Room;

/**
 * Chirp state on every discussion payload. Fail-closed per the contracts: a
 * throw in any getter degrades to false rather than 500ing the whole
 * discussion list.
 *
 * N+1 note: the channel has at most ONE live room forum-wide, so instead of a
 * per-row relationship read we resolve the live discussion id once per
 * request and compare ids — one 0-or-1-row query per request, zero per row.
 * The memo lives on this instance (container-resolved per request), never in
 * a static, per the persistent-runtime rule.
 */
class DiscussionFields
{
    /** @var Room|false|null null = not fetched yet; false = no live room */
    private Room|false|null $liveRoom = null;

    /** @var array<int, list<array{id: int, duration: int, recordedAt: string}>>|null */
    private ?array $recordings = null;

    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('chirpIsLive')
                ->get(function ($discussion) {
                    try {
                        return $this->liveId() === (int) $discussion->id;
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
                        $room = $this->liveRoom();

                        return $room && (int) $room->discussion_id === (int) $discussion->id ? (string) $room->speak_policy : null;
                    } catch (\Throwable) {
                        return null;
                    }
                }),

            Schema\Integer::make('chirpRoomHostId')
                ->nullable()
                ->get(function ($discussion) {
                    try {
                        $room = $this->liveRoom();

                        return $room && (int) $room->discussion_id === (int) $discussion->id ? (int) $room->user_id : null;
                    } catch (\Throwable) {
                        return null;
                    }
                }),

            Schema\Boolean::make('chirpSpeakEligible')
                ->get(function ($discussion, $context) {
                    try {
                        $room = $this->liveRoom();
                        if (!$room || (int) $room->discussion_id !== (int) $discussion->id) {
                            return false;
                        }
                        $actor = $context->getActor();
                        if ($actor->isGuest()) {
                            return false;
                        }
                        if ($room->user_id === $actor->id || $actor->can('chirpStart', $discussion)) {
                            return true;
                        }
                        if ($room->speak_policy === 'op') {
                            return $actor->id === (int) $discussion->user_id;
                        }

                        return $actor->can('chirpSpeak', $discussion);
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

    private function liveId(): int|false
    {
        $room = $this->liveRoom();

        return $room ? (int) $room->discussion_id : false;
    }

    /** The 0-or-1 live room row, fetched once per request (same memo rule). */
    private function liveRoom(): Room|false
    {
        if ($this->liveRoom === null) {
            $this->liveRoom = Room::query()->first() ?: false;
        }

        return $this->liveRoom;
    }
}

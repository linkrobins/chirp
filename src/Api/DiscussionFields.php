<?php

namespace LinkRobins\Chirp\Api;

use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;
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
    /** @var int|false|null null = not fetched yet; false = no live room */
    private int|false|null $liveDiscussionId = null;

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
        ];
    }

    private function liveId(): int|false
    {
        if ($this->liveDiscussionId === null) {
            $this->liveDiscussionId = (int) (Room::query()->value('discussion_id') ?? 0) ?: false;
        }

        return $this->liveDiscussionId;
    }
}

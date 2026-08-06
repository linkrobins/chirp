<?php

namespace LinkRobins\Chirp\Api;

use Flarum\Api\Schema;
use LinkRobins\Chirp\Room;

/**
 * Channel-wide live state on the forum payload (loads on every page, so this
 * must stay O(1) and fail closed): the id of the discussion currently live,
 * or 0 when the channel is quiet. Lets the UI tell someone their channel is
 * already live somewhere else BEFORE they click Go live.
 */
class ForumFields
{
    public function __invoke(): array
    {
        return [
            Schema\Integer::make('chirpLiveDiscussionId')
                ->get(function () {
                    try {
                        return (int) (Room::query()->value('discussion_id') ?? 0);
                    } catch (\Throwable) {
                        return 0;
                    }
                }),
        ];
    }
}

<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

/*
 * Deleting a recording is destructive — the forum holds the ONLY copy — so
 * it gets its own permission rather than riding chirpStart: moderators by
 * default, assignable to any group in the admin grid.
 */
return Migration::addPermissions([
    'discussion.chirpDeleteRecording' => Group::MODERATOR_ID,
]);

<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

/*
 * Default permission seed — without this no group holds the abilities at all.
 * Going live is a moderator capability by default (admins imply it); taking
 * the mic is open to members. Both are adjustable in the admin permission
 * grid; enforcement is backend-side in the controllers.
 */
return Migration::addPermissions([
    'discussion.chirpStart' => Group::MODERATOR_ID,
    'discussion.chirpSpeak' => Group::MEMBER_ID,
]);

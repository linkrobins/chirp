<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * One row = one live room. A room is bound to its discussion (unique — a
 * discussion can host at most one live room), and the StartRoom controller
 * enforces the channel-wide "one live at a time" rule on top. Rows are
 * deleted when the room ends, so the table holds 0 or 1 rows in normal
 * operation — every read is trivially cheap.
 */
return [
    'up' => function (Builder $schema) {
        if (!$schema->hasTable('chirp_rooms')) {
            $schema->create('chirp_rooms', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('discussion_id')->unique();
                $table->unsignedInteger('user_id')->nullable(); // who went live
                $table->dateTime('created_at');

                $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('chirp_rooms');
    },
];

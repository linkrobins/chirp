<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Scheduled rooms: "going live Friday 8pm". One upcoming schedule per
 * discussion (re-scheduling replaces it); the row is CONSUMED when the host
 * actually goes live, and a stale schedule (host never showed) simply ages
 * out of the payload — the table stays tiny.
 */
return [
    'up' => function (Builder $schema) {
        if (!$schema->hasTable('chirp_schedules')) {
            $schema->create('chirp_schedules', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('discussion_id')->unique();
                $table->unsignedInteger('user_id')->nullable();
                $table->dateTime('starts_at');
                $table->dateTime('created_at');
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('chirp_schedules');
    },
];

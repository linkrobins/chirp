<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Speaker policies. Every live room carries one:
 *   open — anyone with chirpSpeak takes the mic freely (the original model)
 *   hand — listeners raise a hand; the host approves before the mic unlocks
 *   op   — only the discussion's author (and hosts/moderators) may speak
 * chirp_hands holds raise-hand requests for the CURRENT room; rows cascade
 * away with the room. Approval is server state on purpose — the data-channel
 * pings are UX sugar, the token endpoint checks the row.
 */
return [
    'up' => function (Builder $schema) {
        if (!$schema->hasColumn('chirp_rooms', 'speak_policy')) {
            $schema->table('chirp_rooms', function (Blueprint $table) {
                $table->string('speak_policy', 10)->default('open');
            });
        }
        if (!$schema->hasTable('chirp_hands')) {
            $schema->create('chirp_hands', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('room_id');
                $table->unsignedInteger('user_id');
                $table->string('status', 10)->default('pending');
                $table->dateTime('created_at');

                $table->unique(['room_id', 'user_id']);
                $table->foreign('room_id')->references('id')->on('chirp_rooms')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('chirp_hands');
        $schema->table('chirp_rooms', function (Blueprint $table) {
            $table->dropColumn('speak_policy');
        });
    },
];

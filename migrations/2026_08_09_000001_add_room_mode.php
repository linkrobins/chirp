<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Room modes: 'live' (the original event shape — starts, happens, ends,
 * leaves a recording) and 'persistent' (a Discord-style voice channel — the
 * row stays until the host closes it, people drop in and out, nothing is
 * recorded). A persistent room OCCUPIES the channel like any other room:
 * one container, one room at a time.
 */
return [
    'up' => function (Builder $schema) {
        if (!$schema->hasColumn('chirp_rooms', 'mode')) {
            $schema->table('chirp_rooms', function (Blueprint $table) {
                $table->string('mode', 10)->default('live');
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->table('chirp_rooms', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    },
];

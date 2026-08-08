<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Multi-channel: which purchased channel a room runs on (the service-side
 * handle from the config exchange). NULL = single-key era row — attributed
 * to the first connected channel at read time, which keeps one-channel
 * installs byte-identical in behavior.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('chirp_rooms', function (Blueprint $table) {
            $table->string('channel', 100)->nullable();
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('chirp_rooms', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    },
];

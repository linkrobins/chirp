<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Recordings delivered to THIS forum — the forum is the system of record for
 * the audio (the hosted service holds nothing after delivery). A row is
 * created 'pending' when a recorded room goes live (capturing who started
 * it — the room row itself is deleted when the room ends, long before the
 * mixed file arrives), then filled in and flipped to 'delivered' when the
 * service posts the file. The file lives under storage/chirp-recordings/,
 * NOT public assets: playback goes through a visibility-checked endpoint so
 * a recording is exactly as private as its discussion.
 */
return [
    'up' => function (Builder $schema) {
        if (!$schema->hasTable('chirp_recordings')) {
            $schema->create('chirp_recordings', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('discussion_id')->index();
                $table->unsignedInteger('user_id')->nullable(); // who went live
                $table->string('status', 20)->default('pending');
                $table->string('path')->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('delivered_at')->nullable();

                $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('chirp_recordings');
    },
];

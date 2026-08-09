<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Two robustness fixes from the v1.1.3 code review.
 *
 * 1. `(discussion_id, status)` on chirp_recordings — DiscussionFields now
 *    looks recordings up one discussion at a time instead of reading the
 *    whole delivered set on every discussion-list response, and this is the
 *    index that makes that a point lookup.
 *
 * 2. Backfill the guard the 2026_08_11 channel migration shipped without:
 *    if that one already ran, this is a no-op; if a botched upgrade left the
 *    column missing, this adds it. Every other schema migration in the
 *    extension is re-runnable and that one wasn't.
 */
return [
    'up' => function (Builder $schema) {
        if (!$schema->hasColumn('chirp_rooms', 'channel')) {
            $schema->table('chirp_rooms', function (Blueprint $table) {
                $table->string('channel', 100)->nullable();
            });
        }

        if ($schema->hasIndex('chirp_recordings', ['discussion_id', 'status'])) {
            return;
        }

        $schema->table('chirp_recordings', function (Blueprint $table) {
            $table->index(['discussion_id', 'status']);
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('chirp_recordings', function (Blueprint $table) {
            $table->dropIndex(['discussion_id', 'status']);
        });
    },
];

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
/**
 * Whether a composite index already covers exactly these columns.
 *
 * `Schema\Builder::hasIndex()` is a Laravel 11 method and Flarum 1.8 ships
 * Laravel 8, where calling it is a fatal that aborts enabling the extension.
 * doctrine/dbal comes with 1.8 and answers the same question; a failure to
 * introspect is treated as "not there", because the worst case is then a
 * duplicate-index error on a re-run rather than a silently missing index.
 *
 * @param list<string> $columns
 */
function indexExists(Builder $schema, string $table, array $columns): bool
{
    try {
        $connection = $schema->getConnection();
        $manager = $connection->getDoctrineSchemaManager();

        foreach ($manager->listTableIndexes($connection->getTablePrefix() . $table) as $index) {
            if ($index->getColumns() === $columns) {
                return true;
            }
        }
    } catch (\Throwable) {
        return false;
    }

    return false;
}

return [
    'up' => function (Builder $schema) {
        if (!$schema->hasColumn('chirp_rooms', 'channel')) {
            $schema->table('chirp_rooms', function (Blueprint $table) {
                $table->string('channel', 100)->nullable();
            });
        }

        if (indexExists($schema, 'chirp_recordings', ['discussion_id', 'status'])) {
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

<?php

namespace LinkRobins\Chirp;

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An announced future room on a discussion. Consumed by going live; shown
 * (with a grace window for late hosts) until then.
 *
 * @property int $id
 * @property int $discussion_id
 * @property int|null $user_id
 * @property \Carbon\Carbon $starts_at
 * @property \Carbon\Carbon $created_at
 */
class Schedule extends AbstractModel
{
    protected $table = 'chirp_schedules';

    protected $fillable = ['discussion_id', 'user_id', 'starts_at', 'created_at'];

    protected $casts = ['starts_at' => 'datetime', 'created_at' => 'datetime'];

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }
}

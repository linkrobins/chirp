<?php

namespace LinkRobins\Chirp;

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A live-room recording delivered to this forum. Created 'pending' at
 * go-live time (that's when the starter is still known), completed at
 * delivery. `path` is a bare filename under storage/chirp-recordings/ —
 * never a client-supplied path.
 *
 * @property int $id
 * @property int $discussion_id
 * @property int|null $user_id
 * @property string $status
 * @property string|null $path
 * @property int|null $size_bytes
 * @property int|null $duration_seconds
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon|null $delivered_at
 */
class Recording extends AbstractModel
{
    protected $table = 'chirp_recordings';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'created_at'   => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }
}

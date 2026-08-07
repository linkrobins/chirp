<?php

namespace LinkRobins\Chirp;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A raise-hand request in the current live room (speak policy 'hand').
 * Statuses: pending → approved | declined. The TOKEN endpoint is the
 * enforcement point — an approved row is what unlocks the publish grant;
 * the LiveKit data-channel messages only make the UI instant.
 *
 * @property int $id
 * @property int $room_id
 * @property int $user_id
 * @property string $status
 * @property \Carbon\Carbon $created_at
 */
class Hand extends AbstractModel
{
    protected $table = 'chirp_hands';

    protected $fillable = ['room_id', 'user_id', 'status', 'created_at'];

    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace LinkRobins\Chirp;

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A live audio room, bound 1:1 to its discussion while live. The row exists
 * only while the room is live (ended rooms are deleted, the discussion is the
 * permanent artifact), so `Room::query()->exists()` answers "is the channel
 * busy" and the table never grows.
 *
 * @property int $id
 * @property int $discussion_id
 * @property int|null $user_id
 * @property \Carbon\Carbon $created_at
 * @property-read Discussion $discussion
 * @property-read User|null $user
 */
class Room extends AbstractModel
{
    protected $table = 'chirp_rooms';

    protected $fillable = ['discussion_id', 'user_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The LiveKit room name for a discussion — stable and collision-free. */
    public static function nameFor(int $discussionId): string
    {
        return 'd' . $discussionId;
    }
}

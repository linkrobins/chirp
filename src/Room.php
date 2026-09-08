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
 * @property string $speak_policy
 * @property string $mode
 * @property string|null $channel  Service-side handle of the channel this room runs on (NULL = single-key era)
 * @property-read Discussion $discussion
 * @property-read User|null $user
 */
class Room extends AbstractModel
{
    protected $table = 'chirp_rooms';

    protected $fillable = ['discussion_id', 'user_id', 'created_at', 'speak_policy', 'mode', 'channel'];

    protected $casts = ['created_at' => 'datetime'];

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // The LiveKit room name is no longer computed here. Every forum shares one
    // media server, so `d{id}` collided the moment two of them had a discussion
    // with the same id. The service derives the name from the channel a setup
    // token resolves to and returns it with each grant, which is what keeps one
    // forum from naming — and so joining — another's room.
}

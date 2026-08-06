<?php

/*
 * Chirp — live audio rooms for Flarum.
 *
 * The discussion IS the room: a channel owner attaches a live audio stage to
 * any discussion; listeners join in one click (unlimited), speakers get the
 * mic up to the channel's slot count, and the thread itself is the chat. When
 * the room ends, the discussion remains — nothing about the conversation is
 * ephemeral.
 *
 * The extension is the free half of a hosted service (same split as Birdseye):
 * the admin pastes a channel key from linkrobins.com → ExchangeKeyOnSave swaps
 * it (via /chirp/config) for the channel's signaling endpoint + API key/secret,
 * stored as server-only settings. Join tokens are minted LOCALLY (HS256 —
 * LiveKit-compatible JWTs), so the hosted service is never in the join path.
 */

use Flarum\Extend;
use Flarum\Settings\Event\Saved;
use LinkRobins\Chirp\Api\DiscussionFields;
use LinkRobins\Chirp\Api\ForumFields;
use LinkRobins\Chirp\Http\EndRoomController;
use LinkRobins\Chirp\Http\JoinTokenController;
use LinkRobins\Chirp\Http\StartRoomController;
use LinkRobins\Chirp\Listener\ExchangeKeyOnSave;
use LinkRobins\Chirp\Room;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    new Extend\Locales(__DIR__ . '/locale'),

    // Service URL is admin-overridable for testing; the channel key + resolved
    // credentials are server-only settings, never serialized to the forum.
    // 'connected' is the ONLY value the forum payload sees.
    (new Extend\Settings())
        ->default('linkrobins-chirp.service-url', 'https://linkrobins.com')
        ->default('linkrobins-chirp.connected', '0')
        ->serializeToForum('chirpConnected', 'linkrobins-chirp.connected', fn ($v) => $v === '1'),

    // Exchange the pasted channel key for connection config on save.
    (new Extend\Event())
        ->listen(Saved::class, ExchangeKeyOnSave::class),

    // Live-room state + gates on every discussion payload (fail-closed).
    (new Extend\ApiResource(\Flarum\Api\Resource\DiscussionResource::class))
        ->fields(DiscussionFields::class),

    // Channel-wide: which discussion (if any) is live right now.
    (new Extend\ApiResource(\Flarum\Api\Resource\ForumResource::class))
        ->fields(ForumFields::class),

    (new Extend\Model(\Flarum\Discussion\Discussion::class))
        ->hasOne('chirpRoom', Room::class, 'discussion_id'),

    // Room lifecycle + join tokens. Start/end are writes with explicit
    // permission gates; token is a POST (it allocates a speaker slot).
    (new Extend\Routes('api'))
        ->post('/chirp/rooms', 'chirp.rooms.start', StartRoomController::class)
        ->delete('/chirp/rooms/{id:\d+}', 'chirp.rooms.end', EndRoomController::class)
        ->post('/chirp/rooms/{id:\d+}/token', 'chirp.rooms.token', JoinTokenController::class),

    // Expected domain failures → clean 4xx with locale-keyed messages.
    (new Extend\ErrorHandling())
        ->status('chirp_not_configured', 409)
        ->status('chirp_channel_busy', 409)
        ->status('chirp_slots_full', 409),
];

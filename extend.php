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
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    new Extend\Locales(__DIR__ . '/locale'),


    // Service URL is admin-overridable for testing; the channel keys + resolved
    // credentials (the 'channels' JSON) are server-only settings, never
    // serialized to the forum. 'connected' is the ONLY value the forum
    // payload sees.
    (new Extend\Settings())
        ->default('linkrobins-chirp.service-url', 'https://linkrobins.com')
        ->default('linkrobins-chirp.connected', '0')
        // Accent colors: 'brand' (Chirp's palette) or 'forum' (adopt the
        // forum's Appearance colors). Cosmetic + public, safe to serialize.
        ->default('linkrobins-chirp.appearance', 'brand')
        // Record rooms when the channel's add-on allows it. '1' by default:
        // buying the add-on should Just Work without a second switch hunt.
        // Forum-wide default speaker policy for NEW rooms (host can flip live).
        ->default('linkrobins-chirp.default-speak-policy', 'open')
        ->serializeToForum('chirpConnected', 'linkrobins-chirp.connected', fn ($v) => $v === '1')
        ->serializeToForum('chirpAppearance', 'linkrobins-chirp.appearance'),

    // Exchange the pasted channel key for connection config on save.
    (new Extend\Event())
        ->listen(Saved::class, ExchangeKeyOnSave::class),

    // Live-room state + gates on every discussion payload (fail-closed).
    // 1.x has no resource API, so these are serializer attribute mutators;
    // the values are identical to the 2.x line by design.
    (new Extend\ApiSerializer(\Flarum\Api\Serializer\DiscussionSerializer::class))
        ->attributes(DiscussionFields::class),

    // Channel-wide: which discussion (if any) is live right now.
    (new Extend\ApiSerializer(\Flarum\Api\Serializer\ForumSerializer::class))
        ->attributes(ForumFields::class),

    (new Extend\Model(\Flarum\Discussion\Discussion::class))
        ->hasOne('chirpRoom', Room::class, 'discussion_id'),

    // Room lifecycle + join tokens. Start/end are writes with explicit
    // permission gates; token is a POST (it allocates a speaker slot).
    (new Extend\Routes('api'))
        ->post('/chirp/rooms', 'chirp.rooms.start', StartRoomController::class)
        ->delete('/chirp/rooms/{id:\d+}', 'chirp.rooms.end', EndRoomController::class)
        ->post('/chirp/rooms/{id:\d+}/token', 'chirp.rooms.token', JoinTokenController::class)
        // Speaker policies: the host flips the room's policy live; hands are
        // raised/resolved server-side (the token endpoint enforces), with
        // data-channel pings making the UI instant.
        ->post('/chirp/rooms/{id:\d+}/policy', 'chirp.rooms.policy', \LinkRobins\Chirp\Http\SetPolicyController::class)
        ->post('/chirp/rooms/{id:\d+}/stage', 'chirp.rooms.stage', \LinkRobins\Chirp\Http\ModerateStageController::class)
        ->post('/chirp/rooms/{id:\d+}/hand', 'chirp.rooms.hand', \LinkRobins\Chirp\Http\RaiseHandController::class)
        ->post('/chirp/rooms/{id:\d+}/hand/{userId:\d+}', 'chirp.rooms.hand-resolve', \LinkRobins\Chirp\Http\ResolveHandController::class)
        ->get('/chirp/rooms/{id:\d+}/hands', 'chirp.rooms.hands', \LinkRobins\Chirp\Http\ListHandsController::class)
        ->get('/chirp/channels', 'chirp.channels.list', \LinkRobins\Chirp\Http\ListChannelsController::class)
        ->post('/chirp/rooms/{id:\d+}/schedule', 'chirp.rooms.schedule', \LinkRobins\Chirp\Http\ScheduleRoomController::class)
        ->delete('/chirp/rooms/{id:\d+}/schedule', 'chirp.rooms.schedule-cancel', \LinkRobins\Chirp\Http\CancelScheduleController::class),


    // Followers hear about rooms opening (alert by default; users can add
    // email in their own preferences).
    // 1.x's type() takes the SUBJECT serializer as its second argument, which
    // 2.0 dropped. Both blueprints have a Discussion subject.
    (new Extend\Notification())
        ->type(
            \LinkRobins\Chirp\Notification\RoomStartedBlueprint::class,
            \Flarum\Api\Serializer\BasicDiscussionSerializer::class,
            ['alert']
        )
        ->type(
            \LinkRobins\Chirp\Notification\RoomScheduledBlueprint::class,
            \Flarum\Api\Serializer\BasicDiscussionSerializer::class,
            ['alert']
        ),

    // Expected domain failures → clean 4xx with locale-keyed messages.
    (new Extend\ErrorHandling())
        ->status('chirp_not_configured', 409)
        ->status('chirp_channel_busy', 409)
        ->status('chirp_channels_exhausted', 409)
        ->status('chirp_slots_full', 409)
        ->status('chirp_site_full', 409)
        ->status('chirp_speak_denied', 403),
];

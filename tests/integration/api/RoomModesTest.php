<?php

/*
 * This file is part of linkrobins/flarum-chirp.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Chirp\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Room modes: 'live' shows vs ADMIN-designated persistent voice channels,
 * stage moderation auth, and go-live notifications to followers. The LiveKit
 * API is unreachable in the harness — every server call is fail-soft by
 * design, so the DB rows and HTTP statuses are the observable truth.
 */
class RoomModesTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-subscriptions', 'linkrobins-chirp');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
                ['id' => 3, 'username' => 'follower', 'email' => 'follower@example.test', 'password' => '$2y$10$LO5oGKgGcHOAdSTPjPX6SuXpUdLpJRbaBWQRpJT8zx6EmSbY.pYCK', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                // User 2 is a moderator: holds chirpStart but is NOT an admin.
                ['user_id' => 2, 'group_id' => 4],
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'Show night', 'slug' => 'show-night', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1],
                ['id' => 2, 'title' => 'Hangout', 'slug' => 'hangout', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 2, 'comment_count' => 1],
                ['id' => 3, 'title' => 'Lounge', 'slug' => 'lounge', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 3, 'comment_count' => 1],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>hi</p></t>'],
                ['id' => 2, 'discussion_id' => 2, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>ho</p></t>'],
                ['id' => 3, 'discussion_id' => 3, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>he</p></t>'],
            ],
            'discussion_user' => [
                ['discussion_id' => 1, 'user_id' => 3, 'subscription' => 'follow'],
            ],
        ]);
    }

    private function configure(): void
    {
        $this->setting('linkrobins-chirp.connected', '1');
        $this->setting('linkrobins-chirp.endpoint', 'wss://chirp-x.linkrobins.com');
        $this->setting('linkrobins-chirp.api-key', 'LKtest');
        $this->setting('linkrobins-chirp.api-secret', 'ssssssssssssssssssssssssssssssssssssssss');
        $this->setting('linkrobins-chirp.speaker-slots', '5');
    }

    #[Test]
    public function starting_a_room_notifies_followers_but_not_the_starter(): void
    {
        $this->configure();

        $response = $this->send($this->request('POST', '/api/chirp/rooms', ['authenticatedAs' => 1, 'json' => ['discussionId' => 1]]));
        $this->assertEquals(200, $response->getStatusCode());

        $rows = $this->database()->table('notifications')->where('type', 'chirpRoomStarted')->get();
        $this->assertCount(1, $rows);
        $this->assertEquals(3, $rows[0]->user_id);
    }

    #[Test]
    public function designating_a_voice_channel_is_admin_only(): void
    {
        $this->configure();

        // A moderator with chirpStart cannot designate…
        $denied = $this->send($this->request('POST', '/api/chirp/rooms', ['authenticatedAs' => 2, 'json' => ['discussionId' => 2, 'mode' => 'persistent']]));
        $this->assertEquals(403, $denied->getStatusCode());

        // …an admin can.
        $ok = $this->send($this->request('POST', '/api/chirp/rooms', ['authenticatedAs' => 1, 'json' => ['discussionId' => 2, 'mode' => 'persistent']]));
        $this->assertEquals(200, $ok->getStatusCode());
        $this->assertEquals('persistent', $this->database()->table('chirp_rooms')->value('mode'));
        // Voice channels are never recorded — no pending attribution row.
        $this->assertEquals(0, $this->database()->table('chirp_recordings')->count());
    }

    #[Test]
    public function voice_channels_do_not_block_going_live_and_coexist(): void
    {
        $this->configure();
        $this->setting('linkrobins-chirp.recordings-available', '1');

        // Two designated channels coexist…
        foreach ([2, 3] as $id) {
            $r = $this->send($this->request('POST', '/api/chirp/rooms', ['authenticatedAs' => 1, 'json' => ['discussionId' => $id, 'mode' => 'persistent']]));
            $this->assertEquals(200, $r->getStatusCode());
        }
        // …and a live show still starts alongside them.
        $live = $this->send($this->request('POST', '/api/chirp/rooms', ['authenticatedAs' => 2, 'json' => ['discussionId' => 1]]));
        $this->assertEquals(200, $live->getStatusCode());
        $this->assertEquals(3, $this->database()->table('chirp_rooms')->count());

        // A discussion that IS a voice channel can't also go live.
        $busy = $this->send($this->request('POST', '/api/chirp/rooms', ['authenticatedAs' => 2, 'json' => ['discussionId' => 2]]));
        $this->assertEquals(409, $busy->getStatusCode());
    }

    #[Test]
    public function removing_a_voice_channel_is_admin_only_and_listing_works(): void
    {
        $this->configure();
        $this->database()->table('chirp_rooms')->insert(['id' => 1, 'discussion_id' => 2, 'user_id' => 1, 'created_at' => Carbon::now(), 'speak_policy' => 'open', 'mode' => 'persistent']);

        // The moderator who could end any live show cannot remove a channel…
        $denied = $this->send($this->request('DELETE', '/api/chirp/rooms/2', ['authenticatedAs' => 2]));
        $this->assertEquals(403, $denied->getStatusCode());

        // …and the admin list shows it until an admin removes it.
        $list = $this->send($this->request('GET', '/api/chirp/channels', ['authenticatedAs' => 1]));
        $this->assertEquals(200, $list->getStatusCode());
        $channels = json_decode((string) $list->getBody(), true)['channels'];
        $this->assertCount(1, $channels);
        $this->assertEquals(2, $channels[0]['discussionId']);

        $modList = $this->send($this->request('GET', '/api/chirp/channels', ['authenticatedAs' => 2]));
        $this->assertEquals(403, $modList->getStatusCode());

        $removed = $this->send($this->request('DELETE', '/api/chirp/rooms/2', ['authenticatedAs' => 1]));
        $this->assertEquals(204, $removed->getStatusCode());
        $this->assertEquals(0, $this->database()->table('chirp_rooms')->count());
    }

    #[Test]
    public function voice_channels_ignore_speaker_policies(): void
    {
        $this->configure();
        // Even with the most restrictive policy stored, a voice channel lets
        // any chirpSpeak holder pursue the mic: the policy 403 must NOT
        // fire — the harness's unreachable LiveKit then fail-closes the
        // SLOT check as 409, which is the "policy passed" signature.
        $this->database()->table('chirp_rooms')->insert(['id' => 1, 'discussion_id' => 2, 'user_id' => 1, 'created_at' => Carbon::now(), 'speak_policy' => 'op', 'mode' => 'persistent']);

        $response = $this->send($this->request('POST', '/api/chirp/rooms/2/token', ['authenticatedAs' => 3, 'json' => ['speak' => true]]));
        $this->assertEquals(409, $response->getStatusCode());

        // The same actor against the same policy on a LIVE room is denied
        // by the policy itself (403) — the channel really is the difference.
        $this->database()->table('chirp_rooms')->where('id', 1)->update(['mode' => 'live']);
        $denied = $this->send($this->request('POST', '/api/chirp/rooms/2/token', ['authenticatedAs' => 3, 'json' => ['speak' => true]]));
        $this->assertEquals(403, $denied->getStatusCode());
    }

    #[Test]
    public function mute_is_an_accepted_moderation_action(): void
    {
        $this->configure();
        $this->database()->table('chirp_rooms')->insert(['id' => 1, 'discussion_id' => 2, 'user_id' => 1, 'created_at' => Carbon::now(), 'speak_policy' => 'open', 'mode' => 'persistent']);

        // Fail-soft LiveKit: the action is accepted even when the room API
        // is unreachable.
        $ok = $this->send($this->request('POST', '/api/chirp/rooms/2/stage', ['authenticatedAs' => 1, 'json' => ['identity' => 'u3', 'action' => 'mute']]));
        $this->assertEquals(200, $ok->getStatusCode());

        $self = $this->send($this->request('POST', '/api/chirp/rooms/2/stage', ['authenticatedAs' => 1, 'json' => ['identity' => 'u1', 'action' => 'mute']]));
        $this->assertEquals(422, $self->getStatusCode());
    }

    #[Test]
    public function scheduling_is_gated_notifies_followers_and_is_consumed_by_going_live(): void
    {
        $this->configure();
        $when = Carbon::now()->addDay()->toIso8601String();

        // A plain member can't schedule…
        $denied = $this->send($this->request('POST', '/api/chirp/rooms/1/schedule', ['authenticatedAs' => 3, 'json' => ['startsAt' => $when]]));
        $this->assertEquals(403, $denied->getStatusCode());

        // …the past is not a plan…
        $past = $this->send($this->request('POST', '/api/chirp/rooms/1/schedule', ['authenticatedAs' => 2, 'json' => ['startsAt' => Carbon::now()->subHour()->toIso8601String()]]));
        $this->assertEquals(422, $past->getStatusCode());

        // …a moderator can, and the follower gets the heads-up.
        $ok = $this->send($this->request('POST', '/api/chirp/rooms/1/schedule', ['authenticatedAs' => 2, 'json' => ['startsAt' => $when]]));
        $this->assertEquals(200, $ok->getStatusCode());
        $this->assertEquals(1, $this->database()->table('chirp_schedules')->count());
        $this->assertEquals(1, $this->database()->table('notifications')->where('type', 'chirpRoomScheduled')->where('user_id', 3)->count());

        // The payload carries the countdown source.
        $show = $this->send($this->request('GET', '/api/discussions/1', ['authenticatedAs' => 3]));
        $attrs = json_decode((string) $show->getBody(), true)['data']['attributes'];
        $this->assertNotNull($attrs['chirpScheduledAt']);

        // Going live consumes the schedule.
        $live = $this->send($this->request('POST', '/api/chirp/rooms', ['authenticatedAs' => 2, 'json' => ['discussionId' => 1]]));
        $this->assertEquals(200, $live->getStatusCode());
        $this->assertEquals(0, $this->database()->table('chirp_schedules')->count());
    }

    #[Test]
    public function cancelling_a_schedule_is_scheduler_or_moderation(): void
    {
        $this->configure();
        $this->database()->table('chirp_schedules')->insert(['discussion_id' => 1, 'user_id' => 2, 'starts_at' => Carbon::now()->addDay(), 'created_at' => Carbon::now()]);

        $denied = $this->send($this->request('DELETE', '/api/chirp/rooms/1/schedule', ['authenticatedAs' => 3]));
        $this->assertEquals(403, $denied->getStatusCode());

        $ok = $this->send($this->request('DELETE', '/api/chirp/rooms/1/schedule', ['authenticatedAs' => 2]));
        $this->assertEquals(204, $ok->getStatusCode());
        $this->assertEquals(0, $this->database()->table('chirp_schedules')->count());
    }

    #[Test]
    public function stage_moderation_is_host_only_and_validates(): void
    {
        $this->configure();
        $this->database()->table('chirp_rooms')->insert(['id' => 1, 'discussion_id' => 1, 'user_id' => 1, 'created_at' => Carbon::now(), 'speak_policy' => 'open', 'mode' => 'live']);

        $denied = $this->send($this->request('POST', '/api/chirp/rooms/1/stage', ['authenticatedAs' => 3, 'json' => ['identity' => 'u2', 'action' => 'kick']]));
        $this->assertEquals(403, $denied->getStatusCode());

        $self = $this->send($this->request('POST', '/api/chirp/rooms/1/stage', ['authenticatedAs' => 1, 'json' => ['identity' => 'u1', 'action' => 'kick']]));
        $this->assertEquals(422, $self->getStatusCode());

        $bad = $this->send($this->request('POST', '/api/chirp/rooms/1/stage', ['authenticatedAs' => 1, 'json' => ['identity' => 'g12ab', 'action' => 'kick']]));
        $this->assertEquals(422, $bad->getStatusCode());

        $ok = $this->send($this->request('POST', '/api/chirp/rooms/1/stage', ['authenticatedAs' => 1, 'json' => ['identity' => 'u2', 'action' => 'unstage']]));
        $this->assertEquals(200, $ok->getStatusCode());
    }

    #[Test]
    public function unstaging_declines_an_approved_hand(): void
    {
        $this->configure();
        $this->database()->table('chirp_rooms')->insert(['id' => 1, 'discussion_id' => 1, 'user_id' => 1, 'created_at' => Carbon::now(), 'speak_policy' => 'hand', 'mode' => 'live']);
        $this->database()->table('chirp_hands')->insert(['room_id' => 1, 'user_id' => 2, 'status' => 'approved', 'created_at' => Carbon::now()]);

        $this->send($this->request('POST', '/api/chirp/rooms/1/stage', ['authenticatedAs' => 1, 'json' => ['identity' => 'u2', 'action' => 'unstage']]));

        $this->assertEquals('declined', $this->database()->table('chirp_hands')->where('user_id', 2)->value('status'));
    }
}

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
 * Room modes (live vs persistent voice channel), stage moderation auth, and
 * go-live notifications to followers. The LiveKit API is unreachable in the
 * harness — every server call is fail-soft by design, so the DB rows and
 * HTTP statuses are the observable truth.
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
            'discussions' => [
                ['id' => 1, 'title' => 'Show night', 'slug' => 'show-night', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1],
                ['id' => 2, 'title' => 'Other', 'slug' => 'other', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 2, 'comment_count' => 1],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>hi</p></t>'],
                ['id' => 2, 'discussion_id' => 2, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>ho</p></t>'],
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
    public function a_persistent_room_occupies_the_channel_and_skips_recording(): void
    {
        $this->configure();
        $this->setting('linkrobins-chirp.recordings-available', '1');

        $open = $this->send($this->request('POST', '/api/chirp/rooms', ['authenticatedAs' => 1, 'json' => ['discussionId' => 1, 'mode' => 'persistent']]));
        $this->assertEquals(200, $open->getStatusCode());

        $room = $this->database()->table('chirp_rooms')->first();
        $this->assertEquals('persistent', $room->mode);
        // Voice channels are never recorded — no pending attribution row.
        $this->assertEquals(0, $this->database()->table('chirp_recordings')->count());

        // Even with the LiveKit API unreachable (a dead LIVE room would be
        // reconciled away here), a persistent room stays busy until closed.
        $busy = $this->send($this->request('POST', '/api/chirp/rooms', ['authenticatedAs' => 1, 'json' => ['discussionId' => 2]]));
        $this->assertEquals(409, $busy->getStatusCode());
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

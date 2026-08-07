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
 * Speaker policies at the ONLY door that matters — the token endpoint — plus
 * the policy/hand management endpoints' auth. NB: the LiveKit room API is
 * unreachable in the harness, so a mic request that PASSES policy fails
 * CLOSED at the slot count with 409 — the 403/409 split is exactly what
 * distinguishes "policy refused you" from "policy passed".
 */
class SpeakPolicyTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-chirp');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2 — the discussion AUTHOR (OP)
                ['id' => 3, 'username' => 'listener', 'email' => 'listener@example.test', 'password' => '$2y$10$LO5oGKgGcHOAdSTPjPX6SuXpUdLpJRbaBWQRpJT8zx6EmSbY.pYCK', 'is_email_confirmed' => 1],
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'Show night', 'slug' => 'show-night', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>hi</p></t>'],
            ],
            'chirp_rooms' => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => 1, 'created_at' => Carbon::now(), 'speak_policy' => 'open'],
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

    private function setPolicy(string $policy): void
    {
        $this->database()->table('chirp_rooms')->where('id', 1)->update(['speak_policy' => $policy]);
    }

    private function mic(int $userId)
    {
        return $this->send($this->request('POST', '/api/chirp/rooms/1/token', ['authenticatedAs' => $userId, 'json' => ['speak' => true]]));
    }

    #[Test]
    public function op_policy_refuses_a_non_author(): void
    {
        $this->configure();
        $this->setPolicy('op');

        $this->assertEquals(403, $this->mic(3)->getStatusCode());
    }

    #[Test]
    public function op_policy_passes_the_author_through_to_the_slot_check(): void
    {
        $this->configure();
        $this->setPolicy('op');

        // 409 = slots (fail-closed, no live room API in the harness) — the
        // policy gate said yes.
        $this->assertEquals(409, $this->mic(2)->getStatusCode());
    }

    #[Test]
    public function hand_policy_refuses_an_unapproved_hand(): void
    {
        $this->configure();
        $this->setPolicy('hand');

        $this->assertEquals(403, $this->mic(3)->getStatusCode());
    }

    #[Test]
    public function an_approved_hand_unlocks_the_mic(): void
    {
        $this->configure();
        $this->setPolicy('hand');

        $raise = $this->send($this->request('POST', '/api/chirp/rooms/1/hand', ['authenticatedAs' => 3]));
        $this->assertEquals(200, $raise->getStatusCode());

        $resolve = $this->send($this->request('POST', '/api/chirp/rooms/1/hand/3', ['authenticatedAs' => 1, 'json' => ['action' => 'approve']]));
        $this->assertEquals(200, $resolve->getStatusCode());

        $this->assertEquals(409, $this->mic(3)->getStatusCode()); // policy passed → slot check
    }

    #[Test]
    public function a_declined_hand_stays_locked_and_can_reraise(): void
    {
        $this->configure();
        $this->setPolicy('hand');

        $this->send($this->request('POST', '/api/chirp/rooms/1/hand', ['authenticatedAs' => 3]));
        $this->send($this->request('POST', '/api/chirp/rooms/1/hand/3', ['authenticatedAs' => 1, 'json' => ['action' => 'decline']]));
        $this->assertEquals(403, $this->mic(3)->getStatusCode());

        $again = $this->send($this->request('POST', '/api/chirp/rooms/1/hand', ['authenticatedAs' => 3]));
        $this->assertEquals(200, $again->getStatusCode());
    }

    #[Test]
    public function raising_a_hand_needs_hand_policy(): void
    {
        $this->configure();

        $response = $this->send($this->request('POST', '/api/chirp/rooms/1/hand', ['authenticatedAs' => 3]));
        $this->assertEquals(409, $response->getStatusCode());
    }

    #[Test]
    public function only_the_host_flips_the_policy(): void
    {
        $this->configure();

        $denied = $this->send($this->request('POST', '/api/chirp/rooms/1/policy', ['authenticatedAs' => 3, 'json' => ['policy' => 'hand']]));
        $this->assertEquals(403, $denied->getStatusCode());

        $ok = $this->send($this->request('POST', '/api/chirp/rooms/1/policy', ['authenticatedAs' => 1, 'json' => ['policy' => 'hand']]));
        $this->assertEquals(200, $ok->getStatusCode());

        $bad = $this->send($this->request('POST', '/api/chirp/rooms/1/policy', ['authenticatedAs' => 1, 'json' => ['policy' => 'chaos']]));
        $this->assertEquals(422, $bad->getStatusCode());
    }

    #[Test]
    public function the_hands_list_is_host_only(): void
    {
        $this->configure();
        $this->setPolicy('hand');

        $denied = $this->send($this->request('GET', '/api/chirp/rooms/1/hands', ['authenticatedAs' => 3]));
        $this->assertEquals(403, $denied->getStatusCode());

        $ok = $this->send($this->request('GET', '/api/chirp/rooms/1/hands', ['authenticatedAs' => 1]));
        $this->assertEquals(200, $ok->getStatusCode());
    }
}

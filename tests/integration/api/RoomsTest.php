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

class RoomsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-chirp');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'Show night', 'slug' => 'show-night', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>hi</p></t>'],
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
    public function a_guest_cannot_go_live(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chirp/rooms', ['json' => ['discussionId' => 1]])
        );

        // 400: a sessionless POST is stopped by the CSRF middleware before it
        // even reaches the controller (the SPA sends the token; raw guests
        // can't). Either way: denied.
        $this->assertContains($response->getStatusCode(), [400, 401]);
    }

    #[Test]
    public function a_member_without_the_permission_cannot_go_live(): void
    {
        $this->configure();

        $response = $this->send(
            $this->request('POST', '/api/chirp/rooms', [
                'authenticatedAs' => 2,
                'json'            => ['discussionId' => 1],
            ])
        );

        $this->assertEquals(403, $response->getStatusCode());
    }

    #[Test]
    public function going_live_without_a_channel_key_is_a_clean_conflict(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chirp/rooms', [
                'authenticatedAs' => 1,
                'json'            => ['discussionId' => 1],
            ])
        );

        $this->assertEquals(409, $response->getStatusCode());
        $this->assertStringContainsString('chirp_not_configured', (string) $response->getBody());
    }

    #[Test]
    public function an_admin_goes_live_and_gets_a_publish_token(): void
    {
        $this->configure();

        $response = $this->send(
            $this->request('POST', '/api/chirp/rooms', [
                'authenticatedAs' => 1,
                'json'            => ['discussionId' => 1],
            ])
        );

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('wss://chirp-x.linkrobins.com', $body['endpoint']);

        // The token is a signed LiveKit JWT for room d1 with the publish grant.
        $payload = json_decode(base64_decode(strtr(explode('.', $body['token'])[1], '-_', '+/')), true);
        $this->assertSame('d1', $payload['video']['room']);
        $this->assertTrue($payload['video']['canPublish']);

        // The room row exists — the channel is now busy.
        $this->assertEquals(1, $this->database()->table('chirp_rooms')->count());
    }

    #[Test]
    public function the_channel_only_holds_one_live_room(): void
    {
        $this->configure();

        $first = $this->send($this->request('POST', '/api/chirp/rooms', [
            'authenticatedAs' => 1,
            'json'            => ['discussionId' => 1],
        ]));
        $this->assertEquals(200, $first->getStatusCode());

        $second = $this->send($this->request('POST', '/api/chirp/rooms', [
            'authenticatedAs' => 1,
            'json'            => ['discussionId' => 1],
        ]));

        $this->assertEquals(409, $second->getStatusCode());
        $this->assertStringContainsString('chirp_channel_busy', (string) $second->getBody());
    }

    #[Test]
    public function joining_a_discussion_with_no_live_room_is_a_404(): void
    {
        $this->configure();

        $response = $this->send(
            $this->request('POST', '/api/chirp/rooms/1/token', ['authenticatedAs' => 2])
        );

        $this->assertEquals(404, $response->getStatusCode());
    }

    #[Test]
    public function a_listener_token_never_carries_the_publish_grant(): void
    {
        $this->configure();

        $this->send($this->request('POST', '/api/chirp/rooms', [
            'authenticatedAs' => 1,
            'json'            => ['discussionId' => 1],
        ]));

        // A plain member listening (no speak request, no chirpStart) — the
        // guest path is identical controller-side (guests just carry the SPA's
        // CSRF token, which this raw harness can't).
        $response = $this->send($this->request('POST', '/api/chirp/rooms/1/token', ['authenticatedAs' => 2]));

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertFalse($body['canPublish']);

        $payload = json_decode(base64_decode(strtr(explode('.', $body['token'])[1], '-_', '+/')), true);
        $this->assertFalse($payload['video']['canPublish']);
        $this->assertTrue($payload['video']['canSubscribe']);
    }
}

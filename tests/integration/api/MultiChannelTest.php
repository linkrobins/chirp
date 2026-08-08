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
 * Multi-channel: a forum runs SEVERAL purchased channels at once — each
 * powers one designated voice channel plus one live broadcast at a time.
 * The LiveKit API is unreachable in the harness — every server call is
 * fail-soft/fail-closed by design, so DB rows (incl. the room→channel
 * binding) and HTTP statuses are the observable truth.
 */
class MultiChannelTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-subscriptions', 'linkrobins-chirp');

        $this->prepareDatabase([
            'users' => [$this->normalUser()],
            'group_user' => [
                ['user_id' => 2, 'group_id' => 4], // moderator: chirpStart, not admin
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'Show night', 'slug' => 'show-night', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1],
                ['id' => 2, 'title' => 'Hangout', 'slug' => 'hangout', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 2, 'comment_count' => 1],
                ['id' => 3, 'title' => 'Lounge', 'slug' => 'lounge', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 3, 'comment_count' => 1],
                ['id' => 4, 'title' => 'Open mic', 'slug' => 'open-mic', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 4, 'comment_count' => 1],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>a</p></t>'],
                ['id' => 2, 'discussion_id' => 2, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>b</p></t>'],
                ['id' => 3, 'discussion_id' => 3, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>c</p></t>'],
                ['id' => 4, 'discussion_id' => 4, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>d</p></t>'],
            ],
        ]);
    }

    /** Two connected channels via the multi-channel JSON setting. */
    private function configureTwo(): void
    {
        $this->setting('linkrobins-chirp.connected', '1');
        $this->setting('linkrobins-chirp.channels', json_encode([
            ['key' => 'k1', 'handle' => 'ch-one', 'endpoint' => 'wss://chirp-a.linkrobins.com', 'api_key' => 'LKa', 'api_secret' => str_repeat('a', 40), 'speaker_slots' => 5, 'recordings' => false, 'connected' => true],
            ['key' => 'k2', 'handle' => 'ch-two', 'endpoint' => 'wss://chirp-b.linkrobins.com', 'api_key' => 'LKb', 'api_secret' => str_repeat('b', 40), 'speaker_slots' => 5, 'recordings' => false, 'connected' => true],
        ]));
    }

    #[Test]
    public function each_channel_powers_one_voice_channel(): void
    {
        $this->configureTwo();

        // Two channels → two designations, each bound to its own channel…
        foreach ([2, 3] as $id) {
            $r = $this->send($this->request('POST', '/api/chirp/rooms', ['authenticatedAs' => 1, 'json' => ['discussionId' => $id, 'mode' => 'persistent']]));
            $this->assertEquals(200, $r->getStatusCode());
        }
        $handles = $this->database()->table('chirp_rooms')->orderBy('id')->pluck('channel')->all();
        $this->assertEquals(['ch-one', 'ch-two'], $handles);

        // …and the third is the "add another channel" moment.
        $third = $this->send($this->request('POST', '/api/chirp/rooms', ['authenticatedAs' => 1, 'json' => ['discussionId' => 4, 'mode' => 'persistent']]));
        $this->assertEquals(409, $third->getStatusCode());
        $this->assertEquals('chirp_channels_exhausted', json_decode((string) $third->getBody(), true)['errors'][0]['code']);

        // The admin list reports the occupancy.
        $list = $this->send($this->request('GET', '/api/chirp/channels', ['authenticatedAs' => 1]));
        $data = json_decode((string) $list->getBody(), true);
        $this->assertEquals(['used' => 2, 'total' => 2], $data['slots']);
        $this->assertCount(2, $data['keys']);
    }

    #[Test]
    public function two_channels_run_two_live_broadcasts_but_not_three(): void
    {
        $this->configureTwo();

        foreach ([1, 2] as $id) {
            $r = $this->send($this->request('POST', '/api/chirp/rooms', ['authenticatedAs' => 2, 'json' => ['discussionId' => $id]]));
            $this->assertEquals(200, $r->getStatusCode());
        }
        $handles = $this->database()->table('chirp_rooms')->where('mode', 'live')->orderBy('id')->pluck('channel')->all();
        $this->assertEquals(['ch-one', 'ch-two'], $handles);

        // Both live slots taken (the unreachable liveness probe stays
        // fail-closed) → 409 busy, not a third room.
        $third = $this->send($this->request('POST', '/api/chirp/rooms', ['authenticatedAs' => 2, 'json' => ['discussionId' => 3]]));
        $this->assertEquals(409, $third->getStatusCode());
        $this->assertEquals(2, $this->database()->table('chirp_rooms')->count());
    }

    #[Test]
    public function join_token_and_endpoint_come_from_the_rooms_own_channel(): void
    {
        $this->configureTwo();
        $this->database()->table('chirp_rooms')->insert([
            ['id' => 1, 'discussion_id' => 1, 'user_id' => 2, 'created_at' => Carbon::now(), 'speak_policy' => 'open', 'mode' => 'live', 'channel' => 'ch-two'],
        ]);

        $res = $this->send($this->request('POST', '/api/chirp/rooms/1/token', ['authenticatedAs' => 2, 'json' => []]));
        $this->assertEquals(200, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);

        // Listener token: minted with ch-two's key/secret, pointed at
        // ch-two's signaling endpoint.
        $this->assertEquals('wss://chirp-b.linkrobins.com', $body['endpoint']);
        [$header, $claims, $sig] = explode('.', $body['token']);
        $decoded = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);
        $this->assertEquals('LKb', $decoded['iss']);

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $header . '.' . $claims, str_repeat('b', 40), true)), '+/', '-_'), '=');
        $this->assertEquals($expected, $sig);
    }

    #[Test]
    public function legacy_null_channel_rooms_bind_to_the_first_connected_channel(): void
    {
        $this->configureTwo();
        // A row from the single-key era: no channel handle.
        $this->database()->table('chirp_rooms')->insert([
            ['id' => 1, 'discussion_id' => 1, 'user_id' => 2, 'created_at' => Carbon::now(), 'speak_policy' => 'open', 'mode' => 'live', 'channel' => null],
        ]);

        $res = $this->send($this->request('POST', '/api/chirp/rooms/1/token', ['authenticatedAs' => 2, 'json' => []]));
        $this->assertEquals(200, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);
        $this->assertEquals('wss://chirp-a.linkrobins.com', $body['endpoint']);

        // …and it occupies the first channel's live slot: the next live
        // room lands on ch-two.
        $live = $this->send($this->request('POST', '/api/chirp/rooms', ['authenticatedAs' => 2, 'json' => ['discussionId' => 2]]));
        $this->assertEquals(200, $live->getStatusCode());
        $this->assertEquals('ch-two', $this->database()->table('chirp_rooms')->where('discussion_id', 2)->value('channel'));
    }

    #[Test]
    public function saving_channel_keys_writes_the_channels_json_and_clears_legacy_settings(): void
    {
        // Legacy flat settings present (v1.0 install)…
        $this->setting('linkrobins-chirp.connected', '1');
        $this->setting('linkrobins-chirp.endpoint', 'wss://chirp-x.linkrobins.com');
        $this->setting('linkrobins-chirp.api-key', 'LKold');
        $this->setting('linkrobins-chirp.api-secret', str_repeat('x', 40));

        // …the admin saves two keys through the new UI. The exchange can't
        // reach the service in the harness, so both entries land
        // disconnected — the observable contract is the JSON shape + the
        // legacy cleanup, and that a failed exchange never 500s the save.
        $res = $this->send($this->request('POST', '/api/settings', [
            'authenticatedAs' => 1,
            'json'            => ['linkrobins-chirp.channel-keys' => json_encode(['key-one', 'key-two'])],
        ]));
        $this->assertEquals(204, $res->getStatusCode());

        $stored = json_decode((string) $this->database()->table('settings')->where('key', 'linkrobins-chirp.channels')->value('value'), true);
        $this->assertCount(2, $stored);
        $this->assertEquals(['key-one', 'key-two'], array_column($stored, 'key'));
        $this->assertEquals([false, false], array_column($stored, 'connected'));

        $this->assertEquals('0', $this->database()->table('settings')->where('key', 'linkrobins-chirp.connected')->value('value'));
        $this->assertNull($this->database()->table('settings')->where('key', 'linkrobins-chirp.api-secret')->value('value'));
        $this->assertNull($this->database()->table('settings')->where('key', 'linkrobins-chirp.endpoint')->value('value'));
    }
}

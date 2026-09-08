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
use LinkRobins\Chirp\Tests\integration\ConfiguresChirp;
use PHPUnit\Framework\Attributes\Test;

/**
 * Multi-channel: a forum runs SEVERAL purchased channels at once — each
 * powers one designated voice channel plus one live broadcast at a time.
 * The LiveKit API is unreachable in the harness and the service is stubbed, so
 * DB rows (incl. the room→channel binding), HTTP statuses and the room names the
 * stub was asked for are the observable truth.
 */
class MultiChannelTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use ConfiguresChirp;

    protected function allowedRepeatedQueries(): array
    {
        return [
            // Saving channel keys clears the pre-multi-channel legacy
            // settings, one repository delete per key. The set is fixed and
            // small (it cannot grow with data), and the settings repository
            // only exposes per-key deletion, so the repetition is bounded
            // and deliberate rather than an N+1. Matched on the verb alone
            // because the table name varies with both the identifier quoting
            // (backticks vs double quotes) and the configured table prefix;
            // the only deletes this endpoint issues are these.
            'delete from',
        ];
    }

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
        $this->connectChannels(['ch-one', 'ch-two']);
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

        // Every channel shares one media server now, so the endpoint no longer
        // distinguishes them — the ROOM does, and it is derived from the
        // channel the room is bound to.
        $this->assertEquals('wss://chirp.linkrobins.test', $body['endpoint']);
        $minted = $this->app()->getContainer()->make(\LinkRobins\Chirp\ChirpClient::class)->minted;
        $this->assertEquals('ch-two-d1', end($minted)['room']);
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
        $this->assertEquals('wss://chirp.linkrobins.test', $body['endpoint']);
        $minted = $this->app()->getContainer()->make(\LinkRobins\Chirp\ChirpClient::class)->minted;
        $this->assertEquals('ch-one-d1', end($minted)['room']);

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

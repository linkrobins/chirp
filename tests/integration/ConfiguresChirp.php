<?php

namespace LinkRobins\Chirp\Tests\integration;

use Flarum\Extend;
use LinkRobins\Chirp\Tests\integration\Fake\FakeServiceProvider;

/**
 * Connects one or more channels the way a real install is connected: through
 * the channels JSON, each carrying the setup token it authenticates to the
 * service with.
 *
 * The old flat settings (endpoint / api-key / api-secret) are deliberately not
 * used. They described a dedicated media server per channel and carried a
 * signing secret, and neither exists now: the service mints every grant, so the
 * stub client is bound here too.
 */
trait ConfiguresChirp
{
    /** @param string[] $handles */
    protected function connectChannels(array $handles = ['ch-one']): void
    {
        $this->extend((new Extend\ServiceProvider())->register(FakeServiceProvider::class));

        $channels = [];
        foreach ($handles as $i => $handle) {
            $channels[] = [
                'key'           => 'k' . ($i + 1),
                'handle'        => $handle,
                'endpoint'      => 'wss://chirp.linkrobins.test',
                'setup_token'   => 'k' . ($i + 1),
                'speaker_slots' => 5,
                'recordings'    => false,
                'connected'     => true,
            ];
        }

        $this->setting('linkrobins-chirp.connected', '1');
        $this->setting('linkrobins-chirp.channels', json_encode($channels));
    }
}

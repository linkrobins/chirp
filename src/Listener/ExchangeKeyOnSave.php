<?php

namespace LinkRobins\Chirp\Listener;

use Flarum\Settings\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Chirp\ChirpClient;

/**
 * When the admin saves the Chirp channel key, exchange it for connection
 * config and store it as server-only settings. Fail-soft: a bad key just
 * leaves 'connected' = '0' (the admin banner surfaces that); it never 500s
 * the settings save.
 *
 * Status flags are '1'/'0' STRINGS: settings values round-trip through the DB
 * as strings (a PHP false becomes "0", which is truthy in JS) — the admin
 * banner compares === '1'. The api-secret is a server-only setting and is
 * NEVER serialized to any frontend payload (nothing serializeToForum's it;
 * admin pages only see the keys explicitly registered in the settings UI).
 */
class ExchangeKeyOnSave
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ChirpClient $client,
    ) {
    }

    public function handle(Saved $event): void
    {
        // Only react when THIS save touched the channel key.
        if (!array_key_exists('linkrobins-chirp.channel-key', $event->settings)) {
            return;
        }

        $token = trim((string) $event->settings['linkrobins-chirp.channel-key']);

        // Cleared → disconnect.
        if ($token === '') {
            $this->settings->set('linkrobins-chirp.connected', '0');
            $this->settings->delete('linkrobins-chirp.endpoint');
            $this->settings->delete('linkrobins-chirp.api-key');
            $this->settings->delete('linkrobins-chirp.api-secret');
            $this->settings->delete('linkrobins-chirp.speaker-slots');
            $this->settings->delete('linkrobins-chirp.recordings-available');
            return;
        }

        $config = $this->client->fetchConfig($token);
        if (!$config) {
            $this->settings->set('linkrobins-chirp.connected', '0');
            return;
        }

        $this->settings->set('linkrobins-chirp.endpoint', $config['endpoint']);
        $this->settings->set('linkrobins-chirp.api-key', $config['api_key']);
        $this->settings->set('linkrobins-chirp.api-secret', $config['api_secret']);
        $this->settings->set('linkrobins-chirp.speaker-slots', (string) $config['speaker_slots']);
        // Whether the channel PAYS for recordings; the admin's record-rooms
        // toggle only takes effect when this is on. Refreshed by re-saving
        // the key (the dashboard says so next to the add-on toggle).
        $this->settings->set('linkrobins-chirp.recordings-available', $config['recordings'] ? '1' : '0');
        $this->settings->set('linkrobins-chirp.connected', '1');
    }
}

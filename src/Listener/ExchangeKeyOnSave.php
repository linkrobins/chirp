<?php

namespace LinkRobins\Chirp\Listener;

use Flarum\Settings\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Chirp\Channels;
use LinkRobins\Chirp\ChirpClient;

/**
 * When the admin saves channel keys, exchange each for connection config and
 * store the lot as ONE server-only 'channels' JSON setting. Fail-soft: a bad
 * key marks that entry disconnected (the admin banner surfaces it) and a
 * TRANSIENT exchange failure keeps the entry's previous config, so a service
 * blip during an unrelated settings save can't disconnect a working channel.
 * It never 500s the settings save.
 *
 * Two triggers, new and legacy:
 *  - 'linkrobins-chirp.channel-keys' — JSON array of keys (multi-channel
 *    admin UI, one entry per purchased channel).
 *  - 'linkrobins-chirp.channel-key'  — the single-key setting from v1.0;
 *    still honored so an old admin UI (or a bench) keeps working. It is
 *    folded into the same channels JSON as a one-element list.
 *
 * Status flags are '1'/'0' STRINGS: settings values round-trip through the DB
 * as strings (a PHP false becomes "0", which is truthy in JS) — the admin
 * banner compares === '1'. Keys/secrets live only in server-only settings and
 * are NEVER serialized to any frontend payload.
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
        $multi  = array_key_exists('linkrobins-chirp.channel-keys', $event->settings);
        $single = array_key_exists('linkrobins-chirp.channel-key', $event->settings);

        if (!$multi && !$single) {
            return;
        }

        if ($multi) {
            $decoded = json_decode((string) $event->settings['linkrobins-chirp.channel-keys'], true);
            $keys    = is_array($decoded) ? $decoded : [];
        } else {
            $keys = [(string) $event->settings['linkrobins-chirp.channel-key']];
        }

        $keys = array_values(array_unique(array_filter(array_map(
            fn ($k) => is_string($k) ? trim($k) : '',
            $keys
        ))));

        // All keys cleared → disconnect everything.
        if ($keys === []) {
            $this->settings->set('linkrobins-chirp.connected', '0');
            $this->settings->delete(Channels::SETTING);
            $this->deleteLegacyFlatSettings();
            return;
        }

        // Previous entries by key, so a transient exchange failure keeps a
        // working channel's config instead of dropping it.
        $previous = [];
        foreach (json_decode((string) $this->settings->get(Channels::SETTING, '[]'), true) ?: [] as $entry) {
            if (is_array($entry) && !empty($entry['key'])) {
                $previous[(string) $entry['key']] = $entry;
            }
        }

        $channels     = [];
        $anyConnected = false;

        foreach ($keys as $key) {
            $config = $this->client->fetchConfig($key);

            if ($config) {
                $channels[] = ['key' => $key, 'connected' => true] + $config;
                $anyConnected = true;
            } elseif (isset($previous[$key])) {
                $channels[] = $previous[$key];
                $anyConnected = $anyConnected || !empty($previous[$key]['connected']);
            } else {
                // Never exchanged successfully — keep the slot visible in the
                // admin UI but unusable.
                $channels[] = ['key' => $key, 'connected' => false];
            }
        }

        $this->settings->set(Channels::SETTING, json_encode($channels, JSON_UNESCAPED_SLASHES));
        $this->settings->set('linkrobins-chirp.connected', $anyConnected ? '1' : '0');

        // The channels JSON is now the single source of truth; the v1.0 flat
        // settings would otherwise shadow it as a stale legacy fallback.
        $this->deleteLegacyFlatSettings();
    }

    protected function deleteLegacyFlatSettings(): void
    {
        foreach (['endpoint', 'api-key', 'api-secret', 'speaker-slots', 'recordings-available'] as $suffix) {
            $this->settings->delete('linkrobins-chirp.' . $suffix);
        }
    }
}

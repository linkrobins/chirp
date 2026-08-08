<?php

namespace LinkRobins\Chirp;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * The forum's connected channels, materialized from the server-only
 * 'linkrobins-chirp.channels' JSON setting (written by ExchangeKeyOnSave,
 * never serialized to any frontend).
 *
 * Slot semantics live here: each channel powers AT MOST one designated
 * voice channel (mode 'persistent') and one live broadcast (mode 'live')
 * at a time. Rooms bind to a channel by handle; rows from the single-key
 * era have a NULL channel and are attributed to the first connected
 * channel, which reproduces the old behavior exactly on one-channel
 * installs.
 *
 * Legacy fallback: installs that upgraded but never re-saved the key still
 * have the old flat settings (endpoint/api-key/api-secret/…). When the
 * channels JSON is absent, one Channel is synthesized from those so the
 * upgrade is invisible until the admin next touches the settings page.
 */
class Channels
{
    public const SETTING = 'linkrobins-chirp.channels';

    /** @var Channel[]|null */
    protected ?array $cache = null;

    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    /** @return Channel[] in the admin's configured order. */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $raw = (string) $this->settings->get(self::SETTING, '');
        if ($raw !== '') {
            $list = json_decode($raw, true);
            if (is_array($list)) {
                return $this->cache = array_values(array_map(
                    fn (array $c) => Channel::fromArray($c),
                    array_filter($list, 'is_array')
                ));
            }
        }

        // Legacy flat settings (pre-multi-channel installs).
        if ((string) $this->settings->get('linkrobins-chirp.api-secret', '') !== '') {
            return $this->cache = [Channel::fromArray([
                'handle'        => 'legacy',
                'endpoint'      => (string) $this->settings->get('linkrobins-chirp.endpoint', ''),
                'api_key'       => (string) $this->settings->get('linkrobins-chirp.api-key', ''),
                'api_secret'    => (string) $this->settings->get('linkrobins-chirp.api-secret', ''),
                'speaker_slots' => (int) $this->settings->get('linkrobins-chirp.speaker-slots', 1),
                'recordings'    => $this->settings->get('linkrobins-chirp.recordings-available') === '1',
                'connected'     => $this->settings->get('linkrobins-chirp.connected') === '1',
            ])];
        }

        return $this->cache = [];
    }

    /** @return Channel[] */
    public function connected(): array
    {
        return array_values(array_filter($this->all(), fn (Channel $c) => $c->connected && $c->apiSecret !== ''));
    }

    public function anyConnected(): bool
    {
        return $this->connected() !== [];
    }

    public function byHandle(?string $handle): ?Channel
    {
        foreach ($this->all() as $channel) {
            if ($channel->handle === $handle) {
                return $channel;
            }
        }

        return null;
    }

    /** Recording-delivery auth: which channel signs with this API key? */
    public function byApiKey(string $apiKey): ?Channel
    {
        foreach ($this->all() as $channel) {
            if ($channel->apiKey !== '' && hash_equals($channel->apiKey, $apiKey)) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * The channel a room runs on. NULL-channel rows (single-key era) belong
     * to the first connected channel; a room bound to a since-removed
     * channel falls back the same way so its bar can still be torn down.
     */
    public function forRoom(Room $room): ?Channel
    {
        $connected = $this->connected();

        if ($room->channel !== null && $room->channel !== '') {
            $bound = $this->byHandle($room->channel);
            if ($bound && $bound->connected) {
                return $bound;
            }
        }

        return $connected[0] ?? null;
    }

    /**
     * A connected channel free to host a NEW designated voice channel
     * (none of its slots hold a persistent room), or null when every
     * channel already powers one — the "buy another channel" moment.
     */
    public function freeForPersistent(): ?Channel
    {
        return $this->freeFor('persistent');
    }

    /** A connected channel with no live broadcast on it right now. */
    public function freeForLive(): ?Channel
    {
        return $this->freeFor('live');
    }

    protected function freeFor(string $mode): ?Channel
    {
        $connected = $this->connected();
        if ($connected === []) {
            return null;
        }

        $used = Room::query()->where('mode', $mode)->pluck('channel')->all();

        // Legacy NULL bindings occupy the FIRST channel's slot.
        $used = array_map(
            fn ($handle) => ($handle === null || $handle === '') ? $connected[0]->handle : (string) $handle,
            $used
        );

        foreach ($connected as $channel) {
            if (!in_array($channel->handle, $used, true)) {
                return $channel;
            }
        }

        return null;
    }

    /** Voice-channel occupancy for the admin panel: [used, total]. `used`
     *  can EXCEED total on grandfathered installs (v1.0 allowed unlimited
     *  designations) — show the honest number, don't clamp it. */
    public function persistentSlots(): array
    {
        return [
            (int) Room::query()->where('mode', 'persistent')->count(),
            count($this->connected()),
        ];
    }
}

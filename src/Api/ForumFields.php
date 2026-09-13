<?php

namespace LinkRobins\Chirp\Api;

use Flarum\Api\Serializer\AbstractSerializer;
use LinkRobins\Chirp\Channels;

/**
 * Channel-wide live state on the forum payload (loads on every page, so this
 * must stay cheap): whether a channel is free to host a live broadcast right
 * now. Lets the UI tell someone every channel is already live BEFORE they
 * click Go live.
 *
 * Multi-channel: a forum may run several channels, each with its own
 * one-live-broadcast slot — so this is a boolean ("any slot free?"), not
 * the single live discussion id it was in v1.0. Only live SHOWS count:
 * designated voice channels are standing places on their own separate slot
 * and never make a channel read as busy.
 *
 * 1.x shape: an `Extend\ApiSerializer->attributes()` mutator rather than 2.0's
 * typed field objects. The values are identical to the 2.x line and must stay
 * that way; the shared frontend reads both.
 */
class ForumFields
{
    public function __construct(protected Channels $channels)
    {
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function __invoke(AbstractSerializer $serializer, mixed $model, array $attributes): array
    {
        try {
            $attributes['chirpLiveFree'] = $this->channels->freeForLive() !== null;
        } catch (\Throwable) {
            // Fail OPEN: the start endpoint enforces for real; a read hiccup
            // here must not grey out Go live.
            $attributes['chirpLiveFree'] = true;
        }

        // Voice-channel occupancy, for the admin panel ("2 of 3 channels
        // power a voice channel"). Serialized as "used/total".
        try {
            [$used, $total] = $this->channels->persistentSlots();
            $attributes['chirpChannelSlots'] = $used . '/' . $total;
        } catch (\Throwable) {
            $attributes['chirpChannelSlots'] = '0/0';
        }

        return $attributes;
    }
}

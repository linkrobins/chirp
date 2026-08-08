<?php

namespace LinkRobins\Chirp\Api;

use Flarum\Api\Schema;
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
 */
class ForumFields
{
    public function __construct(protected Channels $channels)
    {
    }

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('chirpLiveFree')
                ->get(function () {
                    try {
                        return $this->channels->freeForLive() !== null;
                    } catch (\Throwable) {
                        // Fail OPEN: the start endpoint enforces for real; a
                        // read hiccup here must not grey out Go live.
                        return true;
                    }
                }),

            // Voice-channel occupancy, for the admin panel ("2 of 3
            // channels power a voice channel"). Serialized as "used/total".
            Schema\Str::make('chirpChannelSlots')
                ->get(function () {
                    try {
                        [$used, $total] = $this->channels->persistentSlots();

                        return $used . '/' . $total;
                    } catch (\Throwable) {
                        return '0/0';
                    }
                }),
        ];
    }
}

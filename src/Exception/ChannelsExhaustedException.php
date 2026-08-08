<?php

namespace LinkRobins\Chirp\Exception;

use Exception;
use Flarum\Foundation\KnownError;

/**
 * Every connected channel already powers a designated voice channel — the
 * next standing room needs another channel. Mapped to 409
 * chirp_channels_exhausted.
 */
class ChannelsExhaustedException extends Exception implements KnownError
{
    public function getType(): string
    {
        return 'chirp_channels_exhausted';
    }
}

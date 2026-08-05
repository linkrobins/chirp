<?php

namespace LinkRobins\Chirp\Exception;

use Exception;
use Flarum\Foundation\KnownError;

/** The channel already has a live room — mapped to 409 chirp_channel_busy. */
class ChannelBusyException extends Exception implements KnownError
{
    public function getType(): string
    {
        return 'chirp_channel_busy';
    }
}

<?php

namespace LinkRobins\Chirp\Exception;

use Exception;
use Flarum\Foundation\KnownError;

/** Every speaker slot is taken — mapped to 409 chirp_slots_full. */
class SlotsFullException extends Exception implements KnownError
{
    public function getType(): string
    {
        return 'chirp_slots_full';
    }
}

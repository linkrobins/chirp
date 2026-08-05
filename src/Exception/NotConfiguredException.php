<?php

namespace LinkRobins\Chirp\Exception;

use Exception;
use Flarum\Foundation\KnownError;

/** The forum has no active channel key — mapped to 409 chirp_not_configured. */
class NotConfiguredException extends Exception implements KnownError
{
    public function getType(): string
    {
        return 'chirp_not_configured';
    }
}

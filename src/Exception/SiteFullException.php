<?php

namespace LinkRobins\Chirp\Exception;

use Exception;
use Flarum\Foundation\KnownError;

/**
 * The site's Chirp plan is at its capacity — as many people are in voice
 * across this forum's rooms as the plan allows — mapped to 409 chirp_site_full.
 * Decided by the service when it mints the grant, not here: the count spans
 * every channel the site owns.
 */
class SiteFullException extends Exception implements KnownError
{
    public function getType(): string
    {
        return 'chirp_site_full';
    }
}

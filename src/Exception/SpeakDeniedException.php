<?php

namespace LinkRobins\Chirp\Exception;

use Exception;
use Flarum\Foundation\KnownError;

/**
 * The room's speaker policy refuses this mic request: unapproved hand in
 * 'hand' mode, or a non-author in 'op' mode. 403 with a locale-keyed
 * message (see extend.php ErrorHandling).
 */
class SpeakDeniedException extends Exception implements KnownError
{
    public function getType(): string
    {
        return 'chirp_speak_denied';
    }
}

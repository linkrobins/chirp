<?php

/*
 * This file is part of linkrobins/flarum-chirp.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Chirp\Tests\unit;

use LinkRobins\Chirp\Job\FetchRecordingJob;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RecordingCapTest extends TestCase
{
    #[Test]
    public function the_settings_default_matches_the_jobs_constant(): void
    {
        // extend.php hard-codes this rather than referencing the constant:
        // that file is evaluated on every request during extension boot, so a
        // class reference there turns any autoload hiccup into a forum-wide
        // 500. The literal keeps boot dependency-free; this test keeps the two
        // from drifting apart.
        $extend = file_get_contents(__DIR__ . '/../../extend.php');

        $this->assertStringContainsString(
            "->default('linkrobins-chirp.max-recording-bytes', '" . FetchRecordingJob::DEFAULT_MAX_BYTES . "')",
            $extend,
            'extend.php default is out of step with FetchRecordingJob::DEFAULT_MAX_BYTES'
        );
    }
}

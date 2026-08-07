<?php

namespace LinkRobins\Chirp\Post;

use Carbon\Carbon;
use Flarum\Post\AbstractEventPost;

/**
 * The event post dropped into a discussion when its live room's recording
 * arrives — the permanent audio artifact, in the thread's timeline exactly
 * where the room was. Content carries only ids/numbers; the audio itself
 * streams through the visibility-checked endpoint.
 */
class RecordingPost extends AbstractEventPost
{
    public static string $type = 'chirpRecording';

    public static function reply(int $discussionId, ?int $userId, int $recordingId, int $durationSeconds): static
    {
        $post = new static;

        $post->content       = ['recordingId' => $recordingId, 'duration' => $durationSeconds];
        $post->created_at    = Carbon::now();
        $post->discussion_id = $discussionId;
        $post->user_id       = $userId;

        return $post;
    }
}

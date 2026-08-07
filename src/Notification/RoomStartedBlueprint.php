<?php

namespace LinkRobins\Chirp\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * "X went live in <discussion>" — sent to the discussion's followers when a
 * room opens (both modes; a voice channel opening is just as much an event
 * worth showing up to). Alert-only by default; users can enable email in
 * their own notification preferences.
 */
class RoomStartedBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(
        protected Discussion $discussion,
        protected ?User $starter,
        protected string $mode,
    ) {
    }

    public function getFromUser(): ?User
    {
        return $this->starter;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->discussion;
    }

    public function getData(): array
    {
        return ['mode' => $this->mode];
    }

    public static function getType(): string
    {
        return 'chirpRoomStarted';
    }

    public static function getSubjectModel(): string
    {
        return Discussion::class;
    }
}

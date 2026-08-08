<?php

namespace LinkRobins\Chirp\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * "X scheduled a live room in <discussion>" — the heads-up that lets a
 * follower plan to show up. Alert-only by default; email via user prefs.
 */
class RoomScheduledBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(
        protected Discussion $discussion,
        protected ?User $scheduler,
        protected string $startsAt,
    ) {
    }

    public function getFromUser(): ?User
    {
        return $this->scheduler;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->discussion;
    }

    public function getData(): array
    {
        return ['startsAt' => $this->startsAt];
    }

    public static function getType(): string
    {
        return 'chirpRoomScheduled';
    }

    public static function getSubjectModel(): string
    {
        return Discussion::class;
    }
}

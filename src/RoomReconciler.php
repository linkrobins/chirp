<?php

namespace LinkRobins\Chirp;

use Carbon\Carbon;
use LinkRobins\Chirp\LiveKit\RoomService;

/**
 * Clears rows for live rooms that are no longer live on their media server.
 *
 * A LIVE room that ends NATURALLY — everyone leaves and the server's
 * departure timeout closes it — never passes through EndRoom, so its row
 * lingers and wedges that channel with 409s forever. This asks each media
 * server whether the room really is still up and deletes only the
 * confirmed-dead ones.
 *
 * Runs OUTSIDE the go-live transaction on purpose. It makes one network call
 * per busy channel, and doing that while holding `lockForUpdate` rows meant
 * a slow or timing-out media server held write locks on chirp_rooms for the
 * length of the timeout — with several channels connected, several timeouts
 * in series (v1.1.3 review, finding 9). Clearing dead rows first and then
 * opening a short transaction gives the same result without the lock being
 * hostage to an external service.
 *
 * Failure posture is unchanged and deliberate: a probe that cannot answer
 * leaves the row alone (fail-CLOSED = still busy), because deleting a row
 * for a room that IS live would let a second host start over the top of it.
 */
class RoomReconciler
{
    /** A room younger than this is exempt — see reap(). */
    private const GRACE_SECONDS = 60;

    public function __construct(
        protected Channels $channels,
        protected RoomService $rooms,
    ) {
    }

    /**
     * Delete rows for live rooms whose media server says they are gone.
     *
     * @return int how many rows were cleared
     */
    public function reap(): int
    {
        $cleared = 0;

        foreach (Room::query()->where('mode', 'live')->get() as $room) {
            // A JUST-started room has no server-side presence until its host's
            // WebRTC connect lands (the media server only creates rooms on
            // first join) — indistinguishable
            // from a dead room to the probe. Without this window a racing
            // second host silently deletes a live-in-a-moment room; the
            // two-channel drill caught exactly that.
            if ($room->created_at && $room->created_at->gt(Carbon::now()->subSeconds(self::GRACE_SECONDS))) {
                continue;
            }

            $channel = $this->channels->forRoom($room);

            if ($channel && $this->rooms->roomExists($channel, (int) $room->discussion_id) === false) {
                $room->delete();
                $cleared++;
            }
        }

        return $cleared;
    }
}

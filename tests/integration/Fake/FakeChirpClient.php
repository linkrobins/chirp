<?php

namespace LinkRobins\Chirp\Tests\integration\Fake;

use LinkRobins\Chirp\Channel;
use LinkRobins\Chirp\ChirpClient;

/**
 * Stands in for the hosted service in integration tests.
 *
 * The real client makes an HTTP round trip to mint every grant, and the harness
 * has no service to talk to. Rather than assert on a locally signed JWT — which
 * the extension can no longer produce, and which was only ever verifiable
 * because each channel had its own secret — these tests assert the thing that
 * still matters: which channel a room binds to, and that the room name the
 * service hands back is the one used.
 */
class FakeChirpClient extends ChirpClient
{
    /** Every grant this fake issued, so tests can assert what was asked for. */
    public array $minted = [];

    public function fetchConfig(string $token): ?array
    {
        return [
            'handle'        => 'ch-' . $token,
            'endpoint'      => 'wss://chirp.linkrobins.test',
            'setup_token'   => $token,
            'speaker_slots' => 5,
        ];
    }

    public function mintToken(Channel $channel, int $discussionId, string $scope = 'participant', array $participant = []): ?array
    {
        if ($channel->setupToken === '' || $discussionId < 1) {
            return null;
        }

        // Mirrors the service: the room name is derived from the channel, never
        // proposed by the caller.
        $grant = [
            'endpoint' => 'wss://chirp.linkrobins.test',
            'room'     => $channel->handle . '-d' . $discussionId,
            'token'    => 'fake.' . $scope . '.' . $channel->handle . '.' . $discussionId,
        ];

        $this->minted[] = $grant + ['scope' => $scope, 'participant' => $participant];

        return $grant;
    }

    /** Every room operation asked for, so tests can assert the derived name. */
    public array $roomOps = [];

    public function roomOp(Channel $channel, int $discussionId, string $action, array $metadata = []): ?array
    {
        if ($channel->setupToken === '' || $discussionId < 1) {
            return null;
        }

        $room = $channel->handle . '-d' . $discussionId;
        $this->roomOps[] = ['room' => $room, 'action' => $action, 'metadata' => $metadata];

        return ['room' => $room, 'exists' => true, 'created' => true, 'deleted' => true];
    }
}

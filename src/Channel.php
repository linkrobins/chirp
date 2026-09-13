<?php

namespace LinkRobins\Chirp;

/**
 * One connected Chirp channel, exchanged from one pasted channel key. A forum
 * may connect several — each powers ONE designated voice channel plus one live
 * broadcast at a time (the Discord shape: more standing rooms = more channels).
 *
 * `handle` is the service-side stable name for the channel; it survives
 * credential rotation, so it's what room rows bind to.
 *
 * This object deliberately holds no LiveKit signing key. Every forum now shares
 * one media server, and LiveKit does not bind an API key to a room prefix, so a
 * forum holding a signing secret could mint a grant for another forum's room.
 * The secret stays on the service; `setupToken` is what we authenticate WITH
 * when asking it for a grant, and it only ever yields rooms belonging to this
 * channel.
 */
class Channel
{
    /**
     * Plain public properties, not `readonly`: that keyword is PHP 8.1, and
     * this line targets every PHP that Flarum 1.8 runs on. Nothing mutates a
     * Channel — it is built once in fromArray() and read from there on — so
     * the keyword was documentation, not enforcement we depend on. The 2.x
     * line requires 8.3 and keeps it.
     */
    public function __construct(
        public string $handle,
        public string $endpoint,
        public string $setupToken,
        public int $speakerSlots,
        public bool $connected,
    ) {
    }

    /** https:// form of the wss:// endpoint, for server-to-server API calls. */
    public function httpEndpoint(): string
    {
        return preg_replace('/^wss:/', 'https:', $this->endpoint) ?? '';
    }

    /** @param array<string, mixed> $data one channel's row from the service payload */
    public static function fromArray(array $data): self
    {
        return new self(
            handle: (string) ($data['handle'] ?? ''),
            endpoint: (string) ($data['endpoint'] ?? ''),
            setupToken: (string) ($data['setup_token'] ?? ''),
            speakerSlots: max(1, (int) ($data['speaker_slots'] ?? 1)),
            connected: (bool) ($data['connected'] ?? false),
        );
    }

    /** @return array{handle:string,endpoint:string,setup_token:string,speaker_slots:int,connected:bool} */
    public function toArray(): array
    {
        return [
            'handle'        => $this->handle,
            'endpoint'      => $this->endpoint,
            'setup_token'   => $this->setupToken,
            'speaker_slots' => $this->speakerSlots,
            'connected'     => $this->connected,
        ];
    }
}

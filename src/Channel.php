<?php

namespace LinkRobins\Chirp;

/**
 * One connected Chirp channel: a dedicated audio server on the hosted
 * service, exchanged from one pasted channel key. A forum may connect
 * several — each powers ONE designated voice channel plus one live
 * broadcast at a time (the Discord shape: more standing rooms = more
 * channels).
 *
 * `handle` is the service-side stable name for the channel; it survives
 * credential rotation (the key/secret change, the handle doesn't), so it's
 * what room rows bind to.
 */
class Channel
{
    public function __construct(
        public readonly string $handle,
        public readonly string $endpoint,
        public readonly string $apiKey,
        public readonly string $apiSecret,
        public readonly int $speakerSlots,
        public readonly bool $recordings,
        public readonly bool $connected,
    ) {
    }

    /** https:// form of the wss:// endpoint, for server-to-server API calls. */
    public function httpEndpoint(): string
    {
        return preg_replace('/^wss:/', 'https:', $this->endpoint) ?? '';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            handle: (string) ($data['handle'] ?? ''),
            endpoint: (string) ($data['endpoint'] ?? ''),
            apiKey: (string) ($data['api_key'] ?? ''),
            apiSecret: (string) ($data['api_secret'] ?? ''),
            speakerSlots: max(1, (int) ($data['speaker_slots'] ?? 1)),
            recordings: (bool) ($data['recordings'] ?? false),
            connected: (bool) ($data['connected'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'handle'        => $this->handle,
            'endpoint'      => $this->endpoint,
            'api_key'       => $this->apiKey,
            'api_secret'    => $this->apiSecret,
            'speaker_slots' => $this->speakerSlots,
            'recordings'    => $this->recordings,
            'connected'     => $this->connected,
        ];
    }
}

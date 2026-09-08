<?php

namespace LinkRobins\Chirp;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Psr\Log\LoggerInterface;

/**
 * Exchanges the customer's Chirp channel key for connection config, by POSTing
 * it to the Chirp service (srvup) /chirp/config endpoint. The channel key is
 * the auth (a per-channel secret), so this is a single authenticated call.
 * Returns ['endpoint','api_key','api_secret','speaker_slots'] or null on any
 * failure.
 *
 * Runs SYNCHRONOUSLY inside the admin's settings-save request, by design (same
 * rationale as Warble's exchange): on the stock `sync` queue driver a job would
 * run inline anyway; worst case is capped hard (3s connect + 5s total) and
 * every failure path is fail-soft — the admin sees the disconnected banner,
 * never an error page.
 */
class ChirpClient
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected LoggerInterface $log,
        protected Client $http,
        protected Config $config,
    ) {
    }

    public function fetchConfig(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $base = rtrim((string) ($this->settings->get('linkrobins-chirp.service-url') ?: 'https://linkrobins.com'), '/');

        try {
            $response = $this->http->post($base . '/chirp/config', [
                // forum_url is the DELIVERY address for finished recordings —
                // the service POSTs signed notifications back to it.
                'form_params'     => ['token' => $token, 'forum_url' => (string) $this->config->url()],
                'headers'         => ['Accept' => 'application/json'],
                'connect_timeout' => 3,
                'timeout'         => 5,
                'http_errors'     => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->log->warning('Chirp: config exchange failed', ['status' => $response->getStatusCode()]);
                return null;
            }

            $data = json_decode((string) $response->getBody(), true);
            if (!is_array($data) || empty($data['endpoint'])) {
                return null;
            }

            // A service that still answers with api_secret is running the old
            // per-channel media servers, which this build can no longer address:
            // it has no local signer. Refuse rather than half-connect.
            if (!empty($data['api_secret'])) {
                $this->log->warning('Chirp: service returned a signing secret; this build expects service-minted tokens.');
                return null;
            }

            return [
                // Stable service-side channel name; survives credential rotation
                // so room→channel bindings do too.
                'handle'        => (string) (Arr::get($data, 'handle') ?: substr(sha1((string) $data['endpoint']), 0, 12)),
                'endpoint'      => (string) $data['endpoint'],
                // Kept so we can authenticate later token requests as this channel.
                'setup_token'   => $token,
                'speaker_slots' => max(1, (int) Arr::get($data, 'speaker_slots', 1)),
                'recordings'    => (bool) Arr::get($data, 'recordings', false),
            ];
        } catch (\Throwable $e) {
            $this->log->warning('Chirp: config exchange threw', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Ask the service for a LiveKit grant on one of this channel's rooms.
     *
     * We send a discussion id, never a room name: the service derives the name
     * from the channel our setup token resolves to, which is what stops one
     * forum from addressing another's room on the shared media server. It hands
     * back the name it chose so server-to-server calls can use the same one.
     *
     * @param string $scope 'participant' for a join grant, 'admin' for a
     *                      sixty-second room-scoped moderation grant.
     * @return array{endpoint:string,room:string,token:string}|null
     */
    public function mintToken(Channel $channel, int $discussionId, string $scope = 'participant', array $participant = []): ?array
    {
        if ($channel->setupToken === '' || $discussionId < 1) {
            return null;
        }

        $base = rtrim((string) ($this->settings->get('linkrobins-chirp.service-url') ?: 'https://linkrobins.com'), '/');

        try {
            $response = $this->http->post($base . '/chirp/token', [
                'form_params' => array_filter([
                    'token'      => $channel->setupToken,
                    'discussion' => $discussionId,
                    'scope'      => $scope,
                    'identity'   => (string) ($participant['identity'] ?? ''),
                    'name'       => (string) ($participant['name'] ?? ''),
                    'publish'    => !empty($participant['publish']) ? '1' : '',
                ], fn ($v) => $v !== ''),
                'headers'         => ['Accept' => 'application/json'],
                'connect_timeout' => 3,
                'timeout'         => 5,
                'http_errors'     => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->log->warning('Chirp: token mint failed', ['status' => $response->getStatusCode(), 'scope' => $scope]);
                return null;
            }

            $data = json_decode((string) $response->getBody(), true);
            if (!is_array($data) || empty($data['token']) || empty($data['room'])) {
                return null;
            }

            return [
                'endpoint' => (string) ($data['endpoint'] ?? $channel->endpoint),
                'room'     => (string) $data['room'],
                'token'    => (string) $data['token'],
            ];
        } catch (\Throwable $e) {
            $this->log->warning('Chirp: token mint threw', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Ask the service to create, delete or check one of this channel's rooms.
     *
     * These three cannot ride the room-scoped admin grant the way moderation
     * does. LiveKit checks `roomAdmin` against the room named in the token, but
     * `roomCreate` and `roomList` are instance-wide, so a token carrying them
     * works on every room on a shared server regardless of its claim. A drill
     * showed exactly that: one tenant's grant deleted another tenant's room. So
     * the service performs them with a grant that never leaves it, on a room it
     * derives from our channel.
     *
     * @param string $action create | delete | exists
     */
    public function roomOp(Channel $channel, int $discussionId, string $action, array $metadata = []): ?array
    {
        if ($channel->setupToken === '' || $discussionId < 1) {
            return null;
        }

        $base = rtrim((string) ($this->settings->get('linkrobins-chirp.service-url') ?: 'https://linkrobins.com'), '/');

        try {
            $params = [
                'token'      => $channel->setupToken,
                'discussion' => $discussionId,
                'action'     => $action,
            ];
            if ($metadata !== []) {
                $params['metadata'] = $metadata;
            }

            $response = $this->http->post($base . '/chirp/room', [
                'form_params'     => $params,
                'headers'         => ['Accept' => 'application/json'],
                'connect_timeout' => 3,
                'timeout'         => 5,
                'http_errors'     => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->log->warning('Chirp: room op failed', ['status' => $response->getStatusCode(), 'action' => $action]);
                return null;
            }

            $data = json_decode((string) $response->getBody(), true);

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            $this->log->warning('Chirp: room op threw', ['error' => $e->getMessage(), 'action' => $action]);
            return null;
        }
    }
}

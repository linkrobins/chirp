<?php

namespace LinkRobins\Chirp;

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
                'form_params'     => ['token' => $token],
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
            if (!is_array($data) || empty($data['endpoint']) || empty($data['api_key']) || empty($data['api_secret'])) {
                return null;
            }

            return [
                'endpoint'      => (string) $data['endpoint'],
                'api_key'       => (string) $data['api_key'],
                'api_secret'    => (string) $data['api_secret'],
                'speaker_slots' => max(1, (int) Arr::get($data, 'speaker_slots', 1)),
            ];
        } catch (\Throwable $e) {
            $this->log->warning('Chirp: config exchange threw', ['error' => $e->getMessage()]);
            return null;
        }
    }
}

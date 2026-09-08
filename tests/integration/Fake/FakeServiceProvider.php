<?php

namespace LinkRobins\Chirp\Tests\integration\Fake;

use Flarum\Foundation\AbstractServiceProvider;
use LinkRobins\Chirp\ChirpClient;

/** Binds the stub service client for the duration of an integration test. */
class FakeServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(ChirpClient::class, function ($container) {
            return new FakeChirpClient(
                $container->make(\Flarum\Settings\SettingsRepositoryInterface::class),
                $container->make(\Psr\Log\LoggerInterface::class),
                new \GuzzleHttp\Client(),
                $container->make(\Flarum\Foundation\Config::class),
            );
        });
    }
}

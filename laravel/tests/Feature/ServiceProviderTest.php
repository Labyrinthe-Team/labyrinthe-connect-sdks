<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use Labyrinthe\Connect\Client;
use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;
use Labyrinthe\Connect\Laravel\LabyrintheManager;
use Labyrinthe\Connect\Laravel\LabyrintheServiceProvider;
use Labyrinthe\Connect\Laravel\OtpGateway;
use Labyrinthe\Connect\Laravel\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_the_client_is_a_singleton_built_from_config(): void
    {
        config()->set('labyrinthe.base_url', 'http://localhost:8000');

        $client = app()->make(Client::class);

        self::assertSame($client, app()->make(Client::class));
        self::assertTrue($client->isTestMode());
        self::assertSame('http://localhost:8000/api/public/v1', $client->baseUrl());
    }

    public function test_a_missing_api_key_fails_with_a_clear_message_only_when_used(): void
    {
        config()->set('labyrinthe.api_key', null);

        // Booting the app does not fail.
        self::assertInstanceOf(LabyrintheManager::class, app()->make('labyrinthe'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('LABYRINTHE_API_KEY');
        app()->make(Client::class);
    }

    public function test_the_user_agent_identifies_laravel(): void
    {
        $client = app()->make(Client::class);
        $transport = (new \ReflectionProperty($client->sms, 'transport'))->getValue($client->sms);
        self::assertIsObject($transport);
        $userAgent = (new \ReflectionProperty($transport, 'userAgent'))->getValue($transport);

        self::assertIsString($userAgent);
        self::assertStringContainsString('labyrinthe-laravel/'.LabyrintheServiceProvider::VERSION, $userAgent);
        self::assertStringContainsString('laravel/'.app()->version(), $userAgent);
    }

    public function test_the_facade_exposes_the_services(): void
    {
        self::assertSame(app()->make(Client::class)->sms, Labyrinthe::sms());
        self::assertSame(app()->make(Client::class), Labyrinthe::client());
        self::assertInstanceOf(OtpGateway::class, Labyrinthe::otp());
        self::assertTrue(Labyrinthe::isTestMode());
    }

    public function test_the_manager_is_injectable(): void
    {
        self::assertSame(app()->make('labyrinthe'), app()->make(LabyrintheManager::class));
    }

    public function test_config_and_translations_are_publishable(): void
    {
        $config = ServiceProvider::pathsToPublish(LabyrintheServiceProvider::class, 'labyrinthe-config');
        $lang = ServiceProvider::pathsToPublish(LabyrintheServiceProvider::class, 'labyrinthe-lang');

        self::assertCount(1, $config);
        self::assertFileExists((string) array_key_first($config));
        $target = array_values($config)[0];
        self::assertIsString($target);
        self::assertStringEndsWith('labyrinthe.php', $target);
        self::assertCount(1, $lang);
    }

    public function test_default_config_values(): void
    {
        self::assertSame('243', config('labyrinthe.default_country_code'));
        self::assertSame('webhooks/labyrinthe', config('labyrinthe.webhook.path'));
        self::assertSame(300, config('labyrinthe.webhook.tolerance'));
        self::assertTrue(config('labyrinthe.webhook.deduplicate'));
        self::assertSame(2, config('labyrinthe.max_retries'));
    }
}

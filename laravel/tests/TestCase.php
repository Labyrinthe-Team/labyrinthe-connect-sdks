<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Tests;

use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;
use Labyrinthe\Connect\Laravel\LabyrintheServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected const WEBHOOK_SECRET = 'whsec_laravel_test';

    protected function getPackageProviders($app): array
    {
        return [LabyrintheServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Labyrinthe' => Labyrinthe::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('labyrinthe.api_key', 'lsk_test_package_tests');
        $app['config']->set('labyrinthe.webhook.secret', self::WEBHOOK_SECRET);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }
}

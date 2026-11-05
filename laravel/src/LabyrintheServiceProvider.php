<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Labyrinthe\Connect\Client;
use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\Laravel\Http\Controllers\WebhookController;
use Labyrinthe\Connect\Laravel\Notifications\LabyrintheChannel;
use Psr\Log\LoggerInterface;

final class LabyrintheServiceProvider extends ServiceProvider
{
    public const VERSION = '1.0.0';

    /**
     * CSRF middleware of Laravel 10 (VerifyCsrfToken), 11-12 (ValidateCsrfToken)
     * and 13 (PreventRequestForgery), excluded from the webhook route
     * (signed server-to-server calls carry no CSRF token).
     *
     * @var list<string>
     */
    public const CSRF_MIDDLEWARE = [
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/labyrinthe.php', 'labyrinthe');

        // Built lazily: a missing key only fails when the API is actually used.
        $this->app->singleton(Client::class, static fn (Application $app): Client => self::makeClient($app));
        $this->app->singleton('labyrinthe', static fn (Application $app): LabyrintheManager => new LabyrintheManager($app));
        $this->app->alias('labyrinthe', LabyrintheManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/labyrinthe.php' => $this->app->configPath('labyrinthe.php')], 'labyrinthe-config');
            $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/labyrinthe')], 'labyrinthe-lang');
        }

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'labyrinthe');

        $this->callAfterResolving(ChannelManager::class, function (ChannelManager $channels): void {
            $channels->extend('labyrinthe', fn (Application $app): LabyrintheChannel => $app->make(LabyrintheChannel::class));
        });

        $this->registerRouteMacro();
    }

    /**
     * Route::labyrintheWebhooks('webhooks/labyrinthe'): POST endpoint that
     * verifies signatures and dispatches events. CSRF verification is
     * excluded, so it also works from routes/web.php.
     */
    private function registerRouteMacro(): void
    {
        if (Router::hasMacro('labyrintheWebhooks')) {
            return;
        }

        Router::macro('labyrintheWebhooks', function (?string $uri = null): Route {
            /** @var Router $this */
            $path = $uri ?? config('labyrinthe.webhook.path');

            $csrf = array_values(array_filter(LabyrintheServiceProvider::CSRF_MIDDLEWARE, static fn (string $class): bool => class_exists($class)));

            return $this->post(\is_string($path) && $path !== '' ? $path : 'webhooks/labyrinthe', WebhookController::class)
                ->name('labyrinthe.webhooks')
                ->withoutMiddleware($csrf);
        });
    }

    private static function makeClient(Application $app): Client
    {
        /** @var Config $config */
        $config = $app->make('config');

        $apiKey = $config->get('labyrinthe.api_key');
        if (!\is_string($apiKey) || trim($apiKey) === '') {
            throw new InvalidArgumentException('Labyrinthe Connect: set LABYRINTHE_API_KEY in your .env file (config/labyrinthe.php, "api_key").');
        }

        $sender = $config->get('labyrinthe.sender');
        $baseUrl = $config->get('labyrinthe.base_url');
        $countryCode = $config->get('labyrinthe.default_country_code', '243');

        return new Client(
            apiKey: $apiKey,
            baseUrl: \is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
            timeout: self::float($config->get('labyrinthe.timeout'), 30.0),
            connectTimeout: self::float($config->get('labyrinthe.connect_timeout'), 10.0),
            maxRetries: is_numeric($retries = $config->get('labyrinthe.max_retries')) ? (int) $retries : 2,
            defaultCountryCode: \is_scalar($countryCode) ? (string) $countryCode : '243',
            defaultSender: \is_string($sender) && $sender !== '' ? $sender : null,
            logger: self::logger($app, $config->get('labyrinthe.log_channel')),
            userAgentSuffix: \sprintf('labyrinthe-laravel/%s laravel/%s', self::VERSION, $app->version()),
        );
    }

    private static function float(mixed $value, float $default): float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : $default;
    }

    private static function logger(Application $app, mixed $channel): ?LoggerInterface
    {
        if (!\is_string($channel) || $channel === '') {
            return null;
        }

        return $app->make(LogManager::class)->channel($channel);
    }
}

<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use Labyrinthe\Connect\Client;
use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\Tests\Support\TestCase;
use Labyrinthe\Connect\Version;
use PHPUnit\Framework\Attributes\DataProvider;

final class ClientTest extends TestCase
{
    public function test_it_detects_test_and_live_mode_from_the_key_prefix(): void
    {
        $test = new Client('lsk_test_abc');
        self::assertTrue($test->isTestMode());
        self::assertFalse($test->isLiveMode());

        $live = new Client('lsk_live_abc');
        self::assertFalse($live->isTestMode());
        self::assertTrue($live->isLiveMode());
    }

    public function test_it_requires_an_api_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client('  ');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function baseUrls(): iterable
    {
        yield 'default' => ['https://api.connect.labyrinthe-rdc.com/api/public/v1', 'https://api.connect.labyrinthe-rdc.com/api/public/v1'];
        yield 'bare host' => ['http://localhost:8000', 'http://localhost:8000/api/public/v1'];
        yield 'bare host with slash' => ['http://localhost:8000/', 'http://localhost:8000/api/public/v1'];
        yield 'explicit path' => ['https://proxy.example/labyrinthe/v1/', 'https://proxy.example/labyrinthe/v1'];
    }

    #[DataProvider('baseUrls')]
    public function test_it_normalises_the_base_url(string $input, string $expected): void
    {
        self::assertSame($expected, (new Client('lsk_test_x', baseUrl: $input))->baseUrl());
    }

    public function test_it_rejects_an_invalid_base_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client('lsk_test_x', baseUrl: 'localhost:8000');
    }

    public function test_it_rejects_invalid_options(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client('lsk_test_x', maxRetries: -1);
    }

    public function test_it_rejects_an_invalid_default_country_code(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client('lsk_test_x', defaultCountryCode: '+243');
    }

    public function test_it_sends_authentication_and_identification_headers(): void
    {
        $client = $this->client([self::json(200, ['data' => ['balance' => 10, 'held' => 0]])]);
        $client->balance->get();

        $request = $this->request();
        self::assertSame('Bearer '.self::TEST_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame(\sprintf('labyrinthe-php/%s PHP/%s', Version::SDK, \PHP_VERSION), $request->getHeaderLine('User-Agent'));
        self::assertSame('https://api.test/api/public/v1/balance', (string) $request->getUri());
    }

    public function test_last_response_exposes_status_and_headers_of_successes_and_errors(): void
    {
        $client = $this->client([
            self::json(202, ['data' => [self::message()]], [
                'X-Request-Id' => 'req-ok', 'X-Api-Version' => '1', 'Idempotent-Replayed' => 'true',
                'X-RateLimit-Limit' => '120', 'X-RateLimit-Remaining' => '119',
            ]),
            self::error(404, 'NOT_FOUND', [], ['X-Request-Id' => 'req-404', 'X-Api-Version' => '1']),
        ], maxRetries: 0);
        self::assertNull($client->lastResponse());

        $client->sms->send('0812345678', 'Hello');
        $info = $client->lastResponse();
        self::assertNotNull($info);
        self::assertSame(202, $info->status);
        self::assertSame('req-ok', $info->requestId());
        self::assertSame('1', $info->apiVersion());
        self::assertTrue($info->isReplayed());
        self::assertSame(120, $info->rateLimitLimit());
        self::assertSame(119, $info->rateLimitRemaining());
        self::assertSame('req-ok', $info->header('x-request-id'));

        try {
            $client->sms->get('missing');
        } catch (\Labyrinthe\Connect\Exception\NotFoundException) {
        }
        $error = $client->lastResponse();
        self::assertNotNull($error);
        self::assertSame(404, $error->status);
        self::assertSame('req-404', $error->requestId());
        self::assertFalse($error->isReplayed());
        self::assertNull($error->rateLimitLimit());
    }

    public function test_it_appends_the_user_agent_suffix(): void
    {
        $client = new Client('lsk_test_x', userAgentSuffix: 'labyrinthe-laravel/1.0.0');
        $transport = (new \ReflectionProperty($client->sms, 'transport'))->getValue($client->sms);
        self::assertIsObject($transport);
        $userAgent = (new \ReflectionProperty($transport, 'userAgent'))->getValue($transport);
        self::assertIsString($userAgent);
        self::assertStringEndsWith(' labyrinthe-laravel/1.0.0', $userAgent);
    }

    public function test_the_default_http_client_has_the_configured_timeouts(): void
    {
        $client = new Client('lsk_test_x', timeout: 7.5, connectTimeout: 2.0);
        $transport = (new \ReflectionProperty($client->sms, 'transport'))->getValue($client->sms);
        self::assertIsObject($transport);
        $http = (new \ReflectionProperty($transport, 'http'))->getValue($transport);
        self::assertInstanceOf(GuzzleClient::class, $http);
        $config = (new \ReflectionProperty(GuzzleClient::class, 'config'))->getValue($http);
        self::assertIsArray($config);
        self::assertSame(7.5, $config['timeout']);
        self::assertSame(2.0, $config['connect_timeout']);
    }

    public function test_from_env_reads_the_key_and_base_url(): void
    {
        $_ENV['LABYRINTHE_API_KEY'] = 'lsk_live_fromenv';
        $_ENV['LABYRINTHE_BASE_URL'] = 'http://localhost:8000';

        try {
            $client = Client::fromEnv();
            self::assertTrue($client->isLiveMode());
            self::assertSame('http://localhost:8000/api/public/v1', $client->baseUrl());
        } finally {
            unset($_ENV['LABYRINTHE_API_KEY'], $_ENV['LABYRINTHE_BASE_URL']);
        }
    }

    public function test_from_env_fails_clearly_without_a_key(): void
    {
        $previous = getenv('LABYRINTHE_API_KEY');
        putenv('LABYRINTHE_API_KEY');
        unset($_ENV['LABYRINTHE_API_KEY'], $_SERVER['LABYRINTHE_API_KEY']);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('LABYRINTHE_API_KEY');
            Client::fromEnv();
        } finally {
            if (\is_string($previous)) {
                putenv('LABYRINTHE_API_KEY='.$previous);
            }
        }
    }
}

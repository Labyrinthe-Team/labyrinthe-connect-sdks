<?php

declare(strict_types=1);

namespace Labyrinthe\Connect;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\Http\NativeSleeper;
use Labyrinthe\Connect\Http\Sleeper;
use Labyrinthe\Connect\Internal\Redactor;
use Labyrinthe\Connect\Internal\Transport;
use Labyrinthe\Connect\Service\BalanceService;
use Labyrinthe\Connect\Service\OtpService;
use Labyrinthe\Connect\Service\SenderIdService;
use Labyrinthe\Connect\Service\SmsService;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Labyrinthe Connect API client.
 *
 *     $client = new Client('lsk_test_…');
 *     $message = $client->sms->send(to: '0812345678', body: 'Hello!');
 *
 * Keys starting with `lsk_test_` use the sandbox: nothing is sent, no credit
 * is used, responses have the same shape as in production.
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.labyrinthe-rdc.com/api/public/v1';
    private const API_PATH = '/api/public/v1';

    public readonly SmsService $sms;
    public readonly BalanceService $balance;
    public readonly SenderIdService $senderIds;
    public readonly OtpService $otp;

    private readonly string $apiKey;
    private readonly string $baseUrl;

    /**
     * @param string                       $apiKey             `lsk_live_…` or `lsk_test_…` (keep it server-side)
     * @param string|null                  $baseUrl            API root; "http://localhost:8000" is completed with /api/public/v1
     * @param float                        $timeout            total request timeout, in seconds (default Guzzle client only)
     * @param float                        $connectTimeout     connection timeout, in seconds (default Guzzle client only)
     * @param int                          $maxRetries         automatic retries when it is safe (0 disables them)
     * @param float                        $maxRetryWait       longest wait accepted before a retry (Retry-After), in seconds
     * @param string                       $defaultCountryCode added to local numbers ("0812345678" → "243812345678")
     * @param string|null                  $defaultSender      Sender ID used when send() gets none
     * @param ClientInterface|null         $httpClient         any PSR-18 client (configure its timeouts yourself)
     * @param RequestFactoryInterface|null $requestFactory     PSR-17 factory (defaults to Guzzle's)
     * @param StreamFactoryInterface|null  $streamFactory      PSR-17 factory (defaults to Guzzle's)
     * @param LoggerInterface|null         $logger             PSR-3 logger; never receives the key, codes or full phone numbers
     * @param Sleeper|null                 $sleeper            waits between retries (inject a no-op one in tests)
     * @param string|null                  $userAgentSuffix    appended to the User-Agent (integrations, e.g. "labyrinthe-laravel/1.0.0")
     */
    public function __construct(
        #[\SensitiveParameter]
        string $apiKey,
        ?string $baseUrl = null,
        float $timeout = 30.0,
        float $connectTimeout = 10.0,
        int $maxRetries = 2,
        float $maxRetryWait = 10.0,
        string $defaultCountryCode = PhoneNumber::DEFAULT_COUNTRY_CODE,
        ?string $defaultSender = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        ?Sleeper $sleeper = null,
        ?string $userAgentSuffix = null,
    ) {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new InvalidArgumentException('A Labyrinthe Connect API key is required (lsk_live_… or lsk_test_…).');
        }
        if ($timeout <= 0 || $connectTimeout <= 0) {
            throw new InvalidArgumentException('timeout and connectTimeout must be greater than 0.');
        }
        if ($maxRetries < 0 || $maxRetries > 10) {
            throw new InvalidArgumentException('maxRetries must be between 0 and 10.');
        }
        if ($maxRetryWait < 0) {
            throw new InvalidArgumentException('maxRetryWait cannot be negative.');
        }
        PhoneNumber::assertCountryCode($defaultCountryCode);
        if ($defaultSender === '') {
            $defaultSender = null;
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = self::normalizeBaseUrl($baseUrl ?? self::DEFAULT_BASE_URL);

        $factory = new HttpFactory();

        $transport = new Transport(
            apiKey: $apiKey,
            baseUrl: $this->baseUrl,
            http: $httpClient ?? new GuzzleClient([
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
                'http_errors' => false,
                'allow_redirects' => false,
            ]),
            requestFactory: $requestFactory ?? $factory,
            streamFactory: $streamFactory ?? $factory,
            maxRetries: $maxRetries,
            maxRetryWait: $maxRetryWait,
            sleeper: $sleeper ?? new NativeSleeper(),
            logger: $logger ?? new NullLogger(),
            userAgent: self::userAgent($userAgentSuffix),
        );

        $this->sms = new SmsService($transport, $defaultCountryCode, $defaultSender);
        $this->balance = new BalanceService($transport);
        $this->senderIds = new SenderIdService($transport);
        $this->otp = new OtpService($transport, $defaultCountryCode);
    }

    /**
     * Builds a client from LABYRINTHE_API_KEY and, optionally, LABYRINTHE_BASE_URL.
     *
     * @throws InvalidArgumentException when LABYRINTHE_API_KEY is not set
     */
    public static function fromEnv(
        float $timeout = 30.0,
        int $maxRetries = 2,
        string $defaultCountryCode = PhoneNumber::DEFAULT_COUNTRY_CODE,
        ?string $defaultSender = null,
        ?ClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ): self {
        $apiKey = self::env('LABYRINTHE_API_KEY');
        if ($apiKey === null) {
            throw new InvalidArgumentException('The LABYRINTHE_API_KEY environment variable is not set.');
        }

        return new self(
            apiKey: $apiKey,
            baseUrl: self::env('LABYRINTHE_BASE_URL'),
            timeout: $timeout,
            maxRetries: $maxRetries,
            defaultCountryCode: $defaultCountryCode,
            defaultSender: $defaultSender,
            httpClient: $httpClient,
            logger: $logger,
        );
    }

    /** True with a sandbox key (`lsk_test_…`): nothing is sent, no credit is used. */
    public function isTestMode(): bool
    {
        return str_starts_with($this->apiKey, 'lsk_test_');
    }

    /** True with a production key (`lsk_live_…`). */
    public function isLiveMode(): bool
    {
        return str_starts_with($this->apiKey, 'lsk_live_');
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => Redactor::apiKey($this->apiKey),
            'baseUrl' => $this->baseUrl,
            'testMode' => $this->isTestMode(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('The Labyrinthe Connect client cannot be serialized (it holds the API key).');
    }

    private static function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('baseUrl must be an absolute http(s) URL, e.g. https://api.labyrinthe-rdc.com/api/public/v1.');
        }

        // A bare host ("http://localhost:8000") points to the root of the backend.
        if (($parts['path'] ?? '') === '') {
            $baseUrl .= self::API_PATH;
        }

        return $baseUrl;
    }

    private static function userAgent(?string $suffix): string
    {
        $userAgent = \sprintf('labyrinthe-php/%s PHP/%s', Version::SDK, \PHP_VERSION);

        return $suffix === null || trim($suffix) === '' ? $userAgent : $userAgent.' '.trim($suffix);
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

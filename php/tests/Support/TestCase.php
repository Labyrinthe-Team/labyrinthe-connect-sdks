<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Support;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Labyrinthe\Connect\Client;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a Client over Guzzle's MockHandler and records sent requests.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected const TEST_KEY = 'lsk_test_0123456789abcdefSECRETPART';

    /** @var list<array{request: RequestInterface}> */
    protected array $history = [];

    protected RecordingSleeper $sleeper;

    protected MockHandler $mock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->history = [];
        $this->sleeper = new RecordingSleeper();
    }

    /**
     * @param list<mixed> $queue Responses or exceptions, in order
     */
    protected function client(
        array $queue,
        int $maxRetries = 2,
        float $maxRetryWait = 10.0,
        ?LoggerInterface $logger = null,
        ?string $defaultSender = null,
        string $apiKey = self::TEST_KEY,
    ): Client {
        $this->mock = new MockHandler($queue);
        $stack = HandlerStack::create($this->mock);
        $history = &$this->history;
        $stack->push(Middleware::history($history));

        return new Client(
            apiKey: $apiKey,
            baseUrl: 'https://api.test/api/public/v1',
            maxRetries: $maxRetries,
            maxRetryWait: $maxRetryWait,
            defaultSender: $defaultSender,
            httpClient: new GuzzleClient(['handler' => $stack]),
            logger: $logger,
            sleeper: $this->sleeper,
        );
    }

    /**
     * @param array<mixed>          $body
     * @param array<string, string> $headers
     */
    protected static function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<mixed>          $details
     * @param array<string, string> $headers
     */
    protected static function error(int $status, string $code, array $details = [], array $headers = []): Response
    {
        return self::json($status, ['error' => ['code' => $code, 'message' => 'Message en français.', 'details' => (object) $details]], $headers);
    }

    /**
     * A Message as returned by the API (every field of the spec).
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected static function message(array $overrides = []): array
    {
        return $overrides + [
            'id' => '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10',
            'reference' => null,
            'recipient' => '243812345678',
            'sender' => 'LABYRINTHE',
            'encoding' => 'gsm7',
            'status' => 'queued',
            'segments' => 1,
            'credits_charged' => 1,
            'campaign_id' => null,
            'provider_message_id' => null,
            'error_code' => null,
            'error_message' => null,
            'created_at' => '2026-11-05T10:00:00+00:00',
            'submitted_at' => null,
            'delivered_at' => null,
        ];
    }

    /**
     * An OtpSendResult as returned by the API with a test key (every field of the spec).
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected static function otpSendData(array $overrides = []): array
    {
        return $overrides + [
            'otp_id' => 'otp-1',
            'expires_at' => '2026-11-05T10:05:00+00:00',
            'max_attempts' => 3,
            'code_length' => 6,
            'code_type' => 'numeric',
            'resend_after_seconds' => 60,
            'test_code' => '482913',
        ];
    }

    protected function request(int $index = 0): RequestInterface
    {
        self::assertArrayHasKey($index, $this->history, \sprintf('Request #%d was not sent.', $index));

        return $this->history[$index]['request'];
    }

    /**
     * @return array<mixed>
     */
    protected function requestJson(int $index = 0): array
    {
        $decoded = json_decode((string) $this->request($index)->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

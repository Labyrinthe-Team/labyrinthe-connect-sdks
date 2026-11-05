<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Labyrinthe\Connect\Exception\ApiException;
use Labyrinthe\Connect\Exception\ConnectionException;
use Labyrinthe\Connect\Tests\Support\MemoryLogger;
use Labyrinthe\Connect\Tests\Support\TestCase;

/**
 * The API key, OTP codes and full phone numbers never leak.
 */
final class SecurityTest extends TestCase
{
    private const SECRET_PART = 'SECRETPART';

    private static function dump(mixed $value): string
    {
        ob_start();
        var_dump($value);
        $dump = (string) ob_get_clean();

        return $dump.print_r($value, true);
    }

    public function test_dumping_the_client_or_its_services_never_shows_the_key(): void
    {
        $client = $this->client([]);

        foreach ([$client, $client->sms, $client->otp, $client->balance, $client->senderIds] as $object) {
            $dump = self::dump($object);
            self::assertStringNotContainsString(self::SECRET_PART, $dump);
        }
        self::assertStringContainsString('lsk_test_****', self::dump($client));
    }

    public function test_dumping_a_page_never_shows_the_key(): void
    {
        $client = $this->client([self::json(200, ['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 0]])]);

        self::assertStringNotContainsString(self::SECRET_PART, self::dump($client->sms->list()));
    }

    public function test_the_client_cannot_be_serialized(): void
    {
        $this->expectException(\LogicException::class);
        serialize($this->client([]));
    }

    public function test_the_test_code_is_hidden_from_dumps_and_json(): void
    {
        $client = $this->client([self::json(201, ['data' => ['otp_id' => 'otp-1', 'expires_at' => '2026-11-05T10:05:00Z', 'max_attempts' => 3, 'test_code' => '482913']])]);

        $otp = $client->otp->send('0812345678');

        self::assertSame('482913', $otp->testCode());
        self::assertStringNotContainsString('482913', self::dump($otp));
        self::assertStringNotContainsString('482913', (string) json_encode($otp));
        self::assertStringContainsString('[redacted]', self::dump($otp));
    }

    public function test_exceptions_never_contain_the_key(): void
    {
        $client = $this->client([
            self::error(401, 'UNAUTHENTICATED'),
            new ConnectException('cURL error 7', new Request('GET', 'https://api.test', ['Authorization' => 'Bearer '.self::TEST_KEY])),
        ], maxRetries: 0);

        foreach ([1, 2] as $_) {
            try {
                $client->balance->get();
                self::fail('An exception was expected.');
            } catch (ApiException|ConnectionException $e) {
                self::assertNull($e->getPrevious(), 'The PSR-18 exception (which holds the request) is not chained.');
                self::assertStringNotContainsString(self::SECRET_PART, self::dump($e));
                self::assertStringNotContainsString(self::SECRET_PART, (string) $e);
            }
        }
    }

    public function test_sensitive_arguments_are_redacted_from_stack_traces(): void
    {
        if (\PHP_VERSION_ID < 80200) {
            self::markTestSkipped('#[SensitiveParameter] requires PHP 8.2.');
        }

        $client = $this->client([self::error(500, 'HTTP_ERROR')], maxRetries: 0);
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            $client->otp->verify('otp-1', '482913');
            self::fail('An exception was expected.');
        } catch (ApiException $e) {
            self::assertStringNotContainsString('482913', $e->getTraceAsString());
            self::assertStringNotContainsString('482913', self::dump($e->getTrace()));
        } finally {
            if (\is_string($previous)) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    public function test_logs_contain_no_key_no_code_no_body_and_masked_numbers(): void
    {
        $logger = new MemoryLogger();
        $client = $this->client([
            self::json(202, ['data' => [self::message()]]),
            self::json(200, ['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 0]]),
            self::json(201, ['data' => ['otp_id' => 'otp-1', 'expires_at' => '2026-11-05T10:05:00Z', 'max_attempts' => 3, 'test_code' => '482913']]),
            self::error(503, 'HTTP_ERROR'),
        ], maxRetries: 0, logger: $logger);

        $client->sms->send('0812345678', 'Votre code est 771122');
        $client->sms->list(recipient: '0812345678');
        $client->otp->send('0812345678');
        try {
            $client->otp->verify('otp-1', '482913');
        } catch (ApiException) {
        }

        $logs = $logger->dump();
        self::assertNotSame([], $logger->records);
        self::assertStringNotContainsString(self::SECRET_PART, $logs);
        self::assertStringNotContainsString('482913', $logs);
        self::assertStringNotContainsString('771122', $logs);
        self::assertStringNotContainsString('812345678', $logs);
        self::assertStringContainsString('recipient=24381*****78', $logs);
    }
}

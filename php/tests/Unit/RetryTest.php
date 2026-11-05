<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Labyrinthe\Connect\Exception\ConnectionException;
use Labyrinthe\Connect\Exception\IdempotencyKeyReusedException;
use Labyrinthe\Connect\Exception\OtpRateLimitedException;
use Labyrinthe\Connect\Exception\OtpResendTooSoonException;
use Labyrinthe\Connect\Exception\RateLimitException;
use Labyrinthe\Connect\Exception\ServiceUnavailableException;
use Labyrinthe\Connect\Exception\ValidationException;
use Labyrinthe\Connect\Tests\Support\TestCase;

final class RetryTest extends TestCase
{
    private static function networkError(string $message = 'cURL error 7: Failed to connect'): ConnectException
    {
        return new ConnectException($message, new Request('GET', 'https://api.test'));
    }

    private static function otpSent(): \GuzzleHttp\Psr7\Response
    {
        return self::json(201, ['data' => ['otp_id' => 'otp-1', 'expires_at' => '2026-11-05T10:05:00+00:00', 'max_attempts' => 3]]);
    }

    public function test_get_is_retried_on_5xx_then_succeeds(): void
    {
        $client = $this->client([
            self::error(503, 'HTTP_ERROR'),
            self::error(500, 'HTTP_ERROR'),
            self::json(200, ['data' => ['balance' => 5, 'held' => 0]]),
        ]);

        self::assertSame(5, $client->balance->get()->balance);
        self::assertCount(3, $this->history);
        self::assertCount(2, $this->sleeper->sleeps);
    }

    public function test_retries_stop_after_max_retries(): void
    {
        $client = $this->client(array_fill(0, 5, self::error(503, 'HTTP_ERROR')), maxRetries: 2);

        try {
            $client->balance->get();
            self::fail('ServiceUnavailableException expected.');
        } catch (ServiceUnavailableException) {
            self::assertCount(3, $this->history, '1 attempt + 2 retries');
        }
    }

    public function test_max_retries_zero_disables_retries(): void
    {
        $client = $this->client([self::error(503, 'HTTP_ERROR')], maxRetries: 0);

        $this->expectException(ServiceUnavailableException::class);

        try {
            $client->balance->get();
        } finally {
            self::assertCount(1, $this->history);
        }
    }

    public function test_get_is_retried_on_network_errors(): void
    {
        $client = $this->client([self::networkError(), self::json(200, ['data' => ['balance' => 1, 'held' => 0]])]);

        self::assertSame(1, $client->balance->get()->balance);
        self::assertCount(2, $this->history);
    }

    public function test_estimate_is_retried(): void
    {
        $client = $this->client([self::networkError(), self::error(502, 'HTTP_ERROR'), self::json(200, ['data' => [
            'encoding' => 'gsm7', 'characters' => 5, 'segments_per_message' => 1, 'recipients_count' => 1, 'total_credits' => 1, 'balance_after' => 9,
        ]])]);

        self::assertSame(1, $client->sms->estimate('Hello', '0812345678')->totalCredits);
        self::assertCount(3, $this->history);
    }

    public function test_send_is_retried_with_the_same_idempotency_key(): void
    {
        $client = $this->client([
            self::networkError('cURL error 28: Operation timed out after 30000 milliseconds'),
            self::error(500, 'HTTP_ERROR'),
            self::json(202, ['data' => [self::message()]], ['Idempotent-Replayed' => 'true']),
        ]);

        $client->sms->send('0812345678', 'Hello');

        self::assertCount(3, $this->history);
        $keys = array_map(static fn (array $h): string => $h['request']->getHeaderLine('Idempotency-Key'), $this->history);
        self::assertNotSame('', $keys[0]);
        self::assertSame([$keys[0], $keys[0], $keys[0]], $keys, 'Every retry must reuse the same key.');
        self::assertSame($this->requestJson(0), $this->requestJson(2), 'Every retry must send the same body.');
    }

    public function test_429_too_many_requests_waits_for_retry_after(): void
    {
        $client = $this->client([
            self::error(429, 'TOO_MANY_REQUESTS', ['retry_after_seconds' => 3], ['Retry-After' => '3']),
            self::json(200, ['data' => ['balance' => 1, 'held' => 0]]),
        ]);

        $client->balance->get();

        self::assertSame([3.0], $this->sleeper->sleeps);
    }

    public function test_429_uses_details_retry_after_seconds_without_header(): void
    {
        $client = $this->client([
            self::error(429, 'TOO_MANY_REQUESTS', ['retry_after_seconds' => 4]),
            self::json(200, ['data' => ['balance' => 1, 'held' => 0]]),
        ]);

        $client->balance->get();

        self::assertSame([4.0], $this->sleeper->sleeps);
    }

    public function test_429_is_not_retried_when_the_wait_exceeds_the_maximum(): void
    {
        $client = $this->client([self::error(429, 'TOO_MANY_REQUESTS', [], ['Retry-After' => '45'])], maxRetryWait: 10.0);

        try {
            $client->balance->get();
            self::fail('RateLimitException expected.');
        } catch (RateLimitException $e) {
            self::assertSame(45, $e->getRetryAfter());
            self::assertCount(1, $this->history);
            self::assertSame([], $this->sleeper->sleeps);
        }
    }

    public function test_backoff_is_exponential_with_jitter(): void
    {
        $client = $this->client(array_fill(0, 4, self::error(503, 'HTTP_ERROR')), maxRetries: 3);

        try {
            $client->balance->get();
        } catch (ServiceUnavailableException) {
        }

        self::assertCount(3, $this->sleeper->sleeps);
        foreach ([0.5, 1.0, 2.0] as $i => $base) {
            self::assertGreaterThanOrEqual($base * 0.5, $this->sleeper->sleeps[$i]);
            self::assertLessThanOrEqual($base, $this->sleeper->sleeps[$i]);
        }
    }

    public function test_client_errors_are_never_retried(): void
    {
        $client = $this->client([
            self::error(422, 'VALIDATION_ERROR', ['body' => ['x']]),
            self::error(409, 'IDEMPOTENCY_KEY_REUSED'),
        ]);

        try {
            $client->sms->send('0812345678', 'Hello');
        } catch (ValidationException) {
        }
        try {
            $client->sms->send('0812345678', 'Hello');
        } catch (IdempotencyKeyReusedException) {
        }

        self::assertCount(2, $this->history);
        self::assertSame([], $this->sleeper->sleeps);
    }

    public function test_otp_send_is_never_retried_on_5xx(): void
    {
        $client = $this->client([self::error(500, 'HTTP_ERROR'), self::otpSent()]);

        $this->expectException(ServiceUnavailableException::class);

        try {
            $client->otp->send('0812345678');
        } finally {
            self::assertCount(1, $this->history);
        }
    }

    public function test_otp_send_is_never_retried_on_network_errors(): void
    {
        $client = $this->client([self::networkError('cURL error 28: Operation timed out'), self::otpSent()]);

        try {
            $client->otp->send('0812345678');
            self::fail('ConnectionException expected.');
        } catch (ConnectionException $e) {
            self::assertTrue($e->isTimeout());
            self::assertSame('TIMEOUT', $e->getErrorCode());
            self::assertStringContainsString('may have been processed', $e->getMessage());
            self::assertCount(1, $this->history);
        }
    }

    public function test_otp_send_is_retried_on_generic_429_only(): void
    {
        $client = $this->client([self::error(429, 'TOO_MANY_REQUESTS', [], ['Retry-After' => '1']), self::otpSent()]);

        self::assertSame('otp-1', $client->otp->send('0812345678')->otpId);
        self::assertCount(2, $this->history);
    }

    public function test_otp_business_limits_are_never_retried(): void
    {
        $client = $this->client([
            self::error(429, 'OTP_RESEND_TOO_SOON', ['retry_after_seconds' => 1]),
            self::error(429, 'OTP_RATE_LIMITED', ['scope' => 'account', 'limit' => 100]),
            self::otpSent(),
        ]);

        try {
            $client->otp->send('0812345678');
        } catch (OtpResendTooSoonException) {
        }
        try {
            $client->otp->send('0812345678');
        } catch (OtpRateLimitedException) {
        }

        self::assertCount(2, $this->history);
        self::assertSame([], $this->sleeper->sleeps);
    }

    public function test_otp_verify_is_never_retried_on_5xx_or_network_errors(): void
    {
        $client = $this->client([self::error(503, 'HTTP_ERROR'), self::networkError(), self::json(200, ['data' => ['verified' => true, 'reason' => null]])]);

        try {
            $client->otp->verify('otp-1', '123456');
        } catch (ServiceUnavailableException) {
        }
        try {
            $client->otp->verify('otp-1', '123456');
        } catch (ConnectionException) {
        }

        self::assertCount(2, $this->history);
    }

    public function test_otp_verify_is_retried_on_generic_429(): void
    {
        $client = $this->client([self::error(429, 'TOO_MANY_REQUESTS'), self::json(200, ['data' => ['verified' => true, 'reason' => null]])]);

        self::assertTrue($client->otp->verify('otp-1', '123456')->isValid());
        self::assertCount(2, $this->history);
    }

    public function test_network_errors_become_connection_exceptions_without_query_strings(): void
    {
        $client = $this->client([self::networkError('cURL error 6: Could not resolve host for https://api.test/api/public/v1/sms?recipient=243812345678')], maxRetries: 0);

        try {
            $client->sms->list(recipient: '0812345678');
            self::fail('ConnectionException expected.');
        } catch (ConnectionException $e) {
            self::assertFalse($e->isTimeout());
            self::assertSame('CONNECTION_ERROR', $e->getErrorCode());
            self::assertStringNotContainsString('243812345678', $e->getMessage());
        }
    }
}

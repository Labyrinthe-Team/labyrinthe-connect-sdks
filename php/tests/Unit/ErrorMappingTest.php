<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Labyrinthe\Connect\Enum\OtpRateLimitScope;
use Labyrinthe\Connect\Enum\RejectionReason;
use Labyrinthe\Connect\Exception\ApiException;
use Labyrinthe\Connect\Exception\AuthenticationException;
use Labyrinthe\Connect\Exception\IdempotencyKeyReusedException;
use Labyrinthe\Connect\Exception\InsufficientCreditsException;
use Labyrinthe\Connect\Exception\InvalidPhoneException;
use Labyrinthe\Connect\Exception\NotFoundException;
use Labyrinthe\Connect\Exception\OtpRateLimitedException;
use Labyrinthe\Connect\Exception\OtpResendTooSoonException;
use Labyrinthe\Connect\Exception\PermissionException;
use Labyrinthe\Connect\Exception\RateLimitException;
use Labyrinthe\Connect\Exception\RecipientNotSendableException;
use Labyrinthe\Connect\Exception\SenderIdNotApprovedException;
use Labyrinthe\Connect\Exception\ServiceUnavailableException;
use Labyrinthe\Connect\Exception\UnexpectedValueException;
use Labyrinthe\Connect\Exception\ValidationException;
use Labyrinthe\Connect\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ErrorMappingTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string, class-string<ApiException>}>
     */
    public static function errors(): iterable
    {
        yield 'unauthenticated' => [401, 'UNAUTHENTICATED', AuthenticationException::class];
        yield 'forbidden' => [403, 'FORBIDDEN', PermissionException::class];
        yield 'otp disabled for account' => [403, 'OTP_DISABLED_FOR_ACCOUNT', PermissionException::class];
        yield 'not found' => [404, 'NOT_FOUND', NotFoundException::class];
        yield 'validation' => [422, 'VALIDATION_ERROR', ValidationException::class];
        yield 'recipient not sendable' => [422, 'RECIPIENT_NOT_SENDABLE', RecipientNotSendableException::class];
        yield 'sender not approved' => [422, 'SENDER_ID_NOT_APPROVED', SenderIdNotApprovedException::class];
        yield 'invalid phone' => [422, 'INVALID_PHONE', InvalidPhoneException::class];
        yield 'invalid otp template' => [422, 'INVALID_OTP_TEMPLATE', ApiException::class];
        yield 'insufficient credits' => [409, 'INSUFFICIENT_CREDITS', InsufficientCreditsException::class];
        yield 'idempotency key reused' => [409, 'IDEMPOTENCY_KEY_REUSED', IdempotencyKeyReusedException::class];
        yield 'too many requests' => [429, 'TOO_MANY_REQUESTS', RateLimitException::class];
        yield 'otp resend too soon' => [429, 'OTP_RESEND_TOO_SOON', OtpResendTooSoonException::class];
        yield 'otp rate limited' => [429, 'OTP_RATE_LIMITED', OtpRateLimitedException::class];
        yield 'otp service disabled' => [503, 'OTP_SERVICE_DISABLED', ServiceUnavailableException::class];
        yield 'unknown code' => [418, 'BRAND_NEW_CODE', ApiException::class];
        yield 'unknown code on 401' => [401, 'KEY_EXPIRED', AuthenticationException::class];
        yield 'http error' => [405, 'HTTP_ERROR', ApiException::class];
        yield 'server error' => [500, 'HTTP_ERROR', ServiceUnavailableException::class];
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('errors')]
    public function test_each_error_code_maps_to_a_typed_exception(int $status, string $code, string $class): void
    {
        $client = $this->client([self::error($status, $code, ['some' => 'detail'])], maxRetries: 0);

        try {
            $client->balance->get();
            self::fail('An exception was expected.');
        } catch (ApiException $e) {
            self::assertSame($class, $e::class);
            self::assertSame($code, $e->getErrorCode());
            self::assertSame($status, $e->getStatusCode());
            self::assertSame('Message en français.', $e->getMessage());
            self::assertSame(['some' => 'detail'], $e->getDetails());
        }
    }

    public function test_validation_errors_are_exposed_per_field(): void
    {
        $client = $this->client([self::error(422, 'VALIDATION_ERROR', ['body' => ['Le champ body est obligatoire.'], 'recipients.0' => ['Invalide.']])]);

        try {
            $client->sms->estimate('x', ['0812345678']);
            self::fail('ValidationException expected.');
        } catch (ValidationException $e) {
            self::assertSame(['body' => ['Le champ body est obligatoire.'], 'recipients.0' => ['Invalide.']], $e->getErrors());
            self::assertSame(['Le champ body est obligatoire.'], $e->getFieldErrors('body'));
            self::assertSame([], $e->getFieldErrors('sender'));
        }
    }

    public function test_recipient_not_sendable_lists_every_rejected_number(): void
    {
        $client = $this->client([self::error(422, 'RECIPIENT_NOT_SENDABLE', [
            'reason' => 'blacklisted',
            'rejected' => [
                ['recipient' => '243812345678', 'reason' => 'blacklisted'],
                ['recipient' => '243899999999', 'reason' => 'opted_out'],
            ],
        ])]);

        try {
            $client->sms->sendMany(['0812345678', '0899999999'], 'Hello');
            self::fail('RecipientNotSendableException expected.');
        } catch (RecipientNotSendableException $e) {
            self::assertSame(RejectionReason::Blacklisted, $e->getReason());
            self::assertCount(2, $e->getRejected());
            self::assertSame('243899999999', $e->getRejected()[1]->recipient);
            self::assertSame(RejectionReason::OptedOut, $e->getRejected()[1]->reason);
        }
    }

    public function test_insufficient_credits_exposes_available_and_required(): void
    {
        $client = $this->client([self::error(409, 'INSUFFICIENT_CREDITS', ['available' => 3, 'required' => 10])]);

        try {
            $client->sms->send('0812345678', 'Hello');
            self::fail('InsufficientCreditsException expected.');
        } catch (InsufficientCreditsException $e) {
            self::assertSame(3, $e->getAvailable());
            self::assertSame(10, $e->getRequired());
        }
    }

    public function test_rate_limit_reads_retry_after_from_the_header_first(): void
    {
        $client = $this->client([self::error(429, 'TOO_MANY_REQUESTS', ['retry_after_seconds' => 5], ['Retry-After' => '42'])], maxRetries: 0);

        try {
            $client->balance->get();
            self::fail('RateLimitException expected.');
        } catch (RateLimitException $e) {
            self::assertSame(42, $e->getRetryAfter());
        }
    }

    public function test_otp_specific_429_details(): void
    {
        $client = $this->client([
            self::error(429, 'OTP_RESEND_TOO_SOON', ['retry_after_seconds' => 37]),
            self::error(429, 'OTP_RATE_LIMITED', ['scope' => 'phone', 'limit' => 5]),
        ]);

        try {
            $client->otp->send('0812345678');
            self::fail('OtpResendTooSoonException expected.');
        } catch (OtpResendTooSoonException $e) {
            self::assertSame(37, $e->getRetryAfter());
        }

        try {
            $client->otp->send('0812345678');
            self::fail('OtpRateLimitedException expected.');
        } catch (OtpRateLimitedException $e) {
            self::assertSame(OtpRateLimitScope::Phone, $e->getScope());
            self::assertSame(5, $e->getLimit());
        }
    }

    public function test_empty_details_sent_as_a_json_array_are_accepted(): void
    {
        $client = $this->client([self::json(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'Message introuvable.', 'details' => []]])]);

        try {
            $client->sms->get('missing');
            self::fail('NotFoundException expected.');
        } catch (NotFoundException $e) {
            self::assertSame([], $e->getDetails());
        }
    }

    public function test_non_json_error_bodies_still_give_a_typed_exception(): void
    {
        $client = $this->client([new Response(502, ['Content-Type' => 'text/html'], '<html>Bad gateway</html>')], maxRetries: 0);

        try {
            $client->balance->get();
            self::fail('ServiceUnavailableException expected.');
        } catch (ServiceUnavailableException $e) {
            self::assertSame('HTTP_ERROR', $e->getErrorCode());
            self::assertSame(502, $e->getStatusCode());
        }
    }

    public function test_request_id_is_exposed_when_present(): void
    {
        $client = $this->client([self::error(404, 'NOT_FOUND', [], ['X-Request-Id' => 'req_123'])]);

        try {
            $client->sms->get('missing');
            self::fail('NotFoundException expected.');
        } catch (NotFoundException $e) {
            self::assertSame('req_123', $e->getRequestId());
        }
    }

    public function test_an_unreadable_success_body_is_reported(): void
    {
        $client = $this->client([new Response(200, [], 'not json')]);

        $this->expectException(UnexpectedValueException::class);
        $client->balance->get();
    }

    public function test_a_success_body_missing_required_fields_is_reported(): void
    {
        $client = $this->client([self::json(200, ['data' => ['held' => 0]])]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('"balance"');
        $client->balance->get();
    }
}

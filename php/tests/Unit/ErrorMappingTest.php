<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Labyrinthe\Connect\Enum\OtpRateLimitScope;
use Labyrinthe\Connect\Enum\RejectionReason;
use Labyrinthe\Connect\Exception\AccountSuspendedException;
use Labyrinthe\Connect\Exception\ApiException;
use Labyrinthe\Connect\Exception\AuthenticationException;
use Labyrinthe\Connect\Exception\FeatureDisabledException;
use Labyrinthe\Connect\Exception\IdempotencyKeyReusedException;
use Labyrinthe\Connect\Exception\IdempotencyKeyUnresolvedException;
use Labyrinthe\Connect\Exception\IdempotencyRequestInProgressException;
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
        yield 'account suspended' => [403, 'ACCOUNT_SUSPENDED', AccountSuspendedException::class];
        yield 'not found' => [404, 'NOT_FOUND', NotFoundException::class];
        yield 'validation' => [422, 'VALIDATION_ERROR', ValidationException::class];
        yield 'recipient not sendable' => [422, 'RECIPIENT_NOT_SENDABLE', RecipientNotSendableException::class];
        yield 'sender not approved' => [422, 'SENDER_ID_NOT_APPROVED', SenderIdNotApprovedException::class];
        yield 'invalid phone' => [422, 'INVALID_PHONE', InvalidPhoneException::class];
        yield 'invalid otp template' => [422, 'INVALID_OTP_TEMPLATE', ApiException::class];
        yield 'insufficient credits' => [409, 'INSUFFICIENT_CREDITS', InsufficientCreditsException::class];
        yield 'idempotency key reused' => [409, 'IDEMPOTENCY_KEY_REUSED', IdempotencyKeyReusedException::class];
        yield 'idempotency key unresolved' => [409, 'IDEMPOTENCY_KEY_UNRESOLVED', IdempotencyKeyUnresolvedException::class];
        yield 'idempotency request in progress' => [409, 'IDEMPOTENCY_REQUEST_IN_PROGRESS', IdempotencyRequestInProgressException::class];
        yield 'too many requests' => [429, 'TOO_MANY_REQUESTS', RateLimitException::class];
        yield 'otp resend too soon' => [429, 'OTP_RESEND_TOO_SOON', OtpResendTooSoonException::class];
        yield 'otp rate limited' => [429, 'OTP_RATE_LIMITED', OtpRateLimitedException::class];
        yield 'otp service disabled' => [503, 'OTP_SERVICE_DISABLED', FeatureDisabledException::class];
        yield 'feature disabled' => [503, 'FEATURE_DISABLED', FeatureDisabledException::class];
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

    public function test_feature_disabled_exposes_its_details(): void
    {
        $client = $this->client([self::error(503, 'FEATURE_DISABLED', [
            'feature' => 'api.sms_send',
            'blocked_by' => 'api',
            'message' => "Maintenance de l'envoi de SMS jusqu'à 10 h.",
            'until' => '2026-11-06T10:00:00+00:00',
        ], ['Retry-After' => '3600'])], maxRetries: 0);

        try {
            $client->balance->get();
            self::fail('FeatureDisabledException expected.');
        } catch (FeatureDisabledException $e) {
            self::assertSame('api.sms_send', $e->getFeature());
            self::assertSame('api', $e->getBlockedBy());
            self::assertSame("Maintenance de l'envoi de SMS jusqu'à 10 h.", $e->getAdminMessage());
            self::assertEquals(new \DateTimeImmutable('2026-11-06T10:00:00+00:00'), $e->getUntil());
            self::assertSame(3600, $e->getRetryAfter());
        }
    }

    public function test_otp_service_disabled_exposes_the_same_details(): void
    {
        $client = $this->client([self::error(503, 'OTP_SERVICE_DISABLED', [
            'feature' => 'otp', 'blocked_by' => 'otp', 'message' => null, 'until' => null,
        ])], maxRetries: 0);

        try {
            $client->otp->send('0812345678');
            self::fail('FeatureDisabledException expected.');
        } catch (ServiceUnavailableException $e) {
            // Code written for 1.1 (catch ServiceUnavailableException) keeps working.
            self::assertInstanceOf(FeatureDisabledException::class, $e);
            self::assertSame('OTP_SERVICE_DISABLED', $e->getErrorCode());
            self::assertSame('otp', $e->getFeature());
            self::assertSame('otp', $e->getBlockedBy());
            self::assertNull($e->getAdminMessage());
            self::assertNull($e->getUntil());
            self::assertNull($e->getRetryAfter());
        }
    }

    /**
     * @return iterable<string, array{array<mixed>, string|null}>
     */
    public static function malformedFeatureDetails(): iterable
    {
        yield 'missing' => [[], null];
        yield 'wrong types' => [['feature' => 42, 'blocked_by' => ['api'], 'message' => false, 'until' => 1_700_000_000], null];
        yield 'invalid until' => [['feature' => 'api', 'blocked_by' => 'api', 'message' => '', 'until' => 'pas une date'], 'api'];
    }

    /**
     * @param array<mixed> $details
     */
    #[DataProvider('malformedFeatureDetails')]
    public function test_feature_disabled_tolerates_missing_or_malformed_details(array $details, ?string $feature): void
    {
        $client = $this->client([self::error(503, 'FEATURE_DISABLED', $details, ['Retry-After' => 'soon'])], maxRetries: 0);

        try {
            $client->balance->get();
            self::fail('FeatureDisabledException expected.');
        } catch (FeatureDisabledException $e) {
            self::assertSame($feature, $e->getFeature());
            self::assertSame($feature, $e->getBlockedBy());
            self::assertNull($e->getAdminMessage());
            self::assertNull($e->getUntil());
            self::assertNull($e->getRetryAfter());
            self::assertSame('Message en français.', $e->getMessage());
        }
    }

    public function test_feature_disabled_without_a_details_object_is_still_typed(): void
    {
        $client = $this->client([new Response(503, ['Content-Type' => 'application/json'], '{"error":{"code":"FEATURE_DISABLED","message":"Maintenance."}}')], maxRetries: 0);

        try {
            $client->balance->get();
            self::fail('FeatureDisabledException expected.');
        } catch (FeatureDisabledException $e) {
            self::assertSame([], $e->getDetails());
            self::assertNull($e->getFeature());
            self::assertNull($e->getBlockedBy());
            self::assertNull($e->getUntil());
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

    public function test_request_id_and_api_version_are_exposed_on_every_exception(): void
    {
        $headers = ['X-Request-Id' => '5f0c2b9e-1d3a-4e7f-8b6c-9a0d2e4f6b81', 'X-Api-Version' => '1'];
        $client = $this->client([
            self::error(422, 'VALIDATION_ERROR', ['body' => ['x']], $headers),
            self::error(403, 'ACCOUNT_SUSPENDED', [], $headers),
            self::error(418, 'BRAND_NEW_CODE', [], $headers),
        ], maxRetries: 0);

        foreach ([
            static fn () => $client->sms->estimate('x', '0812345678'),
            static fn () => $client->balance->get(),
            static fn () => $client->senderIds->list(),
        ] as $call) {
            try {
                $call();
                self::fail('ApiException expected.');
            } catch (ApiException $e) {
                self::assertSame('5f0c2b9e-1d3a-4e7f-8b6c-9a0d2e4f6b81', $e->getRequestId());
                self::assertSame('1', $e->getApiVersion());
            }
        }
    }

    public function test_account_suspended_is_still_a_permission_exception(): void
    {
        $client = $this->client([self::error(403, 'ACCOUNT_SUSPENDED')], maxRetries: 2);

        try {
            $client->balance->get();
            self::fail('PermissionException expected.');
        } catch (PermissionException $e) {
            self::assertInstanceOf(AccountSuspendedException::class, $e);
            self::assertCount(1, $this->history, 'Never retried.');
        }
    }

    public function test_otp_rate_limited_reads_retry_after_from_the_header_or_the_details(): void
    {
        $client = $this->client([
            self::error(429, 'OTP_RATE_LIMITED', ['retry_after_seconds' => 1800, 'scope' => 'phone', 'limit' => 5], ['Retry-After' => '1800']),
            self::error(429, 'OTP_RATE_LIMITED', ['retry_after_seconds' => 900, 'scope' => 'account', 'limit' => 100]),
            self::error(429, 'OTP_RESEND_TOO_SOON', ['retry_after_seconds' => 42], ['Retry-After' => '42']),
        ]);

        foreach ([1800, 900] as $expected) {
            try {
                $client->otp->send('0812345678');
                self::fail('OtpRateLimitedException expected.');
            } catch (OtpRateLimitedException $e) {
                self::assertSame($expected, $e->getRetryAfter());
            }
        }

        try {
            $client->otp->send('0812345678');
            self::fail('OtpResendTooSoonException expected.');
        } catch (OtpResendTooSoonException $e) {
            self::assertSame(42, $e->getRetryAfter());
        }
        self::assertSame([], $this->sleeper->sleeps, 'OTP business limits are never retried.');
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

<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Unit;

use Labyrinthe\Connect\Enum\OtpCodeType;
use Labyrinthe\Connect\Enum\OtpFailureReason;
use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class OtpServiceTest extends TestCase
{
    public function test_send_posts_the_normalised_phone_and_options(): void
    {
        $client = $this->client([self::json(201, ['data' => self::otpSendData([
            'otp_id' => '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10',
            'code_length' => 8,
            'code_type' => 'alphanumeric',
            'resend_after_seconds' => 45,
        ])])]);

        $otp = $client->otp->send('0812345678', template: 'Code MaBoutique : {code}', locale: 'fr');

        self::assertSame('/api/public/v1/otp/send', $this->request()->getUri()->getPath());
        self::assertSame(['phone' => '+243812345678', 'template' => 'Code MaBoutique : {code}', 'locale' => 'fr'], $this->requestJson());
        self::assertSame('9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10', $otp->otpId);
        self::assertSame(3, $otp->maxAttempts);
        self::assertSame('2026-11-05T10:05:00+00:00', $otp->expiresAt->format(\DATE_ATOM));
        self::assertSame('482913', $otp->testCode());
        self::assertSame(8, $otp->codeLength);
        self::assertSame(OtpCodeType::Alphanumeric, $otp->codeType);
        self::assertSame(45, $otp->resendAfterSeconds);
        self::assertFalse($otp->replayed);
        self::assertSame(
            '2026-11-05T10:00:45+00:00',
            $otp->resendAvailableAt(new \DateTimeImmutable('2026-11-05T10:00:00+00:00'))->format(\DATE_ATOM),
        );
    }

    public function test_send_always_sends_a_generated_uuid_v4_idempotency_key(): void
    {
        $client = $this->client([self::json(201, ['data' => self::otpSendData()])]);

        $otp = $client->otp->send('0812345678');

        $key = $this->request()->getHeaderLine('Idempotency-Key');
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $key);
        self::assertSame($key, $otp->idempotencyKey);
    }

    public function test_send_uses_the_given_idempotency_key_and_reports_replays(): void
    {
        $client = $this->client([self::json(201, ['data' => self::otpSendData()], ['Idempotent-Replayed' => 'true'])]);

        $otp = $client->otp->send('0812345678', idempotencyKey: 'signup-42');

        self::assertSame('signup-42', $this->request()->getHeaderLine('Idempotency-Key'));
        self::assertSame('signup-42', $otp->idempotencyKey);
        self::assertTrue($otp->replayed);
    }

    public function test_send_passes_web_otp_domain_lower_cased_and_strip_accents(): void
    {
        $client = $this->client([self::json(201, ['data' => self::otpSendData()])]);

        $client->otp->send('+33612345678', webOtpDomain: ' App.Exemple.com ', stripAccents: true);

        self::assertSame(['phone' => '+33612345678', 'web_otp_domain' => 'app.exemple.com', 'strip_accents' => true], $this->requestJson());
    }

    public function test_a_short_foreign_number_is_sent_with_plus(): void
    {
        $client = $this->client([self::json(201, ['data' => self::otpSendData()])]);

        $client->otp->send('+2481234567');

        self::assertSame(['phone' => '+2481234567'], $this->requestJson());
    }

    public function test_strip_accents_is_not_sent_when_false(): void
    {
        $client = $this->client([self::json(201, ['data' => self::otpSendData()])]);

        $client->otp->send('0812345678', stripAccents: false);

        self::assertSame(['phone' => '+243812345678'], $this->requestJson());
    }

    public function test_an_unknown_code_type_does_not_break_parsing(): void
    {
        $client = $this->client([self::json(201, ['data' => self::otpSendData(['code_type' => 'emoji'])])]);

        self::assertSame(OtpCodeType::Unknown, $client->otp->send('0812345678')->codeType);
    }

    public function test_live_responses_have_no_test_code_and_z_dates_are_accepted(): void
    {
        $data = self::otpSendData(['expires_at' => '2026-11-05T10:05:00.000000Z']);
        unset($data['test_code']);
        $client = $this->client([self::json(201, ['data' => $data])]);

        $otp = $client->otp->send('243812345678');

        self::assertNull($otp->testCode());
        self::assertSame('2026-11-05T10:05:00+00:00', $otp->expiresAt->format(\DATE_ATOM));
    }

    public function test_the_json_form_has_the_code_shape_but_never_the_test_code(): void
    {
        $client = $this->client([self::json(201, ['data' => self::otpSendData()])]);

        self::assertSame([
            'otp_id' => 'otp-1',
            'expires_at' => '2026-11-05T10:05:00+00:00',
            'max_attempts' => 3,
            'code_length' => 6,
            'code_type' => 'numeric',
            'resend_after_seconds' => 60,
        ], $client->otp->send('0812345678')->jsonSerialize());
    }

    public function test_send_validates_input_before_any_call(): void
    {
        $client = $this->client([]);
        foreach ([
            static fn () => $client->otp->send('abc'),
            static fn () => $client->otp->send('0812345678', template: 'No token here'),
            static fn () => $client->otp->send('0812345678', locale: 'ln'),
            static fn () => $client->otp->send('0812345678', webOtpDomain: 'https://app.example.com'),
            static fn () => $client->otp->send('0812345678', webOtpDomain: 'localhost'),
            static fn () => $client->otp->send('0812345678', webOtpDomain: 'app.example.com:8443'),
            static fn () => $client->otp->send('0812345678', webOtpDomain: str_repeat('a', 250).'.com'),
            static fn () => $client->otp->send('0812345678', idempotencyKey: ''),
            static fn () => $client->otp->send('0812345678', idempotencyKey: str_repeat('k', 256)),
            static fn () => $client->otp->send('0812345678', requestId: 'not valid!'),
            static fn () => $client->otp->verify('', '123456'),
            static fn () => $client->otp->verify('otp', ''),
            static fn () => $client->otp->verify('otp', str_repeat('1', 21)),
            static fn () => $client->otp->verify('otp', '123456', requestId: str_repeat('r', 129)),
        ] as $i => $case) {
            try {
                $case();
                self::fail(\sprintf('Case #%d should have been refused.', $i));
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->history);
    }

    public function test_verify_success(): void
    {
        $client = $this->client([self::json(200, ['data' => ['verified' => true, 'reason' => null, 'attempts_remaining' => null]])]);

        $result = $client->otp->verify('otp-1', ' 482 913 ');

        self::assertSame(['otp_id' => 'otp-1', 'code' => ' 482 913 '], $this->requestJson(), 'The API normalises the code itself.');
        self::assertFalse($this->request()->hasHeader('Idempotency-Key'));
        self::assertTrue($result->isValid());
        self::assertNull($result->reason);
        self::assertNull($result->attemptsRemaining);
    }

    /**
     * @return iterable<string, array{string, int|null, OtpFailureReason, bool}>
     */
    public static function failures(): iterable
    {
        yield 'invalid code' => ['invalid_code', 2, OtpFailureReason::InvalidCode, true];
        yield 'expired' => ['expired', null, OtpFailureReason::Expired, false];
        yield 'already used' => ['already_used', null, OtpFailureReason::AlreadyUsed, false];
        yield 'max attempts' => ['max_attempts_reached', 0, OtpFailureReason::MaxAttemptsReached, false];
        yield 'future reason' => ['locked_by_fraud', null, OtpFailureReason::Unknown, false];
    }

    #[DataProvider('failures')]
    public function test_verify_failures_are_results_not_exceptions(string $reason, ?int $remaining, OtpFailureReason $expected, bool $canRetry): void
    {
        $client = $this->client([self::json(200, ['data' => ['verified' => false, 'reason' => $reason, 'attempts_remaining' => $remaining]])]);

        $result = $client->otp->verify('otp-1', '000000');

        self::assertFalse($result->isValid());
        self::assertSame($expected, $result->reason);
        self::assertSame($canRetry, $result->reason->canRetry());
        self::assertSame($remaining, $result->attemptsRemaining);
    }

    public function test_verify_tolerates_a_missing_attempts_remaining(): void
    {
        $client = $this->client([self::json(200, ['data' => ['verified' => false, 'reason' => 'invalid_code']])]);

        self::assertNull($client->otp->verify('otp-1', '000000')->attemptsRemaining);
    }
}

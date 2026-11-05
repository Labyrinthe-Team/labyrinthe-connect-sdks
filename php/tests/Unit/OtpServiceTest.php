<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Unit;

use Labyrinthe\Connect\Enum\OtpFailureReason;
use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class OtpServiceTest extends TestCase
{
    public function test_send_posts_the_normalised_phone_and_options(): void
    {
        $client = $this->client([self::json(201, ['data' => [
            'otp_id' => '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10',
            'expires_at' => '2026-11-05T10:05:00+00:00',
            'max_attempts' => 3,
            'test_code' => '482913',
        ]])]);

        $otp = $client->otp->send('0812345678', template: 'Code MaBoutique : {code}', locale: 'fr');

        self::assertSame('/api/public/v1/otp/send', $this->request()->getUri()->getPath());
        self::assertSame(['phone' => '243812345678', 'template' => 'Code MaBoutique : {code}', 'locale' => 'fr'], $this->requestJson());
        self::assertSame('9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10', $otp->otpId);
        self::assertSame(3, $otp->maxAttempts);
        self::assertSame('2026-11-05T10:05:00+00:00', $otp->expiresAt->format(\DATE_ATOM));
        self::assertSame('482913', $otp->testCode());
        self::assertFalse($this->request()->hasHeader('Idempotency-Key'));
    }

    public function test_live_responses_have_no_test_code(): void
    {
        $client = $this->client([self::json(201, ['data' => ['otp_id' => 'x', 'expires_at' => '2026-11-05T10:05:00Z', 'max_attempts' => 5]])]);

        self::assertNull($client->otp->send('243812345678')->testCode());
    }

    public function test_send_validates_input_before_any_call(): void
    {
        $client = $this->client([]);
        foreach ([
            static fn () => $client->otp->send('abc'),
            static fn () => $client->otp->send('0812345678', template: 'No token here'),
            static fn () => $client->otp->send('0812345678', locale: 'ln'),
            static fn () => $client->otp->verify('', '123456'),
            static fn () => $client->otp->verify('otp', ''),
            static fn () => $client->otp->verify('otp', str_repeat('1', 21)),
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
        $client = $this->client([self::json(200, ['data' => ['verified' => true, 'reason' => null]])]);

        $result = $client->otp->verify('otp-1', ' 482 913 ');

        self::assertSame(['otp_id' => 'otp-1', 'code' => ' 482 913 '], $this->requestJson(), 'The API normalises the code itself.');
        self::assertTrue($result->isValid());
        self::assertNull($result->reason);
    }

    /**
     * @return iterable<string, array{string, OtpFailureReason, bool}>
     */
    public static function failures(): iterable
    {
        yield 'invalid code' => ['invalid_code', OtpFailureReason::InvalidCode, true];
        yield 'expired' => ['expired', OtpFailureReason::Expired, false];
        yield 'already used' => ['already_used', OtpFailureReason::AlreadyUsed, false];
        yield 'max attempts' => ['max_attempts_reached', OtpFailureReason::MaxAttemptsReached, false];
        yield 'future reason' => ['locked_by_fraud', OtpFailureReason::Unknown, false];
    }

    #[DataProvider('failures')]
    public function test_verify_failures_are_results_not_exceptions(string $reason, OtpFailureReason $expected, bool $canRetry): void
    {
        $client = $this->client([self::json(200, ['data' => ['verified' => false, 'reason' => $reason]])]);

        $result = $client->otp->verify('otp-1', '000000');

        self::assertFalse($result->isValid());
        self::assertSame($expected, $result->reason);
        self::assertSame($canRetry, $result->reason->canRetry());
    }
}

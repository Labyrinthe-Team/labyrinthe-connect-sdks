<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Integration;

use Labyrinthe\Connect\Client;
use Labyrinthe\Connect\Enum\Encoding;
use Labyrinthe\Connect\Enum\MessageStatus;
use Labyrinthe\Connect\Enum\OtpFailureReason;
use Labyrinthe\Connect\Exception\AuthenticationException;
use Labyrinthe\Connect\Exception\IdempotencyKeyReusedException;
use Labyrinthe\Connect\Exception\NotFoundException;
use Labyrinthe\Connect\Exception\SenderIdNotApprovedException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a real backend with a TEST key (nothing is sent, nothing is billed):
 *
 *     LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 composer test:integration
 *
 * Skipped when LABYRINTHE_API_KEY is not set. Refuses to run with a live key.
 */
#[Group('integration')]
final class IntegrationTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $key = getenv('LABYRINTHE_API_KEY');
        if (!\is_string($key) || $key === '') {
            self::markTestSkipped('LABYRINTHE_API_KEY is not set.');
        }
        if (!str_starts_with($key, 'lsk_test_')) {
            self::markTestSkipped('The integration suite only runs with a test key (lsk_test_…).');
        }

        $baseUrl = getenv('LABYRINTHE_BASE_URL');
        $this->client = new Client($key, baseUrl: \is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : 'http://localhost:8000');
    }

    /** A random DRC number, so per-number OTP limits never collide between runs. */
    private static function randomPhone(): string
    {
        return '0899'.str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);
    }

    private static function reference(): string
    {
        return 'sdk-php-it-'.bin2hex(random_bytes(6));
    }

    public function test_balance(): void
    {
        $balance = $this->client->balance->get();

        self::assertTrue($this->client->isTestMode());
        self::assertGreaterThanOrEqual(0, $balance->held);
    }

    public function test_estimate(): void
    {
        $estimate = $this->client->sms->estimate('Hello from the PHP SDK', ['0812345678', 'not-a-number']);

        self::assertSame(Encoding::Gsm7, $estimate->encoding);
        self::assertSame(1, $estimate->segmentsPerMessage);
        self::assertSame(1, $estimate->recipientsCount, 'Invalid numbers are ignored.');
        self::assertSame(1, $estimate->totalCredits);

        self::assertSame(Encoding::Ucs2, $this->client->sms->estimate('Réunion à 9h', '0812345678')->encoding);
    }

    public function test_send_with_reference_and_idempotency_then_list_and_get(): void
    {
        $reference = self::reference();
        $key = 'sdk-php-it-'.bin2hex(random_bytes(8));
        $phone = self::randomPhone();

        $message = $this->client->sms->send(to: $phone, body: 'Integration test', reference: $reference, idempotencyKey: $key);

        self::assertSame($reference, $message->reference);
        self::assertSame('243'.substr($phone, 1), $message->recipient);
        self::assertSame(1, $message->segments);
        self::assertNotSame(MessageStatus::Unknown, $message->status);

        // Same key, same content: replayed, nothing sent again.
        $replay = $this->client->sms->sendMany([$phone], 'Integration test', reference: $reference, idempotencyKey: $key);
        self::assertTrue($replay->replayed);
        self::assertSame($message->id, $replay->first()?->id);

        // Same key, other content: refused.
        try {
            $this->client->sms->send(to: $phone, body: 'Another body', reference: $reference, idempotencyKey: $key);
            self::fail('IdempotencyKeyReusedException expected.');
        } catch (IdempotencyKeyReusedException $e) {
            self::assertSame(409, $e->getStatusCode());
        }

        $page = $this->client->sms->list(reference: $reference);
        self::assertSame(1, $page->total);
        self::assertSame($message->id, $page->data[0]->id);

        $byRecipient = $this->client->sms->list(recipient: $phone, reference: $reference, perPage: 5);
        self::assertSame(1, $byRecipient->total);

        self::assertSame($message->id, $this->client->sms->get($message->id)->id);
    }

    public function test_a_refused_multi_send_sends_nothing(): void
    {
        $reference = self::reference();

        try {
            $this->client->sms->sendMany([self::randomPhone(), self::randomPhone()], 'Never sent', sender: 'NOTAPPROVED', reference: $reference);
            self::fail('SenderIdNotApprovedException expected.');
        } catch (SenderIdNotApprovedException $e) {
            self::assertSame('SENDER_ID_NOT_APPROVED', $e->getErrorCode());
        }

        self::assertSame(0, $this->client->sms->list(reference: $reference)->total);
    }

    public function test_otp_send_verify_and_single_use(): void
    {
        $otp = $this->client->otp->send(self::randomPhone(), locale: 'en');

        self::assertNotSame('', $otp->otpId);
        self::assertGreaterThan(new \DateTimeImmutable(), $otp->expiresAt);
        $code = $otp->testCode();
        self::assertNotNull($code, 'A test key returns test_code.');

        $wrong = $this->client->otp->verify($otp->otpId, $code === '000000' ? '111111' : '000000');
        self::assertFalse($wrong->isValid());
        self::assertSame(OtpFailureReason::InvalidCode, $wrong->reason);

        self::assertTrue($this->client->otp->verify($otp->otpId, $code)->isValid());

        $again = $this->client->otp->verify($otp->otpId, $code);
        self::assertFalse($again->isValid());
        self::assertSame(OtpFailureReason::AlreadyUsed, $again->reason);
    }

    public function test_unknown_message_and_otp_give_not_found(): void
    {
        try {
            $this->client->sms->get('00000000-0000-4000-8000-000000000000');
            self::fail('NotFoundException expected.');
        } catch (NotFoundException $e) {
            self::assertSame('NOT_FOUND', $e->getErrorCode());
        }

        $this->expectException(NotFoundException::class);
        $this->client->otp->verify('00000000-0000-4000-8000-000000000000', '123456');
    }

    public function test_an_invalid_key_is_rejected(): void
    {
        $client = new Client('lsk_test_invalid', baseUrl: $this->client->baseUrl());

        $this->expectException(AuthenticationException::class);
        $client->balance->get();
    }
}

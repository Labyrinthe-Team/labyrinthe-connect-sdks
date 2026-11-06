<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Tests\Feature;

use Labyrinthe\Connect\Client;
use Labyrinthe\Connect\Enum\Encoding;
use Labyrinthe\Connect\Enum\OtpCodeType;
use Labyrinthe\Connect\Enum\OtpFailureReason;
use Labyrinthe\Connect\Exception\IdempotencyKeyReusedException;
use Labyrinthe\Connect\Exception\IdempotencyKeyUnresolvedException;
use Labyrinthe\Connect\Exception\InsufficientCreditsException;
use Labyrinthe\Connect\Exception\SenderIdNotApprovedException;
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;
use Labyrinthe\Connect\Laravel\LabyrintheManager;
use Labyrinthe\Connect\Laravel\Testing\SentOtp;
use Labyrinthe\Connect\Laravel\Testing\SentSms;
use Labyrinthe\Connect\Laravel\Tests\TestCase;
use Labyrinthe\Connect\Model\Message;
use Labyrinthe\Connect\Model\MessageBatch;
use PHPUnit\Framework\AssertionFailedError;

final class FakeTest extends TestCase
{
    public function test_the_fake_replaces_the_client_everywhere(): void
    {
        $fake = Labyrinthe::fake();

        self::assertSame($fake->client(), app()->make(Client::class));
        self::assertSame($fake, app()->make(LabyrintheManager::class));
        self::assertTrue(Labyrinthe::isTestMode());

        // Code that injected the Client directly is covered too.
        app()->make(Client::class)->sms->send('0812345678', 'Injected');
        Labyrinthe::assertSmsSentTo('243812345678');
    }

    public function test_send_shortcut_and_assertions(): void
    {
        Labyrinthe::fake();

        $message = Labyrinthe::send('0812345678', 'Hello', reference: 'r-1');
        $batch = Labyrinthe::send(['0812345678', '0899999999', '0812345678'], 'Hi all');

        self::assertInstanceOf(Message::class, $message);
        self::assertSame('r-1', $message->reference);
        self::assertInstanceOf(MessageBatch::class, $batch);
        self::assertCount(2, $batch, 'Duplicates are merged like the API does.');

        Labyrinthe::assertSmsSent();
        Labyrinthe::assertSmsSent(static fn (SentSms $sms): bool => $sms->reference === 'r-1');
        Labyrinthe::assertSmsSentTo('+243 899 999 999', static fn (SentSms $sms): bool => $sms->body === 'Hi all');
        Labyrinthe::assertSmsSentCount(2);
        Labyrinthe::assertNoOtpSent();

        self::assertSame($message->id, Labyrinthe::sms()->get($message->id)->id);
        self::assertSame(1, Labyrinthe::sms()->list(reference: 'r-1')->total);
    }

    public function test_failing_assertions_fail(): void
    {
        Labyrinthe::fake();

        foreach ([
            static fn () => Labyrinthe::assertSmsSent(),
            static fn () => Labyrinthe::assertSmsSentTo('0812345678'),
            static fn () => Labyrinthe::assertOtpSent(),
            static fn () => Labyrinthe::assertSmsSentCount(1),
        ] as $i => $assertion) {
            try {
                $assertion();
                self::fail(\sprintf('Assertion #%d should have failed.', $i));
            } catch (AssertionFailedError $e) {
                self::assertStringNotContainsString('Assertion #', $e->getMessage());
            }
        }

        Labyrinthe::send('0812345678', 'x');
        $this->expectException(AssertionFailedError::class);
        Labyrinthe::assertNothingSent();
    }

    public function test_otp_flow_with_the_fake(): void
    {
        $fake = Labyrinthe::fake();

        $otp = Labyrinthe::otp()->send('0812345678');
        $code = $fake->otpCode('+243812345678');

        self::assertNotNull($code);
        self::assertSame($code, $otp->testCode());
        Labyrinthe::assertOtpSent('0812345678');
        Labyrinthe::assertOtpSent(static fn (SentOtp $o): bool => $o->phone === '243812345678');

        self::assertFalse(Labyrinthe::otp()->check($otp->otpId, 'wrong'));
        self::assertTrue(Labyrinthe::otp()->check($otp->otpId, $code));
        $again = Labyrinthe::otp()->verify($otp->otpId, $code);
        self::assertSame(OtpFailureReason::AlreadyUsed, $again->reason);

        self::assertFalse(Labyrinthe::otp()->check('unknown-id', $code));
        self::assertFalse(Labyrinthe::otp()->check(null, $code));
    }

    public function test_a_new_otp_invalidates_the_previous_one_and_attempts_are_capped(): void
    {
        $fake = Labyrinthe::fake()->withOtpCode('424242');

        $first = Labyrinthe::otp()->send('0812345678');
        $second = Labyrinthe::otp()->send('0812345678');

        self::assertSame(OtpFailureReason::AlreadyUsed, Labyrinthe::otp()->verify($first->otpId, '424242')->reason);

        foreach ([1, 2, 3] as $_) {
            Labyrinthe::otp()->verify($second->otpId, '000000');
        }
        self::assertSame(OtpFailureReason::MaxAttemptsReached, Labyrinthe::otp()->verify($second->otpId, '424242')->reason);
        self::assertCount(2, $fake->otps());
    }

    public function test_otp_defaults_come_from_config(): void
    {
        config()->set('labyrinthe.otp.template', 'Code: {code}');
        config()->set('labyrinthe.otp.locale', 'fr');
        $fake = Labyrinthe::fake();

        Labyrinthe::otp()->send('0812345678');
        Labyrinthe::otp()->send('0899999999', locale: 'en');

        self::assertSame('Code: {code}', $fake->otps()[0]->template);
        self::assertSame('fr', $fake->otps()[0]->locale);
        self::assertSame('en', $fake->otps()[1]->locale);
    }

    public function test_an_unresolved_idempotency_key_is_never_retried(): void
    {
        $fake = Labyrinthe::fake()->failNextRequest('IDEMPOTENCY_KEY_UNRESOLVED', 409);

        try {
            Labyrinthe::otp()->send('0812345678', idempotencyKey: 'signup-1');
            self::fail('IdempotencyKeyUnresolvedException expected.');
        } catch (IdempotencyKeyUnresolvedException $e) {
            self::assertSame(409, $e->getStatusCode());
        }
        // A retry would have reached the fake and recorded a code.
        $fake->assertNoOtpSent();

        $fake->failNextRequest('IDEMPOTENCY_KEY_UNRESOLVED', 409);
        try {
            Labyrinthe::send('0812345678', 'Hello');
            self::fail('IdempotencyKeyUnresolvedException expected.');
        } catch (IdempotencyKeyUnresolvedException) {
        }
        $fake->assertNothingSent();

        // The next send with a new key goes through.
        Labyrinthe::otp()->send('0812345678', idempotencyKey: 'signup-2');
        $fake->assertOtpSent('0812345678');
    }

    public function test_simulated_errors_are_typed(): void
    {
        $fake = Labyrinthe::fake()->failNextRequest('INSUFFICIENT_CREDITS', 409, ['available' => 0, 'required' => 1]);

        try {
            Labyrinthe::send('0812345678', 'x');
            self::fail('InsufficientCreditsException expected.');
        } catch (InsufficientCreditsException $e) {
            self::assertSame(1, $e->getRequired());
        }

        $fake->withSenderIds(['MABOUTIQUE']);
        self::assertSame('MABOUTIQUE', Labyrinthe::senderIds()->list()[0]->value);

        $this->expectException(SenderIdNotApprovedException::class);
        Labyrinthe::send('0812345678', 'x', sender: 'OTHER');
    }

    public function test_balance_and_estimate(): void
    {
        Labyrinthe::fake()->withBalance(50);

        self::assertSame(50, Labyrinthe::balance()->get()->balance);
        $estimate = Labyrinthe::sms()->estimate('Réunion', ['0812345678', '0899999999']);
        self::assertSame(2, $estimate->totalCredits);
        self::assertSame(48, $estimate->balanceAfter);
    }

    public function test_dumping_a_recorded_otp_hides_the_code(): void
    {
        $fake = Labyrinthe::fake()->withOtpCode('987654');
        Labyrinthe::otp()->send('0812345678');

        self::assertStringNotContainsString('987654', print_r($fake->otps()[0], true));
    }

    public function test_otp_results_have_the_1_2_fields(): void
    {
        Labyrinthe::fake()->withOtpCode('AB12CD')->withOtpMaxAttempts(2)->withOtpResendAfter(30);

        $otp = Labyrinthe::otp()->send('0812345678');
        self::assertSame(6, $otp->codeLength);
        self::assertSame(OtpCodeType::Alphanumeric, $otp->codeType);
        self::assertSame(30, $otp->resendAfterSeconds);
        self::assertSame(2, $otp->maxAttempts);
        self::assertNotNull($otp->idempotencyKey);

        $wrong = Labyrinthe::otp()->verify($otp->otpId, 'ZZZZZZ');
        self::assertSame(OtpFailureReason::InvalidCode, $wrong->reason);
        self::assertSame(1, $wrong->attemptsRemaining);

        $last = Labyrinthe::otp()->verify($otp->otpId, 'ZZZZZZ');
        self::assertSame(OtpFailureReason::MaxAttemptsReached, $last->reason);
        self::assertSame(0, $last->attemptsRemaining);

        $digits = Labyrinthe::fake()->withOtpCode('1234');
        $numeric = Labyrinthe::otp()->send('0899999999');
        self::assertSame(OtpCodeType::Numeric, $numeric->codeType);
        self::assertSame(4, $numeric->codeLength);
        self::assertNull(Labyrinthe::otp()->verify($numeric->otpId, '1234')->attemptsRemaining);
        self::assertCount(1, $digits->otps());
    }

    public function test_otp_sends_are_idempotent_like_the_api(): void
    {
        $fake = Labyrinthe::fake();

        $first = Labyrinthe::otp()->send('0812345678', idempotencyKey: 'signup-1', webOtpDomain: 'App.Example.com', stripAccents: true);
        $replay = Labyrinthe::otp()->send('+243812345678', idempotencyKey: 'signup-1', webOtpDomain: 'app.example.com', stripAccents: true);

        self::assertFalse($first->replayed);
        self::assertTrue($replay->replayed);
        self::assertSame($first->otpId, $replay->otpId);
        self::assertCount(1, $fake->otps(), 'A replay sends no new SMS.');
        self::assertSame('app.example.com', $fake->otps()[0]->webOtpDomain);
        self::assertTrue($fake->otps()[0]->stripAccents);
        self::assertSame('signup-1', $fake->otps()[0]->idempotencyKey);

        $this->expectException(IdempotencyKeyReusedException::class);
        Labyrinthe::otp()->send('0899999999', idempotencyKey: 'signup-1');
    }

    public function test_otp_defaults_for_web_otp_and_strip_accents_come_from_config(): void
    {
        config()->set('labyrinthe.otp.web_otp_domain', 'app.example.com');
        config()->set('labyrinthe.otp.strip_accents', true);
        $fake = Labyrinthe::fake();

        Labyrinthe::otp()->send('0812345678');
        Labyrinthe::otp()->send('0899999999', stripAccents: false);

        self::assertSame('app.example.com', $fake->otps()[0]->webOtpDomain);
        self::assertTrue($fake->otps()[0]->stripAccents);
        self::assertFalse($fake->otps()[1]->stripAccents);
    }

    public function test_strip_accents_and_normalised_idempotency_for_sms(): void
    {
        $fake = Labyrinthe::fake();

        self::assertSame(Encoding::Gsm7, Labyrinthe::sms()->estimate('Réunion à 9h', ['0812345678', '243812345678'], stripAccents: true)->encoding);
        self::assertSame(1, Labyrinthe::sms()->estimate('Réunion', ['0812345678', '+243812345678'])->recipientsCount, 'Duplicates are merged.');

        $message = Labyrinthe::sms()->send('0812345678', 'Réunion à 9h', idempotencyKey: 'k-1', stripAccents: true);
        self::assertSame(Encoding::Gsm7, $message->encoding);
        self::assertTrue($fake->sent()[0]->stripAccents);

        // Same number in another format, same key: a replay (fingerprint after normalisation).
        $replay = Labyrinthe::sms()->sendMany(['243812345678'], 'Réunion à 9h', idempotencyKey: 'k-1', stripAccents: true);
        self::assertTrue($replay->replayed);
        Labyrinthe::assertSmsSentCount(1);

        self::assertSame('33612345678', Labyrinthe::sms()->send('33612345678', 'Hello')->recipient, 'Foreign numbers without + are kept.');
    }
}

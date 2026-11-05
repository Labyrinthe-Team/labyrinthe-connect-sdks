<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Tests\Feature;

use Labyrinthe\Connect\Client;
use Labyrinthe\Connect\Enum\OtpFailureReason;
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
}

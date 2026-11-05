<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;
use Labyrinthe\Connect\Laravel\Rules\PhoneNumber;
use Labyrinthe\Connect\Laravel\Rules\ValidOtp;
use Labyrinthe\Connect\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RulesTest extends TestCase
{
    public function test_valid_otp_passes_with_the_right_code(): void
    {
        $fake = Labyrinthe::fake();
        $otp = Labyrinthe::otp()->send('0812345678');
        $rule = new ValidOtp($otp->otpId);

        $validator = Validator::make(['code' => $fake->otpCode()], ['code' => ['required', $rule]]);

        self::assertTrue($validator->passes());
        self::assertTrue($rule->result()?->isValid());
    }

    public function test_valid_otp_fails_with_a_wrong_code_and_a_translated_message(): void
    {
        Labyrinthe::fake()->withOtpCode('111111');
        $otp = Labyrinthe::otp()->send('0812345678');

        $validator = Validator::make(['code' => '222222'], ['code' => ['required', new ValidOtp($otp->otpId)]]);
        self::assertTrue($validator->fails());
        self::assertSame('The code field is incorrect.', $validator->errors()->first('code'));

        app()->setLocale('fr');
        $validator = Validator::make(['code' => '222222'], ['code' => ['required', new ValidOtp($otp->otpId)]]);
        self::assertSame('Le champ code est incorrect.', $validator->errors()->first('code'));
    }

    public function test_valid_otp_reports_already_used_codes(): void
    {
        $fake = Labyrinthe::fake();
        $otp = Labyrinthe::otp()->send('0812345678');
        Labyrinthe::otp()->verify($otp->otpId, (string) $fake->otpCode());

        $validator = Validator::make(['code' => $fake->otpCode()], ['code' => [new ValidOtp($otp->otpId)]]);

        self::assertSame('This code has already been used. Please request a new one.', $validator->errors()->first('code'));
    }

    public function test_valid_otp_without_otp_id_or_with_unknown_id(): void
    {
        Labyrinthe::fake();

        self::assertSame(
            'The verification code has expired. Please request a new one.',
            Validator::make(['code' => '123456'], ['code' => [new ValidOtp(null)]])->errors()->first('code'),
        );
        self::assertSame(
            'The verification code has expired. Please request a new one.',
            Validator::make(['code' => '123456'], ['code' => [new ValidOtp('unknown')]])->errors()->first('code'),
        );
        Labyrinthe::assertSmsSentCount(0);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function phones(): iterable
    {
        yield 'local' => ['0812345678', true];
        yield 'international' => ['+243 81 234 5678', true];
        yield 'foreign' => ['+33 6 12 34 56 78', true];
        yield 'letters' => ['hello', false];
        yield 'too short' => ['+1234', false];
        yield 'array' => [['0812345678'], false];
    }

    #[DataProvider('phones')]
    public function test_phone_number_rule(mixed $value, bool $valid): void
    {
        $validator = Validator::make(['phone' => $value], ['phone' => [new PhoneNumber()]]);

        self::assertSame($valid, $validator->passes());
        if (!$valid) {
            self::assertSame('The phone field must be a valid phone number.', $validator->errors()->first('phone'));
        }
    }
}

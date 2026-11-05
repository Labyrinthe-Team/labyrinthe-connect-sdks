<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\Exception\NotFoundException;
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;
use Labyrinthe\Connect\Model\OtpVerifyResult;

/**
 * Validates an OTP code against the API (consumes one attempt when wrong).
 *
 *     $request->validate([
 *         'code' => ['required', 'string', new ValidOtp(session('otp_id'))],
 *     ]);
 *
 * Use it once per submission, after the other rules ("bail" is advised):
 * each wrong code consumes an attempt.
 */
final class ValidOtp implements ValidationRule
{
    private ?OtpVerifyResult $result = null;

    public function __construct(private readonly ?string $otpId)
    {
    }

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if ($this->otpId === null || trim($this->otpId) === '') {
            $fail('labyrinthe::validation.otp.missing')->translate();

            return;
        }
        if (!\is_string($value) && !\is_int($value)) {
            $fail('labyrinthe::validation.otp.invalid_code')->translate();

            return;
        }

        try {
            $this->result = Labyrinthe::otp()->verify($this->otpId, (string) $value);
        } catch (NotFoundException) {
            $fail('labyrinthe::validation.otp.missing')->translate();

            return;
        } catch (InvalidArgumentException) {
            $fail('labyrinthe::validation.otp.invalid_code')->translate();

            return;
        }

        if (!$this->result->isValid()) {
            $reason = $this->result->reason->value ?? 'unknown';
            $fail('labyrinthe::validation.otp.'.$reason)->translate();
        }
    }

    /** The API result, once validated (null when the API was not called). */
    public function result(): ?OtpVerifyResult
    {
        return $this->result;
    }
}

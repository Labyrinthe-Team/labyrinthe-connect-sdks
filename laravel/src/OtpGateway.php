<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel;

use Labyrinthe\Connect\Model\OtpSendResult;
use Labyrinthe\Connect\Model\OtpVerifyResult;
use Labyrinthe\Connect\Service\OtpService;

/**
 * `Labyrinthe::otp()`: send and verify codes with the config defaults.
 *
 *     $otp = Labyrinthe::otp()->send($request->phone, idempotencyKey: 'login-'.$request->session()->getId());
 *     session(['otp_id' => $otp->otpId]);
 *     // later
 *     if (Labyrinthe::otp()->check(session('otp_id'), $request->code)) { … }
 */
final class OtpGateway
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly ?string $defaultTemplate = null,
        private readonly ?string $defaultLocale = null,
        private readonly ?string $defaultWebOtpDomain = null,
        private readonly bool $defaultStripAccents = false,
    ) {
    }

    /**
     * Sends a code by SMS (billed). An Idempotency-Key is always sent (yours,
     * or a generated one), so network errors, 5xx and
     * IDEMPOTENCY_REQUEST_IN_PROGRESS are retried safely: a replay returns the
     * same otpId without a second SMS. OTP_RESEND_TOO_SOON and
     * OTP_RATE_LIMITED are never retried, nor is IDEMPOTENCY_KEY_UNRESOLVED
     * (outcome unknown: a code may have been sent; send again with a new key).
     *
     * @param string|null $webOtpDomain host name reading the code (Web OTP API); defaults to config('labyrinthe.otp.web_otp_domain')
     * @param bool|null   $stripAccents defaults to config('labyrinthe.otp.strip_accents')
     *
     * @throws \Labyrinthe\Connect\Exception\OtpResendTooSoonException
     * @throws \Labyrinthe\Connect\Exception\OtpRateLimitedException
     * @throws \Labyrinthe\Connect\Exception\IdempotencyKeyUnresolvedException
     * @throws \Labyrinthe\Connect\Exception\LabyrintheException
     */
    public function send(
        string $phone,
        ?string $template = null,
        ?string $locale = null,
        ?string $webOtpDomain = null,
        ?bool $stripAccents = null,
        ?string $idempotencyKey = null,
    ): OtpSendResult {
        return $this->otp->send(
            $phone,
            $template ?? $this->defaultTemplate,
            $locale ?? $this->defaultLocale,
            $webOtpDomain ?? $this->defaultWebOtpDomain,
            $stripAccents ?? $this->defaultStripAccents,
            $idempotencyKey,
        );
    }

    /**
     * Verifies a code (one attempt consumed when wrong, see attemptsRemaining). Free.
     *
     * @throws \Labyrinthe\Connect\Exception\NotFoundException   unknown otpId
     * @throws \Labyrinthe\Connect\Exception\LabyrintheException
     */
    public function verify(string $otpId, #[\SensitiveParameter] string $code): OtpVerifyResult
    {
        return $this->otp->verify($otpId, $code);
    }

    /**
     * True when the code is valid. Unknown or empty otpId/code give false.
     */
    public function check(?string $otpId, #[\SensitiveParameter] ?string $code): bool
    {
        if ($otpId === null || trim($otpId) === '' || $code === null || trim($code) === '') {
            return false;
        }

        try {
            return $this->verify($otpId, $code)->isValid();
        } catch (\Labyrinthe\Connect\Exception\NotFoundException|\Labyrinthe\Connect\Exception\InvalidArgumentException) {
            return false;
        }
    }
}

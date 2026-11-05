<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel;

use Labyrinthe\Connect\Model\OtpSendResult;
use Labyrinthe\Connect\Model\OtpVerifyResult;
use Labyrinthe\Connect\Service\OtpService;

/**
 * `Labyrinthe::otp()`: send and verify codes with the config defaults.
 *
 *     $otp = Labyrinthe::otp()->send($request->phone);
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
    ) {
    }

    /**
     * Sends a code by SMS (billed). Never retried automatically.
     *
     * @throws \Labyrinthe\Connect\Exception\OtpResendTooSoonException
     * @throws \Labyrinthe\Connect\Exception\OtpRateLimitedException
     * @throws \Labyrinthe\Connect\Exception\LabyrintheException
     */
    public function send(string $phone, ?string $template = null, ?string $locale = null): OtpSendResult
    {
        return $this->otp->send($phone, $template ?? $this->defaultTemplate, $locale ?? $this->defaultLocale);
    }

    /**
     * Verifies a code (one attempt consumed when wrong). Free.
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

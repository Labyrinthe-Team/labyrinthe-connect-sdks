<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Service;

use Labyrinthe\Connect\Exception\ApiException;
use Labyrinthe\Connect\Exception\ConnectionException;
use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\Internal\RetryMode;
use Labyrinthe\Connect\Internal\Transport;
use Labyrinthe\Connect\Model\OtpSendResult;
use Labyrinthe\Connect\Model\OtpVerifyResult;
use Labyrinthe\Connect\PhoneNumber;

/**
 * One-time passwords by SMS (`$client->otp`).
 *
 * 1. send($phone) and keep `otpId` server-side (session);
 * 2. the user types the code received by SMS;
 * 3. verify($otpId, $code): log the user in when isValid().
 *
 * Code length, validity, attempts, sender and template come from the
 * account's OTP settings (client area > OTP verification).
 */
final class OtpService
{
    public const MAX_TEMPLATE_LENGTH = 1600;
    public const MAX_CODE_LENGTH = 20;

    /**
     * @internal use $client->otp
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $defaultCountryCode,
    ) {
    }

    /**
     * Generates a code and sends it by SMS (billed like any SMS). A new send to
     * the same number invalidates the previous code.
     *
     * Never retried automatically, except on 429 TOO_MANY_REQUESTS (refused
     * before processing): a second success would send a second, billed SMS.
     *
     * @param string|null $template must contain "{code}"; optional "{app_name}", "{minutes}"
     * @param string|null $locale   "fr" or "en", language of the standard template
     *
     * @throws InvalidArgumentException
     * @throws \Labyrinthe\Connect\Exception\OtpResendTooSoonException
     * @throws \Labyrinthe\Connect\Exception\OtpRateLimitedException
     * @throws ApiException
     * @throws ConnectionException
     */
    public function send(string $phone, ?string $template = null, ?string $locale = null): OtpSendResult
    {
        $payload = ['phone' => PhoneNumber::normalizeOrFail($phone, $this->defaultCountryCode)];

        if ($template !== null) {
            if (!str_contains($template, '{code}')) {
                throw new InvalidArgumentException('The OTP template must contain the {code} token.');
            }
            if (mb_strlen($template) > self::MAX_TEMPLATE_LENGTH) {
                throw new InvalidArgumentException(\sprintf('The OTP template must be at most %d characters.', self::MAX_TEMPLATE_LENGTH));
            }
            $payload['template'] = $template;
        }
        if ($locale !== null) {
            if (!\in_array($locale, ['fr', 'en'], true)) {
                throw new InvalidArgumentException('locale must be "fr" or "en".');
            }
            $payload['locale'] = $locale;
        }

        $response = $this->transport->request('POST', '/otp/send', RetryMode::OnlyWhenThrottled, body: $payload);

        return OtpSendResult::fromArray($response->data());
    }

    /**
     * Checks a code. A wrong code is NOT an exception: the result says
     * verified=false with a reason, and one attempt is consumed. Free.
     *
     * @throws \Labyrinthe\Connect\Exception\NotFoundException unknown otpId (or from another account)
     * @throws ApiException
     * @throws ConnectionException
     */
    public function verify(string $otpId, #[\SensitiveParameter] string $code): OtpVerifyResult
    {
        if (trim($otpId) === '') {
            throw new InvalidArgumentException('otpId cannot be empty.');
        }
        if (trim($code) === '' || \strlen($code) > self::MAX_CODE_LENGTH) {
            throw new InvalidArgumentException(\sprintf('The code must be 1 to %d characters.', self::MAX_CODE_LENGTH));
        }

        $response = $this->transport->request('POST', '/otp/verify', RetryMode::OnlyWhenThrottled, body: [
            'otp_id' => $otpId,
            'code' => $code,
        ]);

        return OtpVerifyResult::fromArray($response->data());
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['transport' => $this->transport, 'defaultCountryCode' => $this->defaultCountryCode];
    }
}

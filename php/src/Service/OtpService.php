<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Service;

use Labyrinthe\Connect\Exception\ApiException;
use Labyrinthe\Connect\Exception\ConnectionException;
use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\Internal\RetryMode;
use Labyrinthe\Connect\Internal\Transport;
use Labyrinthe\Connect\Internal\Uuid;
use Labyrinthe\Connect\Model\OtpSendResult;
use Labyrinthe\Connect\Model\OtpVerifyResult;
use Labyrinthe\Connect\PhoneNumber;

/**
 * One-time passwords by SMS (`$client->otp`).
 *
 * 1. send($phone) and keep `otpId` server-side (session);
 * 2. the user types the code received by SMS (`codeLength`, `codeType`);
 * 3. verify($otpId, $code): log the user in when isValid().
 *
 * Code length, alphabet, validity, attempts, sender and template come from
 * the account's OTP settings (client area > OTP verification).
 */
final class OtpService
{
    public const MAX_TEMPLATE_LENGTH = 1600;
    public const MAX_CODE_LENGTH = 20;
    public const MAX_IDEMPOTENCY_KEY_LENGTH = 255;
    public const MAX_WEB_OTP_DOMAIN_LENGTH = 253;

    /** Host name accepted by `web_otp_domain` (same pattern as the API). */
    public const WEB_OTP_DOMAIN_PATTERN = '^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$';

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
     * An Idempotency-Key is always sent (generated when you do not give one)
     * and reused by every retry, so the SDK safely retries network errors,
     * 5xx, 429 TOO_MANY_REQUESTS and 409 IDEMPOTENCY_REQUEST_IN_PROGRESS: a
     * replay returns the same otpId without a second SMS (`replayed`). The
     * business limits OTP_RESEND_TOO_SOON and OTP_RATE_LIMITED are never
     * retried. Pass your own key (e.g. "signup-<session id>") to also protect
     * against your own retries (double clicks, jobs) for 24 hours.
     *
     * @param string|null $template       must contain "{code}"; optional "{app_name}", "{minutes}"
     * @param string|null $locale         "fr" or "en", language of the standard template
     * @param string|null $webOtpDomain   host name of the site reading the code (Web OTP API): adds "@domain #code"
     * @param bool        $stripAccents   replace accents and typographic characters by ASCII (GSM-7, cheaper)
     * @param string|null $idempotencyKey max 255 characters
     * @param string|null $requestId      your X-Request-Id (1 to 128 characters among A-Z a-z 0-9 . _ : -)
     *
     * @throws InvalidArgumentException
     * @throws \Labyrinthe\Connect\Exception\OtpResendTooSoonException
     * @throws \Labyrinthe\Connect\Exception\OtpRateLimitedException
     * @throws ApiException
     * @throws ConnectionException
     */
    public function send(
        string $phone,
        ?string $template = null,
        ?string $locale = null,
        ?string $webOtpDomain = null,
        bool $stripAccents = false,
        ?string $idempotencyKey = null,
        ?string $requestId = null,
    ): OtpSendResult {
        $payload = ['phone' => PhoneNumber::toE164OrFail($phone, $this->defaultCountryCode)];

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
        if ($webOtpDomain !== null) {
            $webOtpDomain = strtolower(trim($webOtpDomain));
            if (\strlen($webOtpDomain) > self::MAX_WEB_OTP_DOMAIN_LENGTH || preg_match('/'.self::WEB_OTP_DOMAIN_PATTERN.'/', $webOtpDomain) !== 1) {
                throw new InvalidArgumentException('webOtpDomain must be a host name without scheme, port or path, e.g. "app.example.com".');
            }
            $payload['web_otp_domain'] = $webOtpDomain;
        }
        if ($stripAccents) {
            $payload['strip_accents'] = true;
        }
        if ($idempotencyKey !== null && ($idempotencyKey === '' || \strlen($idempotencyKey) > self::MAX_IDEMPOTENCY_KEY_LENGTH)) {
            throw new InvalidArgumentException(\sprintf('idempotencyKey must be 1 to %d characters.', self::MAX_IDEMPOTENCY_KEY_LENGTH));
        }

        // Generated once and reused by every retry of this call.
        $idempotencyKey ??= Uuid::v4();

        $response = $this->transport->request('POST', '/otp/send', RetryMode::Safe, body: $payload, headers: [
            'Idempotency-Key' => $idempotencyKey,
        ], requestId: $requestId);

        return OtpSendResult::fromArray($response->data(), $response->header('Idempotent-Replayed') === 'true', $idempotencyKey);
    }

    /**
     * Checks a code. A wrong code is NOT an exception: the result says
     * verified=false with a reason and `attemptsRemaining`, and one attempt is
     * consumed. Free.
     *
     * Only retried on 429 TOO_MANY_REQUESTS, never on network errors or 5xx
     * (an attempt may have been consumed).
     *
     * @param string|null $requestId your X-Request-Id
     *
     * @throws \Labyrinthe\Connect\Exception\NotFoundException unknown otpId (or from another account)
     * @throws ApiException
     * @throws ConnectionException
     */
    public function verify(string $otpId, #[\SensitiveParameter] string $code, ?string $requestId = null): OtpVerifyResult
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
        ], requestId: $requestId);

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

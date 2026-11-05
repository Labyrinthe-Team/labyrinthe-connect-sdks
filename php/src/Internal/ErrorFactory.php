<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Internal;

use Labyrinthe\Connect\Exception\ApiException;
use Labyrinthe\Connect\Exception\AuthenticationException;
use Labyrinthe\Connect\Exception\IdempotencyKeyReusedException;
use Labyrinthe\Connect\Exception\InsufficientCreditsException;
use Labyrinthe\Connect\Exception\InvalidPhoneException;
use Labyrinthe\Connect\Exception\NotFoundException;
use Labyrinthe\Connect\Exception\OtpRateLimitedException;
use Labyrinthe\Connect\Exception\OtpResendTooSoonException;
use Labyrinthe\Connect\Exception\PermissionException;
use Labyrinthe\Connect\Exception\RateLimitException;
use Labyrinthe\Connect\Exception\RecipientNotSendableException;
use Labyrinthe\Connect\Exception\SenderIdNotApprovedException;
use Labyrinthe\Connect\Exception\ServiceUnavailableException;
use Labyrinthe\Connect\Exception\ValidationException;

/**
 * Maps an error response to a typed exception: by error code first, then by
 * HTTP status, then a generic ApiException (unknown codes are expected).
 *
 * @internal
 */
final class ErrorFactory
{
    /** @var array<string, class-string<ApiException>> */
    private const BY_CODE = [
        'UNAUTHENTICATED' => AuthenticationException::class,
        'FORBIDDEN' => PermissionException::class,
        'OTP_DISABLED_FOR_ACCOUNT' => PermissionException::class,
        'NOT_FOUND' => NotFoundException::class,
        'VALIDATION_ERROR' => ValidationException::class,
        'RECIPIENT_NOT_SENDABLE' => RecipientNotSendableException::class,
        'SENDER_ID_NOT_APPROVED' => SenderIdNotApprovedException::class,
        'INVALID_PHONE' => InvalidPhoneException::class,
        'INSUFFICIENT_CREDITS' => InsufficientCreditsException::class,
        'IDEMPOTENCY_KEY_REUSED' => IdempotencyKeyReusedException::class,
        'TOO_MANY_REQUESTS' => RateLimitException::class,
        'OTP_RESEND_TOO_SOON' => OtpResendTooSoonException::class,
        'OTP_RATE_LIMITED' => OtpRateLimitedException::class,
        'OTP_SERVICE_DISABLED' => ServiceUnavailableException::class,
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string, string> $headers lower-case names
     */
    public static function fromResponse(int $status, string $rawBody, array $headers): ApiException
    {
        $code = null;
        $message = null;
        $details = [];

        try {
            $decoded = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        if (\is_array($decoded) && isset($decoded['error']) && \is_array($decoded['error'])) {
            $error = $decoded['error'];
            $code = isset($error['code']) && \is_string($error['code']) ? $error['code'] : null;
            $message = isset($error['message']) && \is_string($error['message']) ? $error['message'] : null;
            // The API sometimes sends `[]` instead of `{}`: both become an empty array.
            $details = isset($error['details']) && \is_array($error['details']) ? $error['details'] : [];
        }

        $code ??= $status === 429 ? 'TOO_MANY_REQUESTS' : 'HTTP_ERROR';
        $message ??= \sprintf('Labyrinthe Connect API returned HTTP %d.', $status);

        $class = self::BY_CODE[$code] ?? self::classForStatus($status);

        return new $class($message, $code, $status, $details, $headers);
    }

    /**
     * @return class-string<ApiException>
     */
    private static function classForStatus(int $status): string
    {
        return match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionException::class,
            $status === 404 => NotFoundException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServiceUnavailableException::class,
            default => ApiException::class,
        };
    }
}

<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Webhook;

use Labyrinthe\Connect\Enum\MessageStatus;
use Labyrinthe\Connect\Enum\OtpFailureReason;
use Labyrinthe\Connect\Enum\OtpWebhookStatus;
use Labyrinthe\Connect\Enum\WebhookEvent;
use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\Exception\InvalidWebhookPayloadException;
use Labyrinthe\Connect\Exception\SignatureVerificationException;
use Labyrinthe\Connect\Exception\UnexpectedValueException;
use Labyrinthe\Connect\Internal\Data;
use Labyrinthe\Connect\Webhook\Event\BalanceLow;
use Labyrinthe\Connect\Webhook\Event\CampaignCompleted;
use Labyrinthe\Connect\Webhook\Event\MessageStatusChanged;
use Labyrinthe\Connect\Webhook\Event\OtpFailed;
use Labyrinthe\Connect\Webhook\Event\OtpVerified;
use Labyrinthe\Connect\Webhook\Event\TestPing;
use Labyrinthe\Connect\Webhook\Event\UnknownEvent;

/**
 * Webhook verification.
 *
 *     $event = Webhook::constructEvent(
 *         file_get_contents('php://input'),
 *         $_SERVER['HTTP_X_SIGNATURE'] ?? '',
 *         getenv('LABYRINTHE_WEBHOOK_SECRET'),
 *     );
 *
 * The signature (`X-Signature: sha256=<hex HMAC-SHA256 of the raw body>`) is
 * checked in constant time on the RAW body, before any JSON parsing; then
 * the signed `timestamp` must be within the tolerance (replay protection).
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'X-Signature';
    public const DEFAULT_TOLERANCE = 300;
    private const PREFIX = 'sha256=';

    private function __construct()
    {
    }

    /**
     * Verifies a delivery and returns the typed event.
     *
     * @param string   $rawBody          the request body, byte for byte (never a re-encoded array)
     * @param string   $signatureHeader  value of the X-Signature header
     * @param string   $secret           the endpoint secret (client area > Developers > Webhooks)
     * @param int      $toleranceSeconds max age of the signed timestamp; 0 disables the check (not recommended)
     * @param int|null $now              current Unix time (tests)
     *
     * @throws SignatureVerificationException wrong/missing signature or timestamp out of tolerance: answer 400
     * @throws InvalidWebhookPayloadException signed body that is not a valid envelope: answer 400
     */
    public static function constructEvent(
        string $rawBody,
        string $signatureHeader,
        #[\SensitiveParameter]
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): Event {
        if (!self::verifySignature($rawBody, $signatureHeader, $secret)) {
            throw new SignatureVerificationException('Invalid webhook signature: the body was not signed with this secret.');
        }

        try {
            $envelope = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidWebhookPayloadException('The webhook body is not valid JSON.');
        }
        if (!\is_array($envelope)) {
            throw new InvalidWebhookPayloadException('The webhook body is not a JSON object.');
        }

        try {
            $timestamp = Data::int($envelope, 'timestamp');
            if ($toleranceSeconds > 0 && abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
                throw new SignatureVerificationException(\sprintf('Webhook timestamp is outside the %d s tolerance (possible replay).', $toleranceSeconds));
            }

            return self::buildEvent($envelope, $timestamp);
        } catch (UnexpectedValueException $e) {
            throw new InvalidWebhookPayloadException(str_replace('Unexpected API payload', 'Invalid webhook payload', $e->getMessage()));
        }
    }

    /**
     * Constant-time check of the X-Signature header against the raw body.
     */
    public static function verifySignature(string $rawBody, string $signatureHeader, #[\SensitiveParameter] string $secret): bool
    {
        if ($secret === '') {
            throw new InvalidArgumentException('The webhook secret cannot be empty.');
        }

        $signature = strtolower(trim($signatureHeader));
        if (!str_starts_with($signature, self::PREFIX)) {
            return false;
        }

        return hash_equals(self::computeSignature($rawBody, $secret), substr($signature, \strlen(self::PREFIX)));
    }

    /**
     * The X-Signature header value for a body: "sha256=<hex>". Useful to
     * test your webhook endpoint.
     */
    public static function sign(string $rawBody, #[\SensitiveParameter] string $secret): string
    {
        return self::PREFIX.self::computeSignature($rawBody, $secret);
    }

    private static function computeSignature(string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $rawBody, $secret);
    }

    /**
     * @param array<mixed> $envelope
     */
    private static function buildEvent(array $envelope, int $timestamp): Event
    {
        $id = Data::string($envelope, 'id');
        $type = Data::string($envelope, 'event');
        $data = Data::optionalArray($envelope, 'data');

        return match (WebhookEvent::tryFrom($type)) {
            WebhookEvent::MessageStatusChanged => new MessageStatusChanged(
                id: $id,
                type: $type,
                timestamp: $timestamp,
                messageId: Data::string($data, 'message_id'),
                recipient: Data::string($data, 'recipient'),
                status: MessageStatus::fromApi(Data::string($data, 'status')),
                providerMessageId: Data::nullableString($data, 'provider_message_id'),
                campaignId: Data::nullableString($data, 'campaign_id'),
                raw: $envelope,
                reference: Data::nullableString($data, 'reference'),
                errorCode: Data::nullableString($data, 'error_code'),
                errorMessage: Data::nullableString($data, 'error_message'),
            ),
            WebhookEvent::CampaignCompleted => new CampaignCompleted(
                id: $id,
                type: $type,
                timestamp: $timestamp,
                campaignId: Data::string($data, 'campaign_id'),
                name: Data::nullableString($data, 'name') ?? '',
                recipientsCount: Data::nullableInt($data, 'recipients_count') ?? 0,
                messagesByStatus: self::counts(Data::optionalArray($data, 'messages_by_status')),
                creditsCharged: Data::nullableInt($data, 'credits_charged') ?? 0,
                completedAt: Data::nullableDateTime($data, 'completed_at'),
                raw: $envelope,
            ),
            WebhookEvent::BalanceLow => new BalanceLow(
                id: $id,
                type: $type,
                timestamp: $timestamp,
                balance: Data::int($data, 'balance'),
                threshold: Data::int($data, 'threshold'),
                raw: $envelope,
            ),
            WebhookEvent::OtpVerified => new OtpVerified(
                id: $id,
                type: $type,
                timestamp: $timestamp,
                otpId: Data::string($data, 'otp_id'),
                phone: Data::string($data, 'phone'),
                status: OtpWebhookStatus::fromApi(Data::string($data, 'status')),
                reason: self::otpReason($data),
                verifiedAt: Data::nullableDateTime($data, 'verified_at'),
                raw: $envelope,
            ),
            WebhookEvent::OtpFailed => new OtpFailed(
                id: $id,
                type: $type,
                timestamp: $timestamp,
                otpId: Data::string($data, 'otp_id'),
                phone: Data::string($data, 'phone'),
                status: OtpWebhookStatus::fromApi(Data::string($data, 'status')),
                reason: self::otpReason($data),
                verifiedAt: Data::nullableDateTime($data, 'verified_at'),
                raw: $envelope,
            ),
            WebhookEvent::TestPing => new TestPing($id, $type, $timestamp, $envelope),
            null => new UnknownEvent($id, $type, $timestamp, $data, $envelope),
        };
    }

    /**
     * @param array<mixed> $data
     */
    private static function otpReason(array $data): ?OtpFailureReason
    {
        $reason = Data::nullableString($data, 'reason');

        return $reason === null ? null : OtpFailureReason::fromApi($reason);
    }

    /**
     * @param array<mixed> $counts
     *
     * @return array<string, int>
     */
    private static function counts(array $counts): array
    {
        $result = [];
        foreach (array_keys($counts) as $status) {
            $result[(string) $status] = Data::int($counts, (string) $status);
        }

        return $result;
    }
}

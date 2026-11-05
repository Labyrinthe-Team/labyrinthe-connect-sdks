<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Unit;

use Labyrinthe\Connect\Enum\MessageStatus;
use Labyrinthe\Connect\Enum\WebhookEvent;
use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\Exception\InvalidWebhookPayloadException;
use Labyrinthe\Connect\Exception\SignatureVerificationException;
use Labyrinthe\Connect\Webhook\Event\BalanceLow;
use Labyrinthe\Connect\Webhook\Event\CampaignCompleted;
use Labyrinthe\Connect\Webhook\Event\MessageStatusChanged;
use Labyrinthe\Connect\Webhook\Event\TestPing;
use Labyrinthe\Connect\Webhook\Event\UnknownEvent;
use Labyrinthe\Connect\Webhook\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';
    private const NOW = 1793952000;

    /**
     * Body encoded exactly like the backend (WebhookRequest::build).
     *
     * @param array<mixed> $data
     */
    private static function body(string $event, array $data, int $timestamp = self::NOW, string $id = '1f0c7e52-8d3a-4b6e-9c2f-5a7d1e3b9f40'): string
    {
        return json_encode(
            ['id' => $id, 'event' => $event, 'data' => $data, 'timestamp' => $timestamp],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }

    private static function signature(string $body, string $secret = self::SECRET): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    public function test_message_status_changed(): void
    {
        $body = self::body('message.status_changed', [
            'message_id' => '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10',
            'recipient' => '243812345678',
            'status' => 'delivered',
            'provider_message_id' => 'DD-88231',
            'campaign_id' => null,
        ]);

        $event = Webhook::constructEvent($body, self::signature($body), self::SECRET, now: self::NOW + 10);

        self::assertInstanceOf(MessageStatusChanged::class, $event);
        self::assertSame('1f0c7e52-8d3a-4b6e-9c2f-5a7d1e3b9f40', $event->id());
        self::assertSame(WebhookEvent::MessageStatusChanged, $event->eventType());
        self::assertSame('message.status_changed', $event->type());
        self::assertSame(self::NOW, $event->timestamp());
        self::assertSame(MessageStatus::Delivered, $event->status);
        self::assertSame('DD-88231', $event->providerMessageId);
        self::assertNull($event->campaignId);
        self::assertSame('243812345678', $event->recipient);
    }

    public function test_campaign_completed(): void
    {
        $body = self::body('campaign.completed', [
            'campaign_id' => '3c9a1f7e-2b4d-4e6a-8f0c-9d1b5e7a3c20',
            'name' => 'Promo de novembre',
            'recipients_count' => 1200,
            'messages_by_status' => ['delivered' => 1150, 'failed' => 30, 'undeliverable' => 20],
            'credits_charged' => 1200,
            'completed_at' => '2026-11-06T10:15:00+00:00',
        ]);

        $event = Webhook::constructEvent($body, self::signature($body), self::SECRET, now: self::NOW);

        self::assertInstanceOf(CampaignCompleted::class, $event);
        self::assertSame('Promo de novembre', $event->name);
        self::assertSame(1150, $event->countFor(MessageStatus::Delivered));
        self::assertSame(0, $event->countFor(MessageStatus::Expired));
        self::assertSame(1200, $event->creditsCharged);
        self::assertSame('2026-11-06T10:15:00+00:00', $event->completedAt?->format(\DATE_ATOM));
    }

    public function test_balance_low(): void
    {
        $body = self::body('balance.low', ['balance' => 950, 'threshold' => 1000]);

        $event = Webhook::constructEvent($body, self::signature($body), self::SECRET, now: self::NOW);

        self::assertInstanceOf(BalanceLow::class, $event);
        self::assertSame(950, $event->balance);
        self::assertSame(1000, $event->threshold);
    }

    public function test_test_ping_with_empty_data_encoded_as_array(): void
    {
        $body = self::body('test.ping', []);

        $event = Webhook::constructEvent($body, self::signature($body), self::SECRET, now: self::NOW);

        self::assertInstanceOf(TestPing::class, $event);
        self::assertSame([], $event->raw()['data']);
    }

    public function test_unknown_events_are_returned_verified_not_rejected(): void
    {
        $body = self::body('otp.verified', ['otp_id' => 'x']);

        $event = Webhook::constructEvent($body, self::signature($body), self::SECRET, now: self::NOW);

        self::assertInstanceOf(UnknownEvent::class, $event);
        self::assertNull($event->eventType());
        self::assertSame('otp.verified', $event->type());
        self::assertSame(['otp_id' => 'x'], $event->data());
    }

    public function test_a_wrong_secret_is_rejected(): void
    {
        $body = self::body('test.ping', []);

        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($body, self::signature($body, 'other-secret'), self::SECRET, now: self::NOW);
    }

    public function test_a_tampered_body_is_rejected(): void
    {
        $body = self::body('balance.low', ['balance' => 950, 'threshold' => 1000]);
        $signature = self::signature($body);
        $tampered = str_replace('950', '9500', $body);

        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($tampered, $signature, self::SECRET, now: self::NOW);
    }

    public function test_a_re_encoded_body_is_rejected(): void
    {
        // Verifying a json_encode()d array instead of the raw body fails: escaping differs.
        $body = self::body('campaign.completed', ['campaign_id' => 'c', 'name' => 'Opération été', 'recipients_count' => 1, 'messages_by_status' => [], 'credits_charged' => 1, 'completed_at' => null]);
        $reEncoded = json_encode(json_decode($body, true), \JSON_THROW_ON_ERROR);

        self::assertFalse(Webhook::verifySignature($reEncoded, self::signature($body), self::SECRET));
        self::assertTrue(Webhook::verifySignature($body, self::signature($body), self::SECRET));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedHeaders(): iterable
    {
        yield 'empty' => [''];
        yield 'no prefix' => [hash_hmac('sha256', 'x', self::SECRET)];
        yield 'other algorithm' => ['sha1=abc'];
        yield 'truncated' => ['sha256=abc'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedHeaders')]
    public function test_malformed_signature_headers_are_rejected(string $header): void
    {
        $body = self::body('test.ping', []);

        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($body, $header, self::SECRET, now: self::NOW);
    }

    public function test_upper_case_hex_and_whitespace_are_accepted(): void
    {
        $body = self::body('test.ping', []);
        $header = '  sha256='.strtoupper(hash_hmac('sha256', $body, self::SECRET)).' ';

        self::assertInstanceOf(TestPing::class, Webhook::constructEvent($body, $header, self::SECRET, now: self::NOW));
    }

    public function test_an_expired_timestamp_is_rejected(): void
    {
        $body = self::body('test.ping', [], timestamp: self::NOW - 301);

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('tolerance');
        Webhook::constructEvent($body, self::signature($body), self::SECRET, now: self::NOW);
    }

    public function test_a_timestamp_too_far_in_the_future_is_rejected(): void
    {
        $body = self::body('test.ping', [], timestamp: self::NOW + 301);

        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($body, self::signature($body), self::SECRET, now: self::NOW);
    }

    public function test_tolerance_is_configurable_and_can_be_disabled(): void
    {
        $body = self::body('test.ping', [], timestamp: self::NOW - 3600);

        self::assertInstanceOf(TestPing::class, Webhook::constructEvent($body, self::signature($body), self::SECRET, 0, self::NOW));
        self::assertInstanceOf(TestPing::class, Webhook::constructEvent($body, self::signature($body), self::SECRET, 7200, self::NOW));

        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($body, self::signature($body), self::SECRET, 600, self::NOW);
    }

    public function test_a_signed_but_invalid_body_is_reported(): void
    {
        $this->expectException(InvalidWebhookPayloadException::class);
        Webhook::constructEvent('not json', self::signature('not json'), self::SECRET, now: self::NOW);
    }

    public function test_a_signed_envelope_missing_fields_is_reported(): void
    {
        $body = (string) json_encode(['event' => 'balance.low', 'data' => ['balance' => 1], 'timestamp' => self::NOW]);

        $this->expectException(InvalidWebhookPayloadException::class);
        Webhook::constructEvent($body, self::signature($body), self::SECRET, now: self::NOW);
    }

    public function test_an_empty_secret_is_a_configuration_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Webhook::constructEvent('{}', 'sha256=x', '');
    }

    public function test_sign_produces_the_header_value(): void
    {
        $body = self::body('test.ping', []);

        self::assertSame(self::signature($body), Webhook::sign($body, self::SECRET));
    }
}

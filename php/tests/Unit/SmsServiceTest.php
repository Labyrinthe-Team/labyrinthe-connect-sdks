<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Unit;

use Labyrinthe\Connect\Enum\Encoding;
use Labyrinthe\Connect\Enum\MessageStatus;
use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\Model\Message;
use Labyrinthe\Connect\Tests\Support\TestCase;

final class SmsServiceTest extends TestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function test_send_posts_a_normalised_recipient_and_returns_a_typed_message(): void
    {
        $client = $this->client([self::json(202, ['data' => [self::message(['reference' => 'CMD-1042'])]])]);

        $message = $client->sms->send(to: '+243 81 234 5678', body: 'Votre commande est prête.', reference: 'CMD-1042');

        $request = $this->request();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/public/v1/sms', $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(['recipient' => '243812345678', 'body' => 'Votre commande est prête.', 'reference' => 'CMD-1042'], $this->requestJson());

        self::assertSame('9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10', $message->id);
        self::assertSame(MessageStatus::Queued, $message->status);
        self::assertSame(Encoding::Gsm7, $message->encoding);
        self::assertSame('CMD-1042', $message->reference);
        self::assertSame(1, $message->creditsCharged);
        self::assertSame('2026-11-05T10:00:00+00:00', $message->createdAt?->format(\DATE_ATOM));
        self::assertNull($message->deliveredAt);
        self::assertSame('queued', $message->raw()['status']);
    }

    public function test_send_always_sends_a_generated_uuid_v4_idempotency_key(): void
    {
        $client = $this->client([
            self::json(202, ['data' => [self::message()]]),
            self::json(202, ['data' => [self::message()]]),
        ]);

        $client->sms->send('0812345678', 'Hello');
        $client->sms->send('0812345678', 'Hello');

        $first = $this->request(0)->getHeaderLine('Idempotency-Key');
        $second = $this->request(1)->getHeaderLine('Idempotency-Key');
        self::assertMatchesRegularExpression(self::UUID_V4, $first);
        self::assertMatchesRegularExpression(self::UUID_V4, $second);
        self::assertNotSame($first, $second, 'Two distinct calls must not share a key.');
    }

    public function test_send_uses_the_given_idempotency_key(): void
    {
        $client = $this->client([self::json(202, ['data' => [self::message()]])]);
        $client->sms->send('0812345678', 'Hello', idempotencyKey: 'order-1042-ready');

        self::assertSame('order-1042-ready', $this->request()->getHeaderLine('Idempotency-Key'));
    }

    public function test_send_many_returns_a_batch_and_reports_replays(): void
    {
        $client = $this->client([self::json(202, ['data' => [
            self::message(['id' => 'a', 'recipient' => '243812345678']),
            self::message(['id' => 'b', 'recipient' => '243899999999']),
        ]], ['Idempotent-Replayed' => 'true'])]);

        $batch = $client->sms->sendMany(['0812345678', '00243 899 999 999'], 'Rappel', sender: 'MABOUTIQUE', idempotencyKey: 'k1');

        self::assertSame(['recipients' => ['243812345678', '243899999999'], 'body' => 'Rappel', 'sender' => 'MABOUTIQUE'], $this->requestJson());
        self::assertCount(2, $batch);
        self::assertTrue($batch->replayed);
        self::assertSame('k1', $batch->idempotencyKey);
        self::assertSame(['a', 'b'], array_map(static fn (Message $m): string => $m->id, iterator_to_array($batch)));
        self::assertSame('a', $batch->first()?->id);
    }

    public function test_send_many_refuses_invalid_numbers_before_any_call(): void
    {
        $client = $this->client([]);

        try {
            $client->sms->sendMany(['0812345678', 'abc', '12'], 'Hello');
            self::fail('An InvalidArgumentException was expected.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('index 1, 2', $e->getMessage());
        }
        self::assertSame([], $this->history);
    }

    public function test_send_validates_its_input_before_any_call(): void
    {
        $client = $this->client([]);
        $cases = [
            static fn () => $client->sms->send('0812345678', ''),
            static fn () => $client->sms->send('0812345678', str_repeat('a', 1601)),
            static fn () => $client->sms->send('not a phone', 'Hello'),
            static fn () => $client->sms->send('0812345678', 'Hello', sender: 'TWELVECHARSX'),
            static fn () => $client->sms->send('0812345678', 'Hello', reference: str_repeat('r', 65)),
            static fn () => $client->sms->send('0812345678', 'Hello', idempotencyKey: str_repeat('k', 256)),
            static fn () => $client->sms->sendMany([], 'Hello'),
            static fn () => $client->sms->sendMany(array_fill(0, 1001, '0812345678'), 'Hello'),
        ];

        foreach ($cases as $i => $case) {
            try {
                $case();
                self::fail(\sprintf('Case #%d should have been refused.', $i));
            } catch (InvalidArgumentException) {
                // expected
            }
        }
        self::assertSame([], $this->history);
    }

    public function test_body_length_counts_characters_not_bytes(): void
    {
        $client = $this->client([self::json(202, ['data' => [self::message()]])]);
        $client->sms->send('0812345678', str_repeat('é', 1600));

        self::assertCount(1, $this->history);
    }

    public function test_the_default_sender_is_used_when_none_is_given(): void
    {
        $client = $this->client([
            self::json(202, ['data' => [self::message()]]),
            self::json(202, ['data' => [self::message()]]),
        ], defaultSender: 'MABOUTIQUE');

        $client->sms->send('0812345678', 'Hello');
        $client->sms->send('0812345678', 'Hello', sender: 'AUTRE');

        self::assertSame('MABOUTIQUE', $this->requestJson(0)['sender']);
        self::assertSame('AUTRE', $this->requestJson(1)['sender']);
    }

    public function test_estimate_returns_the_cost(): void
    {
        $client = $this->client([self::json(200, ['data' => [
            'encoding' => 'ucs2', 'characters' => 12, 'segments_per_message' => 1,
            'recipients_count' => 2, 'total_credits' => 2, 'balance_after' => 998,
        ]])]);

        $estimate = $client->sms->estimate('Réunion 9h', ['0812345678', '+243899999999']);

        self::assertSame(['body' => 'Réunion 9h', 'recipients' => ['243812345678', '243899999999']], $this->requestJson());
        self::assertSame(Encoding::Ucs2, $estimate->encoding);
        self::assertSame(2, $estimate->totalCredits);
        self::assertSame(998, $estimate->balanceAfter);
        self::assertTrue($estimate->isAffordable());
    }

    public function test_estimate_passes_invalid_numbers_through_untouched(): void
    {
        $client = $this->client([self::json(200, ['data' => [
            'encoding' => 'gsm7', 'characters' => 5, 'segments_per_message' => 1,
            'recipients_count' => 1, 'total_credits' => 1, 'balance_after' => null,
        ]])]);

        $estimate = $client->sms->estimate('Hello', ['0812345678', 'abc']);

        self::assertSame(['243812345678', 'abc'], $this->requestJson()['recipients']);
        self::assertNull($estimate->balanceAfter);
        self::assertFalse($estimate->isAffordable());
    }

    public function test_get_encodes_the_id(): void
    {
        $client = $this->client([self::json(200, ['data' => self::message(['status' => 'delivered', 'delivered_at' => '2026-11-05T10:00:05Z'])])]);

        $message = $client->sms->get('a/b c');

        self::assertSame('/api/public/v1/sms/a%2Fb%20c', $this->request()->getUri()->getPath());
        self::assertTrue($message->status->isSuccessful());
        self::assertNotNull($message->deliveredAt);
    }

    public function test_unknown_statuses_and_null_encoding_do_not_break_parsing(): void
    {
        $client = $this->client([self::json(200, ['data' => self::message(['status' => 'scheduled', 'encoding' => null])])]);

        $message = $client->sms->get('x');

        self::assertSame(MessageStatus::Unknown, $message->status);
        self::assertNull($message->encoding);
        self::assertSame('scheduled', $message->raw()['status']);
    }

    public function test_list_sends_filters_and_auto_paginates(): void
    {
        $meta = static fn (int $page): array => ['current_page' => $page, 'last_page' => 2, 'per_page' => 2, 'total' => 3, 'from' => 1, 'to' => 2, 'path' => ''];
        $client = $this->client([
            self::json(200, ['data' => [self::message(['id' => '1']), self::message(['id' => '2'])], 'links' => [], 'meta' => $meta(1)]),
            self::json(200, ['data' => [self::message(['id' => '3'])], 'links' => [], 'meta' => $meta(2)]),
        ]);

        $page = $client->sms->list(
            status: MessageStatus::Delivered,
            recipient: '0812345678',
            reference: 'CMD 1',
            from: new \DateTimeImmutable('2026-11-01T09:00:00+01:00'),
            to: '2026-11-30',
            perPage: 2,
        );

        parse_str($this->request()->getUri()->getQuery(), $query);
        self::assertSame([
            'status' => 'delivered',
            'recipient' => '243812345678',
            'reference' => 'CMD 1',
            'from' => '2026-11-01T08:00:00Z',
            'to' => '2026-11-30',
            'per_page' => '2',
            'page' => '1',
        ], $query);
        self::assertSame(3, $page->total);
        self::assertTrue($page->hasMore());
        self::assertCount(2, $page);

        $ids = array_map(static fn (Message $m): string => $m->id, iterator_to_array($page->autoPaging(), false));
        self::assertSame(['1', '2', '3'], $ids);

        parse_str($this->request(1)->getUri()->getQuery(), $second);
        self::assertSame('2', $second['page']);
        self::assertSame('delivered', $second['status'], 'Filters are kept on the next pages.');
    }

    public function test_list_validates_pagination(): void
    {
        $client = $this->client([]);
        $this->expectException(InvalidArgumentException::class);
        $client->sms->list(perPage: 101);
    }
}

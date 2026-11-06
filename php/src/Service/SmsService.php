<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Service;

use Labyrinthe\Connect\Enum\MessageStatus;
use Labyrinthe\Connect\Exception\ApiException;
use Labyrinthe\Connect\Exception\ConnectionException;
use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\Internal\Data;
use Labyrinthe\Connect\Internal\RetryMode;
use Labyrinthe\Connect\Internal\Transport;
use Labyrinthe\Connect\Internal\Uuid;
use Labyrinthe\Connect\Model\Estimate;
use Labyrinthe\Connect\Model\Message;
use Labyrinthe\Connect\Model\MessageBatch;
use Labyrinthe\Connect\Model\Page;
use Labyrinthe\Connect\PhoneNumber;

/**
 * Send and track SMS (`$client->sms`).
 */
final class SmsService
{
    public const MAX_BODY_LENGTH = 1600;
    public const MAX_RECIPIENTS = 1000;
    public const MAX_SENDER_LENGTH = 11;
    public const MAX_REFERENCE_LENGTH = 64;
    public const MAX_IDEMPOTENCY_KEY_LENGTH = 255;
    public const MAX_PER_PAGE = 100;

    /**
     * @internal use $client->sms
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $defaultCountryCode,
        private readonly ?string $defaultSender,
    ) {
    }

    /**
     * Sends one SMS. Asynchronous: the returned message is `queued`; follow
     * its status with webhooks (message.status_changed) or get().
     *
     * An Idempotency-Key is always sent (generated when you do not give one)
     * so the SDK can safely retry on network errors: the SMS is never sent
     * twice. Pass your own key (e.g. "order-1042-ready") to also protect
     * against your own retries (jobs, double clicks) for 24 hours.
     *
     * @param string      $to             phone number (international or local format)
     * @param string      $body           text, 1 to 1600 characters
     * @param string|null $sender         approved Sender ID (defaults to the client's default sender, then the platform's)
     * @param string|null $reference      your own reference (order number…), max 64 characters
     * @param string|null $idempotencyKey max 255 characters
     * @param bool        $stripAccents   replace accents and typographic characters by ASCII before sending
     *                                    (GSM-7, often half the cost; the transliteration depends on the server)
     * @param string|null $requestId      your X-Request-Id (1 to 128 characters among A-Z a-z 0-9 . _ : -)
     *
     * @throws InvalidArgumentException before any call when the input is invalid
     * @throws ApiException             RecipientNotSendableException, InsufficientCreditsException, SenderIdNotApprovedException, FeatureDisabledException…
     * @throws ConnectionException
     */
    public function send(
        string $to,
        #[\SensitiveParameter] string $body,
        ?string $sender = null,
        ?string $reference = null,
        ?string $idempotencyKey = null,
        bool $stripAccents = false,
        ?string $requestId = null,
    ): Message {
        $batch = $this->dispatch(
            ['recipient' => PhoneNumber::toE164OrFail($to, $this->defaultCountryCode, 'to')],
            $body,
            $sender,
            $reference,
            $idempotencyKey,
            $stripAccents,
            $requestId,
        );

        $message = $batch->first();
        if ($message === null) {
            throw new \Labyrinthe\Connect\Exception\UnexpectedValueException('Unexpected API response: no message was returned.');
        }

        return $message;
    }

    /**
     * Sends the same SMS to several recipients (up to 1000), all or nothing:
     * if a single recipient is refused, nothing is sent and a
     * RecipientNotSendableException lists every refused number. Duplicates are
     * merged by the API (one message per distinct number).
     *
     * @param array<array-key, string> $recipients phone numbers (any keys)
     *
     * @throws InvalidArgumentException before any call when the input is invalid
     * @throws ApiException
     * @throws ConnectionException
     */
    public function sendMany(
        array $recipients,
        #[\SensitiveParameter] string $body,
        ?string $sender = null,
        ?string $reference = null,
        ?string $idempotencyKey = null,
        bool $stripAccents = false,
        ?string $requestId = null,
    ): MessageBatch {
        if ($recipients === []) {
            throw new InvalidArgumentException('At least one recipient is required.');
        }
        if (\count($recipients) > self::MAX_RECIPIENTS) {
            throw new InvalidArgumentException(\sprintf('At most %d recipients per request.', self::MAX_RECIPIENTS));
        }

        $normalized = [];
        $invalid = [];
        foreach (array_values($recipients) as $index => $recipient) {
            $number = PhoneNumber::toE164($recipient, $this->defaultCountryCode);
            if ($number === null) {
                $invalid[] = $index;
            } else {
                $normalized[] = $number;
            }
        }
        if ($invalid !== []) {
            throw new InvalidArgumentException(\sprintf('Invalid phone number(s) at recipients index %s. Nothing was sent.', implode(', ', \array_slice($invalid, 0, 20)).(\count($invalid) > 20 ? '…' : '')));
        }

        return $this->dispatch(['recipients' => $normalized], $body, $sender, $reference, $idempotencyKey, $stripAccents, $requestId);
    }

    /**
     * Computes encoding, segments and credits without sending anything.
     * Recipients are counted like a send: normalised, duplicates merged,
     * invalid numbers ignored (no error).
     *
     * @param array<array-key, string>|string $recipients
     * @param bool                            $stripAccents estimate the text with accents replaced by ASCII
     * @param string|null                     $requestId    your X-Request-Id
     *
     * @throws ApiException
     * @throws ConnectionException
     */
    public function estimate(
        #[\SensitiveParameter] string $body,
        array|string $recipients,
        bool $stripAccents = false,
        ?string $requestId = null,
    ): Estimate {
        self::assertBody($body);
        $recipients = \is_string($recipients) ? [$recipients] : array_values($recipients);
        if ($recipients === []) {
            throw new InvalidArgumentException('At least one recipient is required.');
        }

        $numbers = array_map(
            fn (string $r): string => PhoneNumber::toE164($r, $this->defaultCountryCode) ?? $r,
            $recipients,
        );

        $payload = ['body' => $body, 'recipients' => $numbers];
        if ($stripAccents) {
            $payload['strip_accents'] = true;
        }

        $response = $this->transport->request('POST', '/sms/estimate', RetryMode::Safe, body: $payload, requestId: $requestId);

        return Estimate::fromArray($response->data());
    }

    /**
     * One message of the account, by id (any key or channel that sent it).
     *
     * @param string|null $requestId your X-Request-Id
     *
     * @throws \Labyrinthe\Connect\Exception\NotFoundException
     * @throws ApiException
     * @throws ConnectionException
     */
    public function get(string $id, ?string $requestId = null): Message
    {
        if (trim($id) === '') {
            throw new InvalidArgumentException('The message id cannot be empty.');
        }

        $response = $this->transport->request('GET', '/sms/'.rawurlencode($id), RetryMode::Safe, requestId: $requestId);

        return Message::fromArray($response->data());
    }

    /**
     * Messages sent with this API key, newest first.
     *
     * @param string|null                    $recipient any format, normalised by the API
     * @param string|null                    $reference exact match
     * @param \DateTimeInterface|string|null $from      inclusive (a date "2026-11-01" or a date-time)
     * @param \DateTimeInterface|string|null $to        inclusive (a date covers the whole day)
     * @param string|null                    $requestId your X-Request-Id (sent with this page only)
     *
     * @return Page<Message>
     *
     * @throws ApiException
     * @throws ConnectionException
     */
    public function list(
        MessageStatus|string|null $status = null,
        ?string $recipient = null,
        ?string $reference = null,
        \DateTimeInterface|string|null $from = null,
        \DateTimeInterface|string|null $to = null,
        int $page = 1,
        int $perPage = 15,
        ?string $requestId = null,
    ): Page {
        if ($page < 1) {
            throw new InvalidArgumentException('page must be >= 1.');
        }
        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException(\sprintf('perPage must be between 1 and %d.', self::MAX_PER_PAGE));
        }

        $query = [
            'status' => $status instanceof MessageStatus ? $status->value : $status,
            'recipient' => $recipient === null ? null : (PhoneNumber::toE164($recipient, $this->defaultCountryCode) ?? $recipient),
            'reference' => $reference,
            'from' => self::formatDate($from),
            'to' => self::formatDate($to),
            'per_page' => $perPage,
        ];

        return $this->fetchPage($query, $page, $requestId);
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return Page<Message>
     */
    private function fetchPage(array $query, int $page, ?string $requestId = null): Page
    {
        $response = $this->transport->request('GET', '/sms', RetryMode::Safe, query: $query + ['page' => $page], requestId: $requestId);
        $meta = Data::optionalArray($response->body, 'meta');
        $messages = array_map(static fn (array $m): Message => Message::fromArray($m), $response->dataList());

        return new Page(
            data: $messages,
            currentPage: Data::nullableInt($meta, 'current_page') ?? $page,
            lastPage: Data::nullableInt($meta, 'last_page') ?? $page,
            perPage: Data::nullableInt($meta, 'per_page') ?? \count($messages),
            total: Data::nullableInt($meta, 'total') ?? \count($messages),
            fetchPage: fn (int $next): Page => $this->fetchPage($query, $next),
        );
    }

    /**
     * @param array{recipient: string}|array{recipients: list<string>} $target
     */
    private function dispatch(
        array $target,
        #[\SensitiveParameter] string $body,
        ?string $sender,
        ?string $reference,
        ?string $idempotencyKey,
        bool $stripAccents,
        ?string $requestId,
    ): MessageBatch {
        self::assertBody($body);
        $sender ??= $this->defaultSender;
        if ($sender !== null && ($sender === '' || mb_strlen($sender) > self::MAX_SENDER_LENGTH)) {
            throw new InvalidArgumentException(\sprintf('sender must be 1 to %d characters.', self::MAX_SENDER_LENGTH));
        }
        if ($reference !== null && mb_strlen($reference) > self::MAX_REFERENCE_LENGTH) {
            throw new InvalidArgumentException(\sprintf('reference must be at most %d characters.', self::MAX_REFERENCE_LENGTH));
        }
        if ($idempotencyKey !== null && ($idempotencyKey === '' || \strlen($idempotencyKey) > self::MAX_IDEMPOTENCY_KEY_LENGTH)) {
            throw new InvalidArgumentException(\sprintf('idempotencyKey must be 1 to %d characters.', self::MAX_IDEMPOTENCY_KEY_LENGTH));
        }

        // Generated once and reused by every retry of this call.
        $idempotencyKey ??= Uuid::v4();

        $payload = $target + ['body' => $body];
        if ($sender !== null) {
            $payload['sender'] = $sender;
        }
        if ($reference !== null && $reference !== '') {
            $payload['reference'] = $reference;
        }
        if ($stripAccents) {
            $payload['strip_accents'] = true;
        }

        $response = $this->transport->request('POST', '/sms', RetryMode::Safe, body: $payload, headers: [
            'Idempotency-Key' => $idempotencyKey,
        ], requestId: $requestId);

        return new MessageBatch(
            array_map(static fn (array $m): Message => Message::fromArray($m), $response->dataList()),
            $idempotencyKey,
            $response->header('Idempotent-Replayed') === 'true',
        );
    }

    private static function assertBody(#[\SensitiveParameter] string $body): void
    {
        if ($body === '') {
            throw new InvalidArgumentException('The SMS body cannot be empty.');
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new InvalidArgumentException(\sprintf('The SMS body must be at most %d characters.', self::MAX_BODY_LENGTH));
        }
    }

    private static function formatDate(\DateTimeInterface|string|null $date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            // Same format as the API dates: 2026-11-06T09:39:11+00:00.
            return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
        }

        return $date;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['transport' => $this->transport, 'defaultCountryCode' => $this->defaultCountryCode, 'defaultSender' => $this->defaultSender];
    }
}

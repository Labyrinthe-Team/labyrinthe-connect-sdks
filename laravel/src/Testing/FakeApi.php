<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Testing;

use GuzzleHttp\Psr7\Response;
use Labyrinthe\Connect\PhoneNumber;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * In-memory implementation of the public API, behind a PSR-18 client: the
 * real SDK runs (validation, normalisation, error mapping), nothing leaves
 * the process. Mirrors the sandbox: test_code returned, no credit debited.
 *
 * @internal used by LabyrintheFake
 */
final class FakeApi implements ClientInterface
{
    /** @var list<SentSms> */
    public array $sms = [];

    /** @var list<SentOtp> */
    public array $otps = [];

    public int $balance = 1000;

    /** @var list<string>|null null: every sender is accepted */
    public ?array $senderIds = null;

    public ?string $otpCode = null;

    public int $otpMaxAttempts = 3;

    /** Returned as resend_after_seconds (informative: the fake does not enforce OTP_RESEND_TOO_SOON). */
    public int $otpResendAfterSeconds = 60;

    /** @var list<array{status: int, code: string, message: string, details: array<mixed>, headers?: array<string, string>}> */
    public array $failures = [];

    /** @var array<string, array<string, mixed>> */
    private array $messages = [];

    /** @var array<string, array{fingerprint: string, ids: list<string>}> */
    private array $idempotency = [];

    /** @var array<string, array{used: bool, attempts: int}> */
    private array $otpState = [];

    /** @var array<string, array{fingerprint: string, data: array<string, mixed>}> */
    private array $otpIdempotency = [];

    public function __construct(private readonly string $defaultCountryCode = PhoneNumber::DEFAULT_COUNTRY_CODE)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->failures !== []) {
            $failure = array_shift($this->failures);

            return self::error($failure['status'], $failure['code'], $failure['message'], $failure['details'], $failure['headers'] ?? []);
        }

        $path = (string) preg_replace('#^.*/api/public/v1#', '', $request->getUri()->getPath());
        $method = $request->getMethod();
        $body = json_decode((string) $request->getBody(), true);
        $input = \is_array($body) ? $body : [];

        return match (true) {
            $method === 'POST' && $path === '/sms' => $this->sendSms($input, $request->getHeaderLine('Idempotency-Key')),
            $method === 'POST' && $path === '/sms/estimate' => $this->estimate($input),
            $method === 'GET' && $path === '/sms' => $this->listSms($request->getUri()->getQuery()),
            $method === 'GET' && str_starts_with($path, '/sms/') => $this->getSms(rawurldecode(substr($path, 5))),
            $method === 'GET' && $path === '/balance' => self::json(200, ['data' => ['balance' => $this->balance, 'held' => 0]]),
            $method === 'GET' && $path === '/sender-ids' => $this->listSenderIds(),
            $method === 'POST' && $path === '/otp/send' => $this->sendOtp($input, $request->getHeaderLine('Idempotency-Key')),
            $method === 'POST' && $path === '/otp/verify' => $this->verifyOtp($input),
            default => self::error(404, 'NOT_FOUND', 'Route introuvable.'),
        };
    }

    /**
     * @param array<mixed> $input
     */
    private function sendSms(array $input, string $idempotencyKey): ResponseInterface
    {
        $recipients = isset($input['recipients']) && \is_array($input['recipients']) ? $input['recipients'] : [$input['recipient'] ?? null];
        $body = \is_string($input['body'] ?? null) ? $input['body'] : '';
        $sender = \is_string($input['sender'] ?? null) ? $input['sender'] : null;
        $reference = \is_string($input['reference'] ?? null) ? $input['reference'] : null;
        $stripAccents = ($input['strip_accents'] ?? false) === true;

        $normalized = [];
        $rejected = [];
        foreach ($recipients as $recipient) {
            $number = \is_string($recipient) ? PhoneNumber::normalize($recipient, $this->defaultCountryCode) : null;
            if ($number === null) {
                $rejected[] = ['recipient' => \is_string($recipient) ? $recipient : '', 'reason' => 'invalid_phone'];
            } else {
                $normalized[$number] = $number;
            }
        }
        if ($rejected !== []) {
            return self::error(422, 'RECIPIENT_NOT_SENDABLE', 'Destinataire non envoyable. Aucun message envoyé.', ['reason' => 'invalid_phone', 'rejected' => $rejected]);
        }
        if ($sender !== null && $this->senderIds !== null && !\in_array($sender, $this->senderIds, true)) {
            return self::error(422, 'SENDER_ID_NOT_APPROVED', "Ce Sender ID n'est pas approuvé.");
        }

        // Like the API (1.2.0): computed after normalisation and deduplication of the recipients.
        $fingerprint = hash('sha256', serialize([array_values($normalized), $body, $sender, $reference, $stripAccents]));
        if ($idempotencyKey !== '' && isset($this->idempotency[$idempotencyKey])) {
            $previous = $this->idempotency[$idempotencyKey];
            if ($previous['fingerprint'] !== $fingerprint) {
                return self::error(409, 'IDEMPOTENCY_KEY_REUSED', "Cette clé d'idempotence a déjà servi pour une requête différente.");
            }

            $replayed = array_map(fn (string $id): array => $this->messages[$id], $previous['ids']);

            return self::json(202, ['data' => $replayed], ['Idempotent-Replayed' => 'true']);
        }

        [$encoding, $segments] = self::segments($stripAccents ? self::stripAccents($body) : $body);
        $now = gmdate('Y-m-d\TH:i:sP');
        $created = [];
        foreach ($normalized as $number) {
            $id = self::uuid();
            $this->messages[$id] = [
                'id' => $id, 'reference' => $reference, 'recipient' => $number, 'sender' => $sender ?? 'LABYRINTHE',
                'encoding' => $encoding, 'status' => 'queued', 'segments' => $segments, 'credits_charged' => $segments,
                'campaign_id' => null, 'provider_message_id' => null, 'error_code' => null, 'error_message' => null,
                'created_at' => $now, 'submitted_at' => null, 'delivered_at' => null,
            ];
            $created[] = $id;
        }

        if ($idempotencyKey !== '') {
            $this->idempotency[$idempotencyKey] = ['fingerprint' => $fingerprint, 'ids' => $created];
        }
        $this->sms[] = new SentSms(array_values($normalized), $body, $sender, $reference, $idempotencyKey !== '' ? $idempotencyKey : null, $created, $this->defaultCountryCode, $stripAccents);

        return self::json(202, ['data' => array_map(fn (string $id): array => $this->messages[$id], $created)]);
    }

    /**
     * @param array<mixed> $input
     */
    private function estimate(array $input): ResponseInterface
    {
        $body = \is_string($input['body'] ?? null) ? $input['body'] : '';
        $recipients = \is_array($input['recipients'] ?? null) ? $input['recipients'] : [];
        if (($input['strip_accents'] ?? false) === true) {
            $body = self::stripAccents($body);
        }
        // Counted like a send: normalised, duplicates merged, invalid numbers ignored.
        $numbers = [];
        foreach ($recipients as $recipient) {
            $number = \is_string($recipient) ? PhoneNumber::normalize($recipient, $this->defaultCountryCode) : null;
            if ($number !== null) {
                $numbers[$number] = true;
            }
        }
        $valid = \count($numbers);
        [$encoding, $segments] = self::segments($body);

        return self::json(200, ['data' => [
            'encoding' => $encoding,
            'characters' => mb_strlen($body),
            'segments_per_message' => $segments,
            'recipients_count' => $valid,
            'total_credits' => $segments * $valid,
            'balance_after' => $this->balance - $segments * $valid,
        ]]);
    }

    private function getSms(string $id): ResponseInterface
    {
        return isset($this->messages[$id])
            ? self::json(200, ['data' => $this->messages[$id]])
            : self::error(404, 'NOT_FOUND', 'Message introuvable.');
    }

    private function listSms(string $query): ResponseInterface
    {
        parse_str($query, $params);
        $messages = array_reverse(array_values($this->messages));
        foreach (['status', 'reference'] as $filter) {
            if (isset($params[$filter]) && \is_string($params[$filter])) {
                $value = $params[$filter];
                $messages = array_values(array_filter($messages, static fn (array $m): bool => $m[$filter] === $value));
            }
        }
        if (isset($params['recipient']) && \is_string($params['recipient'])) {
            $recipient = PhoneNumber::normalize($params['recipient'], $this->defaultCountryCode) ?? $params['recipient'];
            $messages = array_values(array_filter($messages, static fn (array $m): bool => $m['recipient'] === $recipient));
        }

        $perPage = isset($params['per_page']) && is_numeric($params['per_page']) ? max(1, (int) $params['per_page']) : 15;
        $page = isset($params['page']) && is_numeric($params['page']) ? max(1, (int) $params['page']) : 1;
        $total = \count($messages);

        return self::json(200, [
            'data' => \array_slice($messages, ($page - 1) * $perPage, $perPage),
            'links' => ['first' => null, 'last' => null, 'prev' => null, 'next' => null],
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'per_page' => $perPage, 'total' => $total],
        ]);
    }

    private function listSenderIds(): ResponseInterface
    {
        $data = array_map(static fn (string $value): array => [
            'id' => self::uuid(), 'value' => $value, 'status' => 'approved', 'purpose' => null,
            'rejection_reason' => null, 'is_default' => false, 'created_at' => gmdate('Y-m-d\TH:i:sP'),
        ], $this->senderIds ?? []);

        return self::json(200, ['data' => $data]);
    }

    /**
     * @param array<mixed> $input
     */
    private function sendOtp(array $input, string $idempotencyKey): ResponseInterface
    {
        $phone = \is_string($input['phone'] ?? null) ? PhoneNumber::normalize($input['phone'], $this->defaultCountryCode) : null;
        if ($phone === null) {
            return self::error(422, 'INVALID_PHONE', 'Numéro de téléphone invalide.');
        }

        $template = \is_string($input['template'] ?? null) ? $input['template'] : null;
        $locale = \is_string($input['locale'] ?? null) ? $input['locale'] : null;
        $webOtpDomain = \is_string($input['web_otp_domain'] ?? null) ? strtolower($input['web_otp_domain']) : null;
        $stripAccents = ($input['strip_accents'] ?? false) === true;

        // Like the API (1.2.0): a replay returns the same response, without a new SMS.
        $fingerprint = hash('sha256', serialize([$phone, $template, $locale, $webOtpDomain, $stripAccents]));
        if ($idempotencyKey !== '' && isset($this->otpIdempotency[$idempotencyKey])) {
            $previous = $this->otpIdempotency[$idempotencyKey];
            if ($previous['fingerprint'] !== $fingerprint) {
                return self::error(409, 'IDEMPOTENCY_KEY_REUSED', "Cette clé d'idempotence a déjà servi pour une requête différente.");
            }

            return self::json(201, ['data' => $previous['data']], ['Idempotent-Replayed' => 'true']);
        }

        // A new code invalidates the previous ones for this number.
        foreach ($this->otps as $previous) {
            if ($previous->phone === $phone) {
                $this->otpState[$previous->otpId]['used'] = true;
            }
        }

        $otpId = self::uuid();
        $code = $this->otpCode ?? str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);
        $this->otps[] = new SentOtp($otpId, $phone, $code, $template, $locale, $webOtpDomain, $stripAccents, $idempotencyKey !== '' ? $idempotencyKey : null);
        $this->otpState[$otpId] = ['used' => false, 'attempts' => 0];

        $data = [
            'otp_id' => $otpId,
            'expires_at' => gmdate('Y-m-d\TH:i:sP', time() + 600),
            'max_attempts' => $this->otpMaxAttempts,
            'code_length' => \strlen($code),
            'code_type' => ctype_digit($code) ? 'numeric' : 'alphanumeric',
            'resend_after_seconds' => $this->otpResendAfterSeconds,
            'test_code' => $code,
        ];
        if ($idempotencyKey !== '') {
            $this->otpIdempotency[$idempotencyKey] = ['fingerprint' => $fingerprint, 'data' => $data];
        }

        return self::json(201, ['data' => $data]);
    }

    /**
     * @param array<mixed> $input
     */
    private function verifyOtp(array $input): ResponseInterface
    {
        $otpId = \is_string($input['otp_id'] ?? null) ? $input['otp_id'] : '';
        $code = \is_string($input['code'] ?? null) ? strtolower((string) preg_replace('/\s+/', '', $input['code'])) : '';

        $otp = null;
        foreach ($this->otps as $candidate) {
            if ($candidate->otpId === $otpId) {
                $otp = $candidate;
            }
        }
        if ($otp === null || !isset($this->otpState[$otpId])) {
            return self::error(404, 'NOT_FOUND', 'Code introuvable.');
        }

        $state = &$this->otpState[$otpId];
        $result = match (true) {
            $state['used'] => ['verified' => false, 'reason' => 'already_used', 'attempts_remaining' => null],
            $state['attempts'] >= $this->otpMaxAttempts => ['verified' => false, 'reason' => 'max_attempts_reached', 'attempts_remaining' => 0],
            hash_equals(strtolower($otp->code), $code) => ['verified' => true, 'reason' => null, 'attempts_remaining' => null],
            default => ['verified' => false, 'reason' => 'invalid_code', 'attempts_remaining' => null],
        };

        if ($result['verified']) {
            $state['used'] = true;
        } elseif ($result['reason'] === 'invalid_code') {
            ++$state['attempts'];
            // Like the API: the last wrong attempt blocks the code.
            $result['attempts_remaining'] = max(0, $this->otpMaxAttempts - $state['attempts']);
            if ($result['attempts_remaining'] === 0) {
                $result['reason'] = 'max_attempts_reached';
            }
        }

        return self::json(200, ['data' => $result]);
    }

    /**
     * Same rule as the API today: ASCII → GSM-7 (160 / 153), otherwise UCS-2 (70 / 67).
     *
     * @return array{string, int}
     */
    private static function segments(string $body): array
    {
        $length = mb_strlen($body);
        if (preg_match('/^[\x00-\x7F]*$/', $body) === 1) {
            return ['gsm7', $length <= 160 ? 1 : (int) ceil($length / 153)];
        }

        return ['ucs2', $length <= 70 ? 1 : (int) ceil($length / 67)];
    }

    /**
     * Rough equivalent of the API's strip_accents: accents removed, typographic
     * quotes and dashes replaced, any other non-ASCII character dropped.
     */
    private static function stripAccents(string $body): string
    {
        $body = strtr($body, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '«' => '"', '»' => '"', '–' => '-', '—' => '-', '…' => '...', "\u{00A0}" => ' ']);
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($body, \Normalizer::FORM_D);
            $body = \is_string($decomposed) ? $decomposed : $body;
        }
        $body = (string) preg_replace('/\p{Mn}+/u', '', $body);

        return (string) preg_replace('/[^\x00-\x7F]/u', '', $body);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @param array<mixed>          $body
     * @param array<string, string> $headers
     */
    private static function json(int $status, array $body, array $headers = []): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<mixed>          $details
     * @param array<string, string> $headers
     */
    private static function error(int $status, string $code, string $message, array $details = [], array $headers = []): ResponseInterface
    {
        return self::json($status, ['error' => ['code' => $code, 'message' => $message, 'details' => (object) $details]], $headers);
    }
}

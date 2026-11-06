<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Internal;

use Labyrinthe\Connect\Exception\ApiException;
use Labyrinthe\Connect\Exception\ConnectionException;
use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\Exception\UnexpectedValueException;
use Labyrinthe\Connect\Http\ResponseInfo;
use Labyrinthe\Connect\Http\Sleeper;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends requests, applies the retry policy and maps errors.
 *
 * @internal
 */
final class Transport
{
    /** Valid X-Request-Id values (the API replaces any other value by a UUID). */
    public const REQUEST_ID_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/';

    private const BASE_DELAY = 0.5;
    private const MAX_BACKOFF = 8.0;

    /** Feature shut down by the platform (maintenance): only retried after a Retry-After. */
    private const FEATURE_DISABLED_CODES = ['FEATURE_DISABLED', 'OTP_SERVICE_DISABLED'];

    private ?ResponseInfo $lastResponse = null;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly int $maxRetries,
        private readonly float $maxRetryWait,
        private readonly Sleeper $sleeper,
        private readonly LoggerInterface $logger,
        private readonly string $userAgent,
    ) {
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null  $body      JSON body
     * @param array<string, string>      $headers
     * @param string|null                $requestId sent as X-Request-Id on every attempt
     *
     * @throws ApiException
     * @throws ConnectionException
     */
    public function request(
        string $method,
        string $path,
        RetryMode $mode,
        array $query = [],
        #[\SensitiveParameter]
        ?array $body = null,
        array $headers = [],
        ?string $requestId = null,
    ): Response {
        if ($requestId !== null) {
            self::assertRequestId($requestId);
            $headers['X-Request-Id'] = $requestId;
        }
        $idempotent = isset($headers['Idempotency-Key']);

        $query = array_filter($query, static fn (mixed $v): bool => $v !== null);
        $queryString = $query === [] ? '' : http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
        $url = $this->baseUrl.$path.($queryString === '' ? '' : '?'.$queryString);
        $logPath = Redactor::path($path, $queryString);
        $payload = $body === null ? null : json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        for ($attempt = 0;; ++$attempt) {
            $request = $this->requestFactory->createRequest($method, $url)
                ->withHeader('Authorization', 'Bearer '.$this->apiKey)
                ->withHeader('Accept', 'application/json')
                ->withHeader('User-Agent', $this->userAgent);
            foreach ($headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            if ($payload !== null) {
                $request = $request
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($this->streamFactory->createStream($payload));
            }

            $this->logger->debug('Labyrinthe Connect request', ['method' => $method, 'path' => $logPath, 'attempt' => $attempt + 1]);
            $startedAt = microtime(true);

            try {
                $response = $this->http->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                $timeout = self::isTimeout($e);
                if ($mode === RetryMode::Safe && $attempt < $this->maxRetries) {
                    $delay = $this->backoff($attempt);
                    $this->logger->warning('Labyrinthe Connect network error, retrying', [
                        'method' => $method, 'path' => $logPath, 'attempt' => $attempt + 1, 'delay' => round($delay, 3), 'timeout' => $timeout,
                    ]);
                    $this->sleeper->sleep($delay);

                    continue;
                }

                $this->logger->error('Labyrinthe Connect network error', ['method' => $method, 'path' => $logPath, 'timeout' => $timeout]);

                throw new ConnectionException($this->connectionMessage($method, $path, $mode, $e), $timeout);
            }

            $status = $response->getStatusCode();
            $responseHeaders = self::headers($response);
            $rawBody = (string) $response->getBody();
            $this->lastResponse = new ResponseInfo($status, $responseHeaders);
            $this->logger->debug('Labyrinthe Connect response', [
                'method' => $method,
                'path' => $logPath,
                'status' => $status,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'replayed' => ($responseHeaders['idempotent-replayed'] ?? null) === 'true',
                'request_id' => $responseHeaders['x-request-id'] ?? null,
            ]);

            if ($status >= 200 && $status < 300) {
                return new Response($status, self::decode($rawBody), $responseHeaders);
            }

            $error = ErrorFactory::fromResponse($status, $rawBody, $responseHeaders);

            if ($attempt < $this->maxRetries && self::isRetryable($mode, $error, $idempotent)) {
                $delay = $this->retryDelay($error, $attempt);
                if ($delay !== null) {
                    $this->logger->warning('Labyrinthe Connect request failed, retrying', [
                        'method' => $method, 'path' => $logPath, 'status' => $status, 'code' => $error->getErrorCode(),
                        'attempt' => $attempt + 1, 'delay' => round($delay, 3), 'request_id' => $error->getRequestId(),
                    ]);
                    $this->sleeper->sleep($delay);

                    continue;
                }
            }

            $this->logger->info('Labyrinthe Connect API error', [
                'method' => $method, 'path' => $logPath, 'status' => $status, 'code' => $error->getErrorCode(),
                'request_id' => $error->getRequestId(),
            ]);

            throw $error;
        }
    }

    /** Metadata of the last response received (success or error), null before the first one. */
    public function lastResponse(): ?ResponseInfo
    {
        return $this->lastResponse;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function assertRequestId(string $requestId): void
    {
        if (preg_match(self::REQUEST_ID_PATTERN, $requestId) !== 1) {
            throw new InvalidArgumentException('requestId must be 1 to 128 characters among A-Z a-z 0-9 . _ : -');
        }
    }

    private static function isRetryable(RetryMode $mode, ApiException $error, bool $idempotent): bool
    {
        // Refused before any processing, in every mode, but never with a blind backoff:
        // only when an automatic re-enable is scheduled (Retry-After, see retryDelay()).
        if (self::isFeatureDisabled($error)) {
            return $error->getHeader('retry-after') !== null;
        }

        // Only the generic throttle: OTP_RESEND_TOO_SOON / OTP_RATE_LIMITED are business limits.
        if ($error->getStatusCode() === 429) {
            return $error->getErrorCode() === 'TOO_MANY_REQUESTS';
        }

        // The original request with this key is still running: replaying the same key is safe.
        if ($error->getErrorCode() === 'IDEMPOTENCY_REQUEST_IN_PROGRESS') {
            return $idempotent;
        }

        return $mode === RetryMode::Safe && $error->getStatusCode() >= 500;
    }

    /**
     * Seconds to wait before the next attempt, or null to give up (the API
     * asks to wait longer than the configured maximum).
     */
    private function retryDelay(ApiException $error, int $attempt): ?float
    {
        if (self::isFeatureDisabled($error)) {
            $retryAfter = self::retryAfterHeader($error);

            return $retryAfter !== null && $retryAfter <= $this->maxRetryWait ? $retryAfter : null;
        }

        if ($error->getStatusCode() === 429 || $error->getErrorCode() === 'IDEMPOTENCY_REQUEST_IN_PROGRESS') {
            $retryAfter = self::retryAfter($error);
            if ($retryAfter !== null) {
                return $retryAfter <= $this->maxRetryWait ? $retryAfter : null;
            }
        }

        return $this->backoff($attempt);
    }

    /** Exponential backoff with jitter: 0.5 s, 1 s, 2 s… (50 to 100 % of it), capped. */
    private function backoff(int $attempt): float
    {
        $delay = min(self::MAX_BACKOFF, self::BASE_DELAY * (2 ** $attempt));
        $jitter = 0.5 + (mt_rand() / mt_getrandmax()) * 0.5;

        return min($this->maxRetryWait, $delay * $jitter);
    }

    private static function isFeatureDisabled(ApiException $error): bool
    {
        return \in_array($error->getErrorCode(), self::FEATURE_DISABLED_CODES, true);
    }

    private static function retryAfter(ApiException $error): ?float
    {
        $header = self::retryAfterHeader($error);
        if ($header !== null) {
            return $header;
        }

        $seconds = $error->getDetails()['retry_after_seconds'] ?? null;

        return \is_int($seconds) || \is_float($seconds) ? (float) $seconds : null;
    }

    /** Seconds from the Retry-After header (delta-seconds or HTTP date), null when absent or unreadable. */
    private static function retryAfterHeader(ApiException $error): ?float
    {
        $header = $error->getHeader('retry-after');
        if ($header === null) {
            return null;
        }
        $header = trim($header);
        if (preg_match('/^\d+(\.\d+)?$/', $header) === 1) {
            return (float) $header;
        }
        $date = strtotime($header);

        return $date !== false ? (float) max(0, $date - time()) : null;
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $rawBody): array
    {
        if (trim($rawBody) === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new UnexpectedValueException('Unexpected API response: the body is not valid JSON.');
        }

        if (!\is_array($decoded)) {
            throw new UnexpectedValueException('Unexpected API response: a JSON object was expected.');
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(ResponseInterface $response): array
    {
        $headers = [];
        foreach (array_keys($response->getHeaders()) as $name) {
            $headers[strtolower((string) $name)] = $response->getHeaderLine((string) $name);
        }

        return $headers;
    }

    private static function isTimeout(ClientExceptionInterface $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'timed out') || str_contains($message, 'curl error 28') || str_contains($message, 'timeout');
    }

    private function connectionMessage(string $method, string $path, RetryMode $mode, ClientExceptionInterface $e): string
    {
        // The underlying message may contain the full URL: drop its query string (phone numbers).
        $reason = (string) preg_replace('/\?[^\s"\']*/', '?…', $e->getMessage());
        $message = \sprintf('Could not reach the Labyrinthe Connect API (%s %s): %s', $method, $path, $reason);

        if ($mode === RetryMode::OnlyWhenThrottled) {
            $message .= ' The request may have been processed; it was not retried automatically.';
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => Redactor::apiKey($this->apiKey),
            'baseUrl' => $this->baseUrl,
            'maxRetries' => $this->maxRetries,
        ];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('The Labyrinthe Connect transport cannot be serialized (it holds the API key).');
    }
}

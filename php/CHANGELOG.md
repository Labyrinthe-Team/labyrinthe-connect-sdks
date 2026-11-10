# Changelog

All notable changes to `labyrinthe/connect-php` are documented here. This project follows
[Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [1.2.1] - 2026-11-09

### Fixed
- Default API URL is now `https://api.connect.labyrinthe-rdc.com/api/public/v1`. The previous default (`api.labyrinthe-rdc.com`) does not serve
  the Labyrinthe Connect API, so requests failed unless the URL was set explicitly. An explicitly configured URL
  is unchanged.
- Links to the website now point to https://connect.labyrinthe-rdc.com.

## [1.2.0] - 2026-11-06

Built against the API specification 1.3.0. Backward compatible with 1.1.x.

### Added
- `FeatureDisabledException` (503 `FEATURE_DISABLED`, and the older 503 `OTP_SERVICE_DISABLED` of `POST /otp/send`):
  a platform administrator temporarily shut down the whole API or one feature (maintenance); nothing was done (no
  SMS sent, no credit debited). Extends `ServiceUnavailableException` (now non-final), so existing `catch` blocks keep
  working. `getFeature()`, `getBlockedBy()`, `getAdminMessage()`, `getUntil()` (`DateTimeImmutable`, null when no
  re-enable is scheduled) and `getRetryAfter()`. Missing or malformed `details` give `null`, never an error.

### Changed
- `FEATURE_DISABLED` and `OTP_SERVICE_DISABLED` are no longer retried with exponential backoff. They are retried, on
  every operation including `otp->verify()` (the request was refused before processing), only when the API sends a
  `Retry-After` no longer than `maxRetryWait`, after exactly that delay; otherwise thrown at once. Other 5xx are
  unchanged.

## [1.1.1] - 2026-11-06

### Fixed
- Phone numbers are now sent to the API in E.164 with `+` (`recipient`, `recipients[]` of `sms->send()`,
  `sendMany()` and `estimate()`, the `recipient` filter of `sms->list()`, `phone` of `otp->send()`). A foreign number
  of 10 digits or less (`+2481234567`, Seychelles) was sent without `+` and read by the API as a local DRC number
  (`2432481234567`). `PhoneNumber::normalize()` still returns digits without `+` (the format of API responses).

### Added
- `PhoneNumber::toE164()` and `PhoneNumber::toE164OrFail()`: the normalised number with `+`, as sent to the API.
- `IdempotencyKeyUnresolvedException` (409 `IDEMPOTENCY_KEY_UNRESOLVED`, `POST /sms` and `POST /otp/send`): the
  original request with this `Idempotency-Key` failed unexpectedly and its outcome is unknown (an SMS may have been
  sent). Never retried: check (`sms->list(reference: …)` or with the user), then send again with a new key.

### Tests
- The integration test of a short foreign number (`+2481234567` → recipient `2481234567`) is no longer marked
  incomplete: `POST /sms` no longer normalises the recipient twice (fixed on the API side).

## [1.1.0] - 2026-11-06

Built against the API specification 1.2.0. Backward compatible with 1.0.0.

### Added
- Request id: `requestId:` option on every call (sent as `X-Request-Id` on every attempt, validated locally);
  `ApiException::getApiVersion()`; `Client::lastResponse()` returns a `Http\ResponseInfo` (status, headers,
  `requestId()`, `apiVersion()`, `isReplayed()`, `rateLimitLimit()`, `rateLimitRemaining()`) for low-level access.
- `IdempotencyRequestInProgressException` (409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`, `getRetryAfter()`), retried
  automatically after `Retry-After` with the same `Idempotency-Key` (`POST /sms`, `POST /otp/send`).
- `AccountSuspendedException` (403 `ACCOUNT_SUSPENDED`), a subclass of `PermissionException` (now non-final).
- OTP send: automatic `Idempotency-Key` (UUID v4, or `idempotencyKey:`), `webOtpDomain:` (Web OTP API) and
  `stripAccents:`; `OtpSendResult` gains `codeLength`, `codeType` (`Enum\OtpCodeType`: numeric, alphanumeric,
  unknown), `resendAfterSeconds`, `resendAvailableAt()`, `replayed` and `idempotencyKey`.
- OTP verify: `OtpVerifyResult::$attemptsRemaining`.
- SMS: `stripAccents:` on `send()`, `sendMany()` and `estimate()`.
- Webhooks: `OtpVerified` and `OtpFailed` events (`Enum\OtpWebhookStatus`), `WebhookEvent::OtpVerified` /
  `OtpFailed`; `MessageStatusChanged` gains `reference`, `errorCode` and `errorMessage`.

### Changed
- `otp->send()` is now retried on network errors, 5xx, 429 `TOO_MANY_REQUESTS` and `IDEMPOTENCY_REQUEST_IN_PROGRESS`
  (safe thanks to its `Idempotency-Key`: a replay returns the same `otpId`, no second SMS). `OTP_RESEND_TOO_SOON`
  and `OTP_RATE_LIMITED` are still never retried; `otp->verify()` is still only retried on 429 `TOO_MANY_REQUESTS`.
- Phone numbers follow the API 1.2.0: without `+`, 11 digits or more not starting with 0 are already international
  (`33612345678` is no longer prefixed with 243). DR Congo formats are unchanged.
- `list()` sends `DateTimeInterface` filters as `2026-11-06T09:39:11+00:00` (the API date format); `Z` and
  microsecond dates are still parsed.
- `OtpRateLimitedException::getRetryAfter()` and `OtpResendTooSoonException::getRetryAfter()` read the
  `Retry-After` header now sent by the API (then `details.retry_after_seconds`).
- `error.details` is always an object since API 1.2.0; a legacy `[]` is still accepted.
- Retry and error logs include the `request_id`.

## [1.0.0] - 2026-11-06

First stable release, built against the API specification 1.1.0.

### Added
- `Client` (`new Client($apiKey, …)` or `Client::fromEnv()`), test/live mode detection, Guzzle by default or any
  PSR-18 client with PSR-17 factories.
- SMS: `send()`, `sendMany()` (all or nothing), `estimate()`, `get()`, `list()` with filters and `autoPaging()`.
- Balance and approved Sender IDs.
- OTP: `send()` (with `testCode()` in test mode) and `verify()`.
- Automatic `Idempotency-Key` (UUID v4) on every SMS send, reused across retries; `MessageBatch::$replayed`.
- Retries with exponential backoff and jitter, only when safe (GET, estimate, SMS send with key; never OTP after a
  network error or 5xx); `Retry-After` support.
- Typed exceptions for every documented error code, `ConnectionException` for network errors and timeouts.
- `Webhook::constructEvent()`: constant-time HMAC-SHA256 verification on the raw body, timestamp tolerance, typed
  events (`MessageStatusChanged`, `CampaignCompleted`, `BalanceLow`, `TestPing`, `UnknownEvent`).
- `PhoneNumber` helpers (normalisation identical to the API, validation, masking).
- Optional PSR-3 logging with masked phone numbers; the API key, OTP codes and message bodies are never logged,
  dumped or serialised.

[Unreleased]: https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/compare/php-v1.2.1...HEAD
[1.2.1]: https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/compare/php-v1.2.0...php-v1.2.1
[1.2.0]: https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/compare/php-v1.1.0...php-v1.2.0
[1.1.0]: https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/compare/php-v1.0.0...php-v1.1.0
[1.0.0]: https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/releases/tag/php-v1.0.0

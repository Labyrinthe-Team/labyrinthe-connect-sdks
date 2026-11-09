# Changelog

All notable changes to `@labyrinthe/connect` are documented here. This project follows
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
- `FeatureDisabledError` (503 `FEATURE_DISABLED`, extends `ServiceUnavailableError`): a platform administrator
  temporarily shut down the whole API (`blockedBy` = `"api"`) or one function (`"api.sms_send"`, `"api.otp_send"`,
  `"api.otp_verify"`). Nothing was done. Read-only `feature`, `blockedBy`, `adminMessage` (`details.message`), `until`
  (`Date`, `null` when unknown or invalid), `retryAfter` (seconds, from `Retry-After`) and `retryAfterMs`. Missing or
  malformed `details` are tolerated (`null`). `FEATURE_DISABLED` added to `ApiErrorCode`.

### Changed
- 503 `OTP_SERVICE_DISABLED` (whole OTP service off) is now thrown as `FeatureDisabledError` too (still an
  `instanceof ServiceUnavailableError`), with the same details (`feature` = `blockedBy` = `"otp"`).
- `FEATURE_DISABLED` and `OTP_SERVICE_DISABLED` are never retried with exponential backoff: they are retried, for
  every call including `otp.verify()` (refused before processing), only when the API sends `Retry-After` (automatic
  re-enable scheduled) no longer than `maxRetryWaitMs`, after exactly that delay; otherwise thrown at once. Other
  5xx keep the previous behaviour.

## [1.1.1] - 2026-11-06

### Added
- `IdempotencyKeyUnresolvedError` (409 `IDEMPOTENCY_KEY_UNRESOLVED`, `sms.send()`, `sms.sendMany()`, `otp.send()`):
  the original request with this Idempotency-Key failed unexpectedly and its outcome is unknown (an SMS may have been
  sent). Never retried by the SDK; check (`sms.list({ reference })` or with the user), then send again with a new key.

### Fixed
- Phone numbers are sent to the API in E.164 with "+" (`recipient`, `recipients[]`, `estimate()` recipients, OTP
  `phone` and the `recipient` filter of `sms.list()`). Without it, the API read a short foreign number (10 digits or
  less) as a local DR Congo number: `+2481234567` (Seychelles) was sent as `2481234567` and became `2432481234567`.
  `PhoneNumber.normalize()` still returns digits without "+", the format of API responses. Requires no code change.

## [1.1.0] - 2026-11-06

Built against the API specification 1.2.0. Backward compatible with 1.0.0, except that `OtpVerifyResult` gains
`attemptsRemaining` (deep-equality checks on the whole result must include it).

### Added
- `X-Request-Id`: per-call `requestId` option (sent on every retry of the call, validated locally), `requestId` and
  `apiVersion` (`X-Api-Version`) on every `ApiError`, `requestId` on `MessageBatch` and `OtpSendResult`, and an
  `onResponse` option receiving `{ status, requestId, apiVersion, replayed, rateLimit, headers }`. Logged with each
  response and error.
- `otp.send()` is idempotent: an `Idempotency-Key` is always sent (generated, or your `idempotencyKey`) and reused by
  retries; `replayed` and `idempotencyKey` on the result.
- `otp.send()` returns `codeLength`, `codeType` (`OtpCodeType`: `numeric` | `alphanumeric`) and
  `resendAfterSeconds`; accepts `webOtpDomain` (Web OTP API, validated against the spec pattern, lower-cased) and
  `stripAccents`.
- `otp.verify()` returns `attemptsRemaining` (`number | null`).
- `stripAccents` on `sms.send()`, `sms.sendMany()` and `sms.estimate()`.
- `AccountSuspendedError` (403 `ACCOUNT_SUSPENDED`, extends `PermissionError`) and
  `IdempotencyRequestInProgressError` (409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`, `retryAfterMs`).
- Webhooks: `message.status_changed` gains `reference`, `errorCode`, `errorMessage`; new `otp.verified` and
  `otp.failed` events in the `WebhookEvent` union (`OtpVerifiedEvent`, `OtpFailedEvent`, `OtpWebhookData`,
  `OtpVerificationStatus`); `WebhookEventType.OtpVerified` / `OtpFailed`. The Express and Next.js helpers receive
  them typed.

### Changed
- Retries: `otp.send()` is now retried like SMS sends (network error, timeout, 5xx, 429 `TOO_MANY_REQUESTS`), always
  with the same key; `409 IDEMPOTENCY_REQUEST_IN_PROGRESS` is retried after `Retry-After` with the same key for
  `sms.send()`, `sms.sendMany()` and `otp.send()`. `otp.verify()` is still retried only on 429
  `TOO_MANY_REQUESTS`; `OTP_RESEND_TOO_SOON` and `OTP_RATE_LIMITED` are never retried.
- `retryAfterMs` reads the `Retry-After` header (seconds or HTTP date) now sent with `OTP_RESEND_TOO_SOON` and
  `OTP_RATE_LIMITED`, then `details.retry_after_seconds`.
- Phone numbers, aligned with the API: without "+", 11 digits or more not starting with 0 are already international
  (`33612345678` stays `33612345678` instead of becoming `24333612345678`). DR Congo formats are unchanged.
- The 1.2.0 response fields are read tolerantly (`null` when an older backend omits them); `error.details` `[]` and
  `Z` dates are still accepted.

## [1.0.0] - 2026-11-06

First stable release, built against the API specification 1.1.0.

### Added
- `new Labyrinthe({ apiKey, baseUrl, timeoutMs, maxRetries, maxRetryWaitMs, defaultCountryCode, defaultSender, fetch, logger })`,
  `LABYRINTHE_API_KEY` / `LABYRINTHE_BASE_URL` read from the environment, `isTestMode` / `isLiveMode`.
- SMS: `send()`, `sendMany()` (all or nothing, `replayed`), `estimate()`, `get()`, `list()` with filters; awaitable
  for one page, async-iterable over every page (`for await`).
- Balance and approved Sender IDs.
- OTP: `send()` (with `testCode` in test mode, hidden from logs and JSON) and `verify()` (discriminated result).
- Automatic `Idempotency-Key` (`crypto.randomUUID()`) on every SMS send, reused across retries.
- Retries with exponential backoff and jitter, only when safe (GET, estimate, SMS send; OTP only on
  429 `TOO_MANY_REQUESTS`); `Retry-After` support capped by `maxRetryWaitMs`; per-call `signal` and `timeoutMs`.
- Typed errors for every documented error code (`LabyrintheError` → `ApiError` → …), `ConnectionError`,
  `TimeoutError`, `RequestAbortedError`, `InvalidArgumentError`.
- `Webhooks.constructEvent()` with Web Crypto: constant-time HMAC-SHA256 verification on the raw body, timestamp
  tolerance, discriminated union on `event`; `Webhooks.sign()` and `verifySignature()`.
- `@labyrinthe/connect/express` (middleware) and `@labyrinthe/connect/next` (App Router route handler).
- `PhoneNumber` helpers (normalisation identical to the API, validation, masking).
- ESM + CommonJS + types; zero runtime dependency; Node.js 18+, edge runtimes, Deno and Bun.

[Unreleased]: https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/compare/node-v1.2.1...HEAD
[1.2.1]: https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/compare/node-v1.2.0...node-v1.2.1
[1.2.0]: https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/compare/node-v1.1.1...node-v1.2.0
[1.1.1]: https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/compare/node-v1.1.0...node-v1.1.1
[1.1.0]: https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/compare/node-v1.0.0...node-v1.1.0
[1.0.0]: https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/releases/tag/node-v1.0.0

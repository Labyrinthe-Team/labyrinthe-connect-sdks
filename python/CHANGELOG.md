# Changelog

All notable changes to `labyrinthe-connect` (Python) are documented here. This project follows
[Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [1.1.1] - 2026-11-06

### Added
- `IdempotencyKeyUnresolvedError` (409 `IDEMPOTENCY_KEY_UNRESOLVED`, `sms.send`, `sms.send_many` and `otp.send`): the
  original request with this `Idempotency-Key` failed unexpectedly and its outcome is unknown (an SMS may have been
  sent). Never retried: check (`sms.list(reference=...)` or with the user), then send again with a new key.

### Fixed
- Phone numbers are now sent to the API in E.164 with their `+` (`recipient`, `recipients`, the OTP `phone` and the
  `recipient` filter of `sms.list()`). Without it, the API read a short foreign number (10 digits or fewer, e.g.
  `+2481234567`, Seychelles) as a local DR Congo number and sent it to `2432481234567`. `phone.normalize()` still
  returns digits without `+` (the format of the API responses).

## [1.1.0] - 2026-11-06

Built against the API specification 1.2.0. Backwards compatible with 1.0.0.

### Added
- `request_id` (`X-Request-Id`) on every error (`LabyrintheError.request_id`; for a network error, the id you sent)
  and on every returned object (`Message`, `MessageBatch`, `Estimate`, `Balance`, `SenderId`, `OtpSendResult`,
  `OtpVerifyResult`, pages), left out of equality and `repr()`. `ApiError.api_version` (`X-Api-Version`).
- `request_id=` on every method: sent as `X-Request-Id` on every attempt (checked locally against the spec pattern).
- `IdempotencyRequestInProgressError` (409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`, `retry_after`): retried automatically
  after `Retry-After` with the same `Idempotency-Key` for `sms.send`, `sms.send_many` and `otp.send`.
- `AccountSuspendedError` (403 `ACCOUNT_SUSPENDED`), a subclass of `PermissionError`.
- `otp.send()`: automatic `Idempotency-Key` (`uuid4`, or `idempotency_key=`) reused across retries; `web_otp_domain`
  and `strip_accents` parameters; `OtpSendResult.code_length`, `code_type` (`OtpCodeType`: `numeric`,
  `alphanumeric`, `unknown`), `resend_after_seconds`, `replayed`, `idempotency_key`.
- `OtpVerifyResult.attempts_remaining`.
- `strip_accents` on `sms.send()`, `sms.send_many()` and `sms.estimate()`.
- Webhooks: `MessageStatusChanged.data.reference`, `error_code`, `error_message`; new events `OtpVerified`
  (`otp.verified`) and `OtpFailed` (`otp.failed`) with `OtpWebhookData` and `OtpVerificationStatus`; Django signals
  `otp_verified` and `otp_failed`; FastAPI and Flask examples updated.

### Changed
- `otp.send()` is now retried, with the same `Idempotency-Key`, after a network error, a 5xx, a 429
  `TOO_MANY_REQUESTS` or a 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` (never after `OTP_RESEND_TOO_SOON` or
  `OTP_RATE_LIMITED`). `otp.verify()` is still retried only on 429 `TOO_MANY_REQUESTS`.
- Phone normalisation aligned with the API: without `+`, 11 digits or more not starting with 0 are already
  international (`33612345678` is no longer prefixed with 243). DR Congo formats are unchanged.
- `OtpVerifyResult` equality now includes `attempts_remaining`.
- `retry_after` of `OtpRateLimitedError` and `OtpResendTooSoonError` reads the `Retry-After` header now sent by the API.
- Fields added by the API 1.2.0 are read leniently (`None` / `UNKNOWN` when an older backend omits them); `[]`
  error details and `Z` / fractional dates are still accepted.

## [1.0.0] - 2026-11-06

First stable release, built against the API specification 1.1.0.

### Added
- `Labyrinthe` (synchronous) and `AsyncLabyrinthe` (asyncio) clients with the same surface:
  `api_key`, `base_url`, `timeout`, `max_retries`, `max_retry_wait`, `default_country_code`, `default_sender`,
  `http_client`; `LABYRINTHE_API_KEY` / `LABYRINTHE_BASE_URL` read from the environment; context managers;
  `is_test_mode` / `is_live_mode`.
- SMS: `send()`, `send_many()` (all or nothing, `replayed`), `estimate()`, `get()`, `list()` with filters and
  pagination (`auto_paging_iter()`, `for` / `async for`).
- Balance and approved Sender IDs.
- OTP: `send()` (with `test_code` in test mode, hidden from `repr()` and dropped when pickled) and `verify()`.
- Automatic `Idempotency-Key` (`uuid4`) on every SMS send, reused across retries.
- Retries with exponential backoff and jitter, only when safe (GET, estimate, SMS send; OTP only on
  429 `TOO_MANY_REQUESTS`); `Retry-After` support capped by `max_retry_wait`; no redirect following.
- Typed errors for every documented error code (`LabyrintheError` → `ApiError` → …), `APIConnectionError`,
  `APITimeoutError`, `InvalidArgumentError`; the original `httpx` exception is never chained.
- `webhooks.construct_event()`: constant-time HMAC-SHA256 verification on the raw body, timestamp tolerance, typed
  frozen events (`MessageStatusChanged`, `CampaignCompleted`, `BalanceLow`, `TestPing`, `UnknownEvent`);
  `sign()` and `verify_signature()`.
- Optional integrations: `contrib.django` (view + signals), `contrib.fastapi` (dependency + router),
  `contrib.flask` (blueprint + signal); extras `[django]`, `[fastapi]`, `[flask]`.
- `phone` helpers (normalisation identical to the API, validation, masking); `logging` logger
  `labyrinthe_connect` (no key, code, body or full number); fully typed (`py.typed`); only dependency: `httpx`.

[Unreleased]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/python-v1.1.1...HEAD
[1.1.1]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/python-v1.1.0...python-v1.1.1
[1.1.0]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/python-v1.0.0...python-v1.1.0
[1.0.0]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/releases/tag/python-v1.0.0

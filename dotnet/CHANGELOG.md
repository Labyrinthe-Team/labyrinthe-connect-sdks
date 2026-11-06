# Changelog

All notable changes to `Labyrinthe.Connect` and `Labyrinthe.Connect.AspNetCore` (.NET) are documented here. This
project follows [Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [1.1.1] - 2026-11-06

### Added
- `IdempotencyKeyUnresolvedException` (409 `IDEMPOTENCY_KEY_UNRESOLVED`, a subclass of `ApiException`): the original
  `Sms.SendAsync` / `SendManyAsync` / `Otp.SendAsync` request with this `Idempotency-Key` failed unexpectedly and its
  outcome is unknown (an SMS may have been sent). Never retried; check (`ListAsync` with `Reference`, or ask the user)
  and resend with a new key.

### Fixed
- Phone numbers are now sent to the API in E.164 with "+" (`+2481234567`) in `Sms.SendAsync`, `SendManyAsync`,
  `EstimateAsync`, the `Recipient` filter of `ListAsync` / `ListAutoPagingAsync` and `Otp.SendAsync`. Without "+", the API
  reads a number of 10 digits or fewer as a local DR Congo number: `+2481234567` (Seychelles) was sent as
  `2481234567` and became `2432481234567`. `PhoneNumber.Normalize()` still returns digits without "+" (the format of
  the API responses). Request bodies no longer escape "+" and non-ASCII characters (plain UTF-8 JSON).
  Since the API fix of `POST /sms` (BACKEND-TODO n° 34), a short foreign number keeps its country code on every
  endpoint; the integration test checks it again.

## [1.1.0] - 2026-11-06

Built against the API specification 1.2.0. Backward compatible with 1.0.0 (additions only; older API responses are
still accepted).

### Added
- Request identifiers: `ApiException.RequestId` (`X-Request-Id`, now on every response) and `ApiException.ApiVersion`
  (`X-Api-Version`); `RequestId` on `MessageBatch`, `Estimate`, `Balance`, `OtpSendResult`, `OtpVerifyResult` and
  `Page<T>` (not part of record equality); `client.WithRequestId("…")` sends your own `X-Request-Id` (validated
  against `^[A-Za-z0-9._:-]{1,128}$`) on every request and retry of that lightweight view. The request id is also in
  the SDK log lines.
- `IdempotencyRequestInProgressException` (409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`, `RetryAfter`): SMS and OTP sends are
  retried automatically after `Retry-After` with the same `Idempotency-Key` (within `MaxRetries` / `MaxRetryWait`).
- `AccountSuspendedException` (403 `ACCOUNT_SUSPENDED`), a subclass of `PermissionException`.
- OTP send: an `Idempotency-Key` is always sent (`SendOtpRequest.IdempotencyKey`, or a generated `Guid` reused across
  retries), so the send is now retried safely after a network error, a timeout, a 5xx, 429 `TOO_MANY_REQUESTS` or
  `IDEMPOTENCY_REQUEST_IN_PROGRESS` (never after `OTP_RESEND_TOO_SOON` / `OTP_RATE_LIMITED`); `OtpSendResult.Replayed`
  and `IdempotencyKey`; new options `WebOtpDomain` (Web OTP API, checked against the spec pattern) and `StripAccents`;
  new fields `CodeLength`, `CodeType` (`OtpCodeType`: `Numeric`, `Alphanumeric`, `Unknown`) and `ResendAfterSeconds`.
- OTP verify: `OtpVerifyResult.AttemptsRemaining`.
- `StripAccents` on `SendSmsRequest`, `SendManySmsRequest` and `EstimateSmsRequest`.
- Webhooks: `MessageStatusChangedData.Reference`, `ErrorCode`, `ErrorMessage`; new typed events `OtpVerified` and
  `OtpFailed` (`OtpWebhookData`: `OtpId`, `Phone` (masked in `ToString()`), `Status` (`OtpWebhookStatus`), `Reason`,
  `VerifiedAt`), also delivered as such by `MapLabyrintheWebhooks()`.

### Changed
- Phone numbers: without `+`, 11 digits or more not starting with 0 are already international (`33612345678` is kept
  instead of becoming `24333612345678`), as in the API 1.2.0. DR Congo formats are unchanged.
- `RateLimitException.RetryAfter` of `OtpResendTooSoonException` / `OtpRateLimitedException` now reads the
  `Retry-After` header sent by the API 1.2.0 (falls back to `details.retry_after_seconds`).
- `otp.verified` / `otp.failed` webhooks are no longer `UnknownEvent`s.
- Tolerances kept: `error.details` as `[]`, `null` message encoding, dates with `Z` or `+00:00`, OTP responses without
  the 1.2.0 fields.

## [1.0.0] - 2026-11-06

First stable release, built against the API specification 1.1.0.

### Added
- `LabyrintheClient` (thread-safe, `IDisposable`) with `LabyrintheClientOptions`: `ApiKey`, `BaseUrl`, `Timeout`,
  `MaxRetries`, `MaxRetryWait`, `DefaultCountryCode`, `DefaultSender`, `Logger`; `LABYRINTHE_API_KEY` /
  `LABYRINTHE_BASE_URL` read from the environment; optional `HttpClient`; `IsTestMode` / `IsLiveMode`.
- SMS: `SendAsync`, `SendManyAsync` (all or nothing, `Replayed`), `EstimateAsync`, `GetAsync`, `ListAsync` with
  filters (`DateFilter`) and `ListAutoPagingAsync` (`IAsyncEnumerable<Message>`).
- Balance and approved Sender IDs.
- OTP: `SendAsync` (with `TestCode` in test mode, hidden from `ToString()`) and `VerifyAsync`.
- Automatic `Idempotency-Key` (`Guid`) on every SMS send, reused across retries.
- Retries with exponential backoff and jitter, only when safe (GET, estimate, SMS send; OTP only on
  429 `TOO_MANY_REQUESTS`); `Retry-After` (seconds or HTTP date) capped by `MaxRetryWait`; no redirect following;
  per-attempt timeout distinct from caller cancellation.
- Typed exceptions for every documented error code (`LabyrintheException` → `ApiException` → …),
  `ApiConnectionException`, `ApiTimeoutException`, `UnexpectedResponseException`.
- `Webhook.ConstructEvent()`: constant-time HMAC-SHA256 verification on the raw body (`string` or `byte[]`),
  timestamp tolerance, typed immutable events (`MessageStatusChanged`, `CampaignCompleted`, `BalanceLow`, `TestPing`,
  `UnknownEvent`); `Sign()` and `VerifySignature()`.
- Immutable records, enums with `Unknown` for future values, `DateTimeOffset` dates (`Z` and `+00:00`).
- `PhoneNumber` helpers (normalisation identical to the API, validation, masking); `ILabyrintheLogger` (no key,
  code, body or full number in any record).
- Targets `netstandard2.0` and `net8.0` (trimmable, AOT compatible); SourceLink and symbol packages.
- `Labyrinthe.Connect.AspNetCore` (net8.0): `services.AddLabyrinthe(configuration)` (section `Labyrinthe`,
  `IHttpClientFactory`, `ILogger<LabyrintheClient>`), `AddLabyrintheWebhookHandler<T>()` and
  `app.MapLabyrintheWebhooks()` (raw body, signature and timestamp checked; 400 / 413 / 500).

[Unreleased]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/dotnet-v1.1.1...HEAD
[1.1.1]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/dotnet-v1.1.0...dotnet-v1.1.1
[1.1.0]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/dotnet-v1.0.0...dotnet-v1.1.0
[1.0.0]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/releases/tag/dotnet-v1.0.0

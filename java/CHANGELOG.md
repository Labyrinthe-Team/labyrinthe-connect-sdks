# Changelog

All notable changes to `cd.labyrinthe:labyrinthe-connect` and `cd.labyrinthe:labyrinthe-connect-spring-boot-starter`
are documented here. This project follows [Semantic Versioning](https://semver.org/) and the format of
[Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [1.2.0] - 2026-11-06

Built against the API specification 1.3.0. Backward compatible with 1.1.1, except the retry change listed under
"Changed".

### Added
- `FeatureDisabledException` (503 `FEATURE_DISABLED`, a subclass of `ServiceUnavailableException`): a platform
  administrator temporarily shut down the whole API (`blockedBy()` = `api`, every route, refused before the key and
  the rate limits are checked) or one function (`api.sms_send`, `api.otp_send`, `api.otp_verify`). Nothing was done
  (no SMS, no debit). Accessors: `feature()`, `blockedBy()`, `adminMessage()` (`details.message`), `until()`
  (`Optional<Instant>`, scheduled re-enable) and `retryAfter()` (`Retry-After` header). Missing or malformed details
  give empty `Optional`s.
- README: "Maintenance (503 `FEATURE_DISABLED`)" section with a handling example.

### Changed
- The legacy 503 `OTP_SERVICE_DISABLED` of `otp().send()` is now a `FeatureDisabledException` too (still a
  `ServiceUnavailableException`), with the same details (`feature` = `blocked_by` = `otp`).
- Retries: 503 `FEATURE_DISABLED` and `OTP_SERVICE_DISABLED` are never retried with the exponential backoff (before,
  `OTP_SERVICE_DISABLED` was retried like any 5xx). They are retried, for every request including `otp().verify()`
  (refused before processing), only when `Retry-After` is present and does not exceed `maxRetryWait`, after exactly
  that delay; otherwise the exception is thrown at once. Other 5xx are unchanged.

## [1.1.1] - 2026-11-06

### Added
- `IdempotencyKeyUnresolvedException` (409 `IDEMPOTENCY_KEY_UNRESOLVED`, a subclass of `ApiException`) for
  `sms().send()`, `sendMany()` and `otp().send()`: the original request with this `Idempotency-Key` failed
  unexpectedly and its outcome is unknown (an SMS may have been sent). Never retried, synchronously or
  asynchronously: check (`sms().list()` filtered by `reference`, or with the user), then send again with a NEW key.

### Fixed
- Phone numbers are now sent to the API in E.164 form with the "+" (`recipient`, `recipients`, OTP `phone` and
  the `recipient` filter of `sms().list()`). Before, a short foreign number lost its "+": `+2481234567`
  (Seychelles, 10 digits) was read by the API as a local DR Congo number and became `2432481234567`.
  `0812345678` is sent as `+243812345678`. `PhoneNumbers.normalize()` still returns digits without "+", the format
  of the API responses.
- The integration test of a short foreign number on `sms().send()` is enabled again: `POST /sms` now keeps
  `+2481234567` as `2481234567` (`BACKEND-TODO.md` n° 34, fixed on the API side).

## [1.1.0] - 2026-11-06

Built against the API specification 1.2.0. Backward compatible with 1.0.0, except the behaviour changes listed
under "Changed".

### Added
- `RequestOptions.builder()`: `timeout`, `requestId` (your own `X-Request-Id`, sent on every retry, validated
  against `^[A-Za-z0-9._:-]{1,128}$`) and `onResponse(Consumer<ResponseInfo>)`; `RequestOptions.requestId(…)`.
- `ResponseInfo`: `status()`, `requestId()`, `apiVersion()`, `replayed()`, `rateLimitLimit()`,
  `rateLimitRemaining()`, `headers()`. `ApiException.apiVersion()` (`X-Api-Version`); `MessageBatch.requestId()`.
- `IdempotencyRequestInProgressException` (409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`, `retryAfter()`), retried
  automatically after `Retry-After` with the same `Idempotency-Key` for `sms().send()`, `sendMany()` and
  `otp().send()`.
- `AccountSuspendedException` (403 `ACCOUNT_SUSPENDED`), a subclass of `PermissionException`.
- OTP send: `SendOtpParams.Builder` `idempotencyKey()`, `webOtpDomain()` (Web OTP API, validated and lower-cased)
  and `stripAccents()`; `OtpSendResult` `codeLength()`, `codeType()` (`OtpCodeType` `NUMERIC` / `ALPHANUMERIC` /
  `UNKNOWN`), `resendAfterSeconds()` / `resendAfter()`, `idempotencyKey()`, `replayed()`.
- OTP verify: `OtpVerifyResult.attemptsRemaining()` (`OptionalInt`), `OtpVerifyResult.failed(reason, attempts)`.
- `stripAccents(boolean)` on `SendSmsParams`, `SendManySmsParams` and `EstimateSmsParams`.
- Webhooks: `MessageStatusChanged.Data` `reference()`, `errorCode()`, `errorMessage()`; new events `OtpVerified`
  (`otp.verified`) and `OtpFailed` (`otp.failed`) with `OtpEventData` (`otpId`, `phone`, `status`
  (`OtpEventStatus`), `reason`, `verifiedAt`); the Spring Boot starter publishes them as application events.
- `Limits.MAX_WEB_OTP_DOMAIN_LENGTH`, `SendOtpParams.WEB_OTP_DOMAIN_PATTERN`, `RequestOptions.REQUEST_ID_PATTERN`.

### Changed
- `otp().send()` always sends an `Idempotency-Key` (random UUID unless you give one) and is now retried on network
  errors, timeouts, 5xx, 429 `TOO_MANY_REQUESTS` and 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` (a retry returns the same
  OTP, never a second SMS). `OTP_RESEND_TOO_SOON` and `OTP_RATE_LIMITED` are still never retried; `otp().verify()`
  is still retried only on 429 `TOO_MANY_REQUESTS`.
- Phone numbers: without "+", 11 digits or more not starting with 0 are already international (`33612345678` is no
  longer prefixed with 243), as the API 1.2.0 does; DR Congo formats are unchanged.
- `OtpVerifyResult.equals()` and `OtpSendResult.equals()` include the new fields.
- `ApiException.requestId()` now documented as always present with API 1.2.0; `OtpRateLimitedException` and
  `OtpResendTooSoonException` read the new `Retry-After` header through `retryAfter()`.
- Still tolerated: `error.details` sent as `[]`, dates with `Z` or microseconds, missing 1.2.0 fields.

## [1.0.0] - 2026-11-06

First stable release, built against the API specification 1.1.0.

### Added
- `LabyrintheClient.builder()` (`apiKey`, `baseUrl`, `timeout`, `maxRetries`, `maxRetryWait`,
  `defaultCountryCode`, `defaultSender`, `httpClient`) and `LabyrintheClient.fromEnv()`
  (`LABYRINTHE_API_KEY`, `LABYRINTHE_BASE_URL`); `isTestMode()` / `isLiveMode()`; thread-safe client.
- SMS: `send()`, `sendMany()` (all or nothing, `replayed()`), `estimate()`, `get()`, `list()` with filters and
  pagination (`Page`, `autoPagingIterable()`, `autoPagingStream()`).
- Balance and approved Sender IDs.
- OTP: `send()` (with `testCode()` in test mode, never in `toString()`) and `verify()`.
- Asynchronous API with the same rules: `client.async()` returning `CompletableFuture`s, `AsyncPage`
  (`autoPagingForEach()`, `autoPagingToList()`).
- Automatic `Idempotency-Key` (random UUID) on every SMS send, reused across retries.
- Retries with exponential backoff and jitter, only when safe (GET, estimate, SMS send; OTP only on
  429 `TOO_MANY_REQUESTS`); `Retry-After` support (seconds or HTTP date) capped by `maxRetryWait`; redirects never
  followed; per-request timeout (`RequestOptions`).
- Unchecked exceptions for every documented error code (`LabyrintheException` → `ApiException` → …),
  `ApiConnectionException`, `ApiTimeoutException`, `InvalidArgumentException`, `UnexpectedResponseException`;
  the underlying `java.net.http` exception is never chained.
- `Webhooks.constructEvent()`: constant-time HMAC-SHA256 verification on the raw body (`byte[]` or `String`),
  timestamp tolerance, typed immutable events (`MessageStatusChanged`, `CampaignCompleted`, `BalanceLow`,
  `TestPing`, `UnknownEvent`) in a closed hierarchy; `sign()` and `verifySignature()`.
- `PhoneNumbers` (normalisation identical to the API, validation, masking); `System.Logger` logging
  (`cd.labyrinthe.connect`, no key, code, body or full number); no runtime dependency (internal JSON parser).
- Spring Boot 3 starter: `LabyrintheClient` bean from `labyrinthe.*` properties,
  `LabyrintheClientBuilderCustomizer`, signed webhook endpoint (`labyrinthe.webhook.*`) publishing events as
  Spring application events; configuration metadata for IDE completion.

[Unreleased]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/java-v1.1.1...HEAD
[1.1.1]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/java-v1.1.0...java-v1.1.1
[1.1.0]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/java-v1.0.0...java-v1.1.0
[1.0.0]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/releases/tag/java-v1.0.0

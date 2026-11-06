# Changelog

All notable changes to `labyrinthe/connect-laravel` are documented here. This project follows
[Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [1.2.0] - 2026-11-06

On top of `labyrinthe/connect-php` ^1.2.0 (API specification 1.3.0). Backward compatible with 1.1.x.

### Added
- `FeatureDisabledException` (from `labyrinthe/connect-php` 1.2.0): 503 `FEATURE_DISABLED` (whole API or one feature
  shut down by the platform, nothing sent) and 503 `OTP_SERVICE_DISABLED`, with `getFeature()`, `getBlockedBy()`,
  `getAdminMessage()`, `getUntil()` and `getRetryAfter()`. Only retried by the SDK after a `Retry-After` no longer
  than 10 seconds (`maxRetryWait`); a queued notification can `release()` its job for `getRetryAfter()` seconds.
- `Labyrinthe::fake()->failNextRequestWithFeatureDisabled($feature, $message, $until, $blockedBy)` simulates it
  (`'otp'` gives `OTP_SERVICE_DISABLED`; `$until` adds `Retry-After`). Fake failures can now carry response headers.

## [1.1.1] - 2026-11-06

### Fixed
- Requires `labyrinthe/connect-php` ^1.1.1: phone numbers are sent to the API in E.164 with `+`, so a short foreign
  number (`+2481234567`) is no longer read as a local DRC number.

### Added
- `IdempotencyKeyUnresolvedException` (from `labyrinthe/connect-php` 1.1.1): 409 `IDEMPOTENCY_KEY_UNRESOLVED` on
  SMS, notification and OTP sends, never retried (outcome unknown: check, then send again with a new key).
  Simulate it with `Labyrinthe::fake()->failNextRequest('IDEMPOTENCY_KEY_UNRESOLVED', 409)`.

## [1.1.0] - 2026-11-06

On top of `labyrinthe/connect-php` ^1.1 (API specification 1.2.0). Backward compatible with 1.0.0.

### Added
- `LabyrintheMessage::stripAccents()` and `Labyrinthe::send(..., stripAccents: true)`.
- `Labyrinthe::otp()->send()` accepts `webOtpDomain`, `stripAccents` and `idempotencyKey`; defaults from the new
  config keys `labyrinthe.otp.web_otp_domain` (`LABYRINTHE_OTP_WEB_OTP_DOMAIN`) and `labyrinthe.otp.strip_accents`
  (`LABYRINTHE_OTP_STRIP_ACCENTS`).
- Events `OtpVerified` (`otp.verified`) and `OtpFailed` (`otp.failed`), dispatched by the webhook controller.
- `ValidOtp::withAttemptsRemaining($otpId)` / `new ValidOtp($otpId, showAttemptsRemaining: true)`: the wrong-code
  message mentions the attempts left (translation key `otp.invalid_code_attempts`, English and French).
- Fake: OTP results carry `codeLength`, `codeType`, `resendAfterSeconds`; `verify()` returns `attemptsRemaining`;
  idempotent OTP sends (replay, `IDEMPOTENCY_KEY_REUSED`); `withOtpMaxAttempts()`, `withOtpResendAfter()`;
  `SentSms::$stripAccents`, `SentOtp::$webOtpDomain`, `$stripAccents`, `$idempotencyKey`.

### Changed
- Requires `labyrinthe/connect-php` ^1.1: OTP sends are retried safely with an `Idempotency-Key`, typed
  `AccountSuspendedException` and `IdempotencyRequestInProgressException`, request id on every exception, phone
  numbers without `+` of 11 digits or more kept as international.
- Fake aligned with the API 1.2.0: SMS idempotency fingerprint after normalisation, estimates deduplicated,
  `strip_accents` applied to the encoding, the last wrong OTP attempt answers `max_attempts_reached`, dates
  formatted `+00:00`.

## [1.0.0] - 2026-11-06

First stable release, for Laravel 10, 11, 12 and 13, on top of `labyrinthe/connect-php` ^1.0.

### Added
- Auto-discovered service provider, publishable `config/labyrinthe.php`, `Labyrinthe` facade, `Client` singleton.
- `labyrinthe` notification channel with `LabyrintheMessage` (content, sender, reference, idempotency key, recipients);
  route from `routeNotificationForLabyrinthe()` or the `phone` attribute; the notification id is used as
  Idempotency-Key so retried queued notifications are not sent twice.
- `Route::labyrintheWebhooks()` and `WebhookController`: raw-body signature check, timestamp tolerance, optional
  deduplication on the event id, events `WebhookReceived`, `MessageStatusChanged`, `CampaignCompleted`, `BalanceLow`;
  CSRF excluded automatically.
- `Labyrinthe::otp()` (`send`, `verify`, `check`) with config defaults; validation rules `ValidOtp` and `PhoneNumber`
  with English and French messages.
- `Labyrinthe::fake()` with assertions (`assertSmsSent`, `assertSmsSentTo`, `assertSmsNotSentTo`,
  `assertSmsSentCount`, `assertNothingSent`, `assertOtpSent`, `assertNoOtpSent`) and helpers (`otpCode`,
  `withBalance`, `withSenderIds`, `withOtpCode`, `failNextRequest`).

[Unreleased]: https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/compare/laravel-v1.1.0...HEAD
[1.1.0]: https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/compare/laravel-v1.0.0...laravel-v1.1.0
[1.0.0]: https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/releases/tag/laravel-v1.0.0

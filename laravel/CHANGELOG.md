# Changelog

All notable changes to `labyrinthe/connect-laravel` are documented here. This project follows
[Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

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

[Unreleased]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/laravel-v1.0.0...HEAD
[1.0.0]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/releases/tag/laravel-v1.0.0

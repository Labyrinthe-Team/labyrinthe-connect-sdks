# Changelog

All notable changes to `labyrinthe/connect-php` are documented here. This project follows
[Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

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

[Unreleased]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/php-v1.0.0...HEAD
[1.0.0]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/releases/tag/php-v1.0.0

# Changelog

All notable changes to `Labyrinthe.Connect` and `Labyrinthe.Connect.AspNetCore` (.NET) are documented here. This
project follows [Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

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

[Unreleased]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/dotnet-v1.0.0...HEAD
[1.0.0]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/releases/tag/dotnet-v1.0.0

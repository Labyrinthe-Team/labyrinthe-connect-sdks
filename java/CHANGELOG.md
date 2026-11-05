# Changelog

All notable changes to `cd.labyrinthe:labyrinthe-connect` and `cd.labyrinthe:labyrinthe-connect-spring-boot-starter`
are documented here. This project follows [Semantic Versioning](https://semver.org/) and the format of
[Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

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

[Unreleased]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/java-v1.0.0...HEAD
[1.0.0]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/releases/tag/java-v1.0.0

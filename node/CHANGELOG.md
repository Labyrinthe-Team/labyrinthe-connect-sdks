# Changelog

All notable changes to `@labyrinthe/connect` are documented here. This project follows
[Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

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

[Unreleased]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/node-v1.0.0...HEAD
[1.0.0]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/releases/tag/node-v1.0.0

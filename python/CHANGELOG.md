# Changelog

All notable changes to `labyrinthe-connect` (Python) are documented here. This project follows
[Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

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

[Unreleased]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/python-v1.0.0...HEAD
[1.0.0]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/releases/tag/python-v1.0.0

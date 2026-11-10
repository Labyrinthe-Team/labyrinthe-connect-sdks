# Changelog

All notable changes to `@labyrinthe/otp-widget` are documented here. This project follows
[Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [1.2.1] - 2026-11-09

### Fixed
- Peer dependency `@labyrinthe/connect` ^1.2.1, whose default API URL is now `https://api.connect.labyrinthe-rdc.com/api/public/v1`.
- Links to the website now point to https://connect.labyrinthe-rdc.com.

## [1.2.0] - 2026-11-06

Built against the API specification 1.3.0 and `@labyrinthe/connect` 1.2 (peer dependency now `^1.2.0`).

### Added
- Labyrinthe maintenance: `503 FEATURE_DISABLED` (whole API or OTP functions shut down by the platform) and
  `OTP_SERVICE_DISABLED` are answered as `service_unavailable` (503) with `admin_message` (the administrator's message,
  meant for end users, the only upstream text forwarded), `until` (ISO 8601) and, when the end is scheduled,
  `retry_after_seconds` and a `Retry-After` header. Reported to `onError`. Also read from an older SDK (details and
  `Retry-After` header of the generic error).
- The widget shows the administrator's message instead of `errors.service_unavailable` when there is one, followed by
  the new text `retryIn` ("Réessayez dans {time}." / "Try again in {time}.") when the time left is known. The
  `labyrinthe-otp:error` event gains `adminMessage` and `until` (`Date`).

### Changed
- Durations of one hour or more are shown as `h:mm:ss` (`1:30:00`) instead of `90:00`.

## [1.1.1] - 2026-11-06

### Added
- Stable error code `send_failed` (502) for `409 IDEMPOTENCY_KEY_UNRESOLVED` (the original send failed unexpectedly on
  the API side and its outcome is unknown: a code may have been sent). Reported to `onError`, never retried; the
  widget shows a French / English message and forgets its `send_id`, so a new code requested by the user is sent with
  a new idempotency key.

### Fixed
- Short foreign numbers (`+2481234567`, Seychelles, 10 digits) are sent to the API with their country code instead of
  being read as local DRC numbers (`2432481234567`). The widget and the server helper already
  sent `+<digits>`, but `@labyrinthe/connect` 1.1.0 dropped the `+` before calling the API: the peer dependency is now
  `^1.1.1` (the release that sends E.164 numbers with `+`). Tests check the number handed to the SDK and the body
  received by a local API.

## [1.1.0] - 2026-11-06

Built against the API specification 1.2.0 and `@labyrinthe/connect` 1.1 (peer dependency now `^1.1.0`).

### Added
- Server option `webOtpDomain`: the API ends the SMS with `@domain #code`, so the Web OTP API of the widget
  (`navigator.credentials.get`, Chrome on Android) can really fill the code. Validated at start-up.
- Server option `stripAccents` (GSM-7 SMS).
- Idempotent sends: the widget sends a random `send_id` (kept until the send succeeds, reused after a lost
  response); the helper derives the `Idempotency-Key` from it with an HMAC of `secret`, so a retried click never
  sends a second SMS. Invalid `send_id`: 400 `invalid_request`.
- Stable error code `account_suspended` (503) for `ACCOUNT_SUSPENDED`, translated in French and English and reported
  to `onError`. `IDEMPOTENCY_REQUEST_IN_PROGRESS` maps to `rate_limited` with `retry_after_seconds`.

### Changed
- `code_length`, `code_type` and `resend_after_seconds` sent to the widget come from the API response; the
  `codeLength`, `codeType` and `resendAfterSeconds` options remain as overrides (needed only with an older API).
- `attempts_left` is the API's `attempts_remaining` (estimated locally only with an older SDK).
- Phone numbers, aligned with the API and the SDK: without "+", 11 digits or more not starting with 0 are already
  international (`33612345678` is kept).

## [1.0.0] - 2026-11-06

First stable release, built against the API specification 1.1.0 and `@labyrinthe/connect` 1.0.

### Added
- `<labyrinthe-otp>` web component (Shadow DOM, zero dependencies, 7.6 kB min+gzip standalone): phone step
  (default country code 243, same normalisation as the SDK), separate code boxes, paste and
  `autocomplete="one-time-code"`, Web OTP API, auto-submit, resend countdown (`resend_after_seconds`,
  `OTP_RESEND_TOO_SOON`), attempts left, localised errors, success state.
- Events `labyrinthe-otp:sent`, `labyrinthe-otp:verified`, `labyrinthe-otp:error`; properties `texts`,
  `requestHeaders`, `step`, `reset()`.
- Accessibility: labels, `role="group"` code boxes with accessible names, `role="alert"` errors, polite live region,
  keyboard navigation, focus management.
- Theming with `--lab-otp-*` CSS variables and `::part()`, light / dark / automatic, French (default) and English.
- React wrapper `@labyrinthe/otp-widget/react` (`'use client'`, React 18 and 19, SSR-safe).
- Server helper `@labyrinthe/otp-widget/server`: `createOtpHandlers()` for the Fetch API (Next.js App Router, edge,
  Hono, Bun, Deno) and Express; `otp_id` kept in an AES-256-GCM encrypted httpOnly cookie or in a `store`;
  `beforeSend` / `onVerified` / `onError` hooks; origin and content-type checks; `exposeTestCode` for development
  (refused in production).
- Standalone `dist/labyrinthe-otp.min.js` for a `<script>` tag (unpkg, jsDelivr).

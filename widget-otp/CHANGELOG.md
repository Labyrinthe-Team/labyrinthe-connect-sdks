# Changelog

All notable changes to `@labyrinthe/otp-widget` are documented here. This project follows
[Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

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

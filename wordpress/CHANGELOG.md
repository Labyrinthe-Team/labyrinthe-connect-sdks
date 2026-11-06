# Changelog

All notable changes to the `labyrinthe-connect` WordPress plugin are documented here. This project follows
[Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).
The wordpress.org changelog lives in `readme.txt`.

## [Unreleased]

## [1.2.0] - 2026-11-06

Built on `labyrinthe/connect-php` 1.2.0 (API specification 1.3.0). No setting to change.

### Added
- Platform maintenance: `503 FEATURE_DISABLED` (whole API, SMS sending or OTP shut down by a Labyrinthe Connect
  administrator) and `503 OTP_SERVICE_DISABLED` (`FeatureDisabledException`, PHP SDK 1.2.0). Nothing was sent, no
  credit used.
  - OTP send and verify: new failure code `FEATURE_DISABLED` with the administrator's message (plain text, tags
    removed) or a translated "temporarily unavailable" message, followed by the expected return time when
    `details.until` is known, and `retry_after` (countdown) when the API sends `Retry-After`. Sends are logged with
    the error code and the request ID; a refused verification consumes no attempt.
  - Order SMS: retryable failure. The action is rescheduled at the announced return time (`Retry-After`, at least
    1 minute) when it is known, otherwise after 5, 10 then 15 minutes as before; the order note and the SMS log carry
    the maintenance message.
- `Send_Result::$retry_after` (seconds, `0` when unknown), also in the `labyrinthe_connect_sms_sent` action data.

### Changed
- The SDK no longer retries `FEATURE_DISABLED` / `OTP_SERVICE_DISABLED` blindly: only after a `Retry-After` no longer
  than its 3-second limit (`maxRetryWait`).

## [1.1.1] - 2026-11-06

Built on `labyrinthe/connect-php` 1.1.1.

### Fixed
- Short foreign numbers (10 digits or fewer, e.g. `+2481234567`): the PHP SDK 1.1.1 now sends numbers to the API in
  E.164 with `+`, so they are no longer read as local DRC numbers by the API (`BACKEND-TODO.md` n° 32). The plugin
  still passes `+<international number>` to the SDK: the stored numbers are digits without `+`, and without it the SDK
  would add the default country code to a short foreign one. With `BACKEND-TODO.md` n° 34 fixed on the API side,
  `POST /sms` keeps the country code too (`+2481234567` → `2481234567`); the integration test is no longer marked
  incomplete and the "known limit" is gone from the README.

### Added
- `409 IDEMPOTENCY_KEY_UNRESOLVED` (`IdempotencyKeyUnresolvedException`, PHP SDK 1.1.1): the outcome of the original
  request is unknown (an SMS may have been sent). Order SMS: final failure, never retried nor rescheduled, with an
  order note and an SMS log row carrying the error code and the request ID. OTP: logged with the request ID, neutral
  message for the visitor (`SERVICE_UNAVAILABLE`), and their next request uses a new idempotency key.

## [1.1.0] - 2026-11-06

Built on `labyrinthe/connect-php` 1.1.0 (API specification 1.2.0). No setting to change: the update is automatic
(the SMS log table gains a `request_id` column).

### Added
- OTP idempotency: every send carries a stable `Idempotency-Key` (site + session + context + number + rank of the
  send), so a double click or a retry after a network error replays the same send (same code, no second SMS); the SDK
  now retries an OTP send once (network error, 5xx, 429). A replayed send is not logged twice.
- OTP code field shaped by the API: `code_length` (length) and `code_type` (numeric keyboard, or text for
  alphanumeric codes), `autocomplete="one-time-code"`, pasted codes cleaned; the shape is remembered for the next forms.
- Resend countdown from `resend_after_seconds`; after a wrong code, the number of attempts left (`attempts_remaining`).
- Web OTP: new "Automatic code filling" setting (on by default) and "Domain" (the site domain by default, ignored for
  `localhost` or an IP address), sent as `web_otp_domain`; Chrome on Android fills in and checks the code
  (`navigator.credentials.get`). Filter `labyrinthe_connect_web_otp_domain`.
- Webhooks `otp.verified` and `otp.failed`: WordPress actions `labyrinthe_otp_verified` and `labyrinthe_otp_failed`.
- `message.status_changed`: `error_code` and `error_message` saved in the SMS log and added to the order note of a
  failed SMS; the order is found by the `reference` (`order-<order ID>`) when the log row is missing.
- Suspended account (`403 ACCOUNT_SUSPENDED`): clear error notice in the administration with the request ID, cleared
  by the next successful call; a clear message in order notes and in the test tools; visitors see a neutral message.
- Request ID: every API call sends its own `X-Request-Id` (`wp-<uuid>`); on failure, the ID (the one of the API, or the
  one sent for a network error) is written in the order note, the SMS log (new column, searchable) and the messages of
  the test tools, for the support.
- Segment preview counted by the API (`POST /sms/estimate`, nothing sent or billed, accents removed as configured),
  with the local estimate while typing or without API key. New AJAX action `labyrinthe_connect_estimate`.

### Changed
- Accent removal is done by the API (`strip_accents`) for order SMS, test SMS and, now, OTP codes; the plugin's own
  transliteration (`Sms_Text::strip_special_characters()`) is removed. The text sent to the API is the template as
  rendered (`labyrinthe_connect_sms_body` receives it with its accents).
- Order SMS `reference` is now `order-<order ID>` (was `order-<order number>`; identical unless a custom order number
  plugin is used), so that delivery reports find their order.
- Phone numbers: still sent as `+<international number>`. SDK 1.1.0 keeps foreign numbers of 11 digits or more, but the
  `+` is still needed for shorter ones (and the API still prefixes them with 243: see `BACKEND-TODO.md`).
- Default resend delay before the API answers: 60 seconds (was 30), the API value is used as soon as it is known.

## [1.0.0] - 2026-11-06

First release, built on `labyrinthe/connect-php` 1.0.0 (API specification 1.1.0).

### Added
- Settings page (Settings API, `manage_options`): API key and webhook secret encrypted in the database (libsodium,
  WordPress salts) or defined in `wp-config.php`, test mode badge, approved Sender IDs, default country code (243),
  accent removal, SMS log retention, "Test the connection" and "Send a test SMS" (AJAX, nonce).
- WooCommerce: SMS per order status with editable templates and variables, segment and encoding preview, shop manager
  SMS for new orders, Action Scheduler (never blocks checkout), `Idempotency-Key` per order and status, retries on
  network errors / 5xx, order notes, HPOS and block checkout compatibility.
- OTP: phone verification at registration (WordPress, WooCommerce) and checkout (classic and blocks), login by SMS
  (login forms, `[labyrinthe_otp_login]` shortcode, `labyrinthe-connect/otp-login` block); `otp_id` kept server-side,
  `OTP_RESEND_TOO_SOON` countdown, per-IP limit, test code shown to administrators only.
- Webhook endpoint `/wp-json/labyrinthe/v1/webhook`: signature on the raw body, replay protection, deduplication on the
  event `id`, SMS log update, WordPress actions (`labyrinthe_webhook_received`, `labyrinthe_message_status_changed`,
  `labyrinthe_campaign_completed`, `labyrinthe_balance_low`, `labyrinthe_webhook_test_ping`), low balance notice.
- SMS log (`WP_List_Table`, masked numbers, filters, daily purge), privacy policy suggestion, clean uninstall.
- French translation (fr_FR, also used for the other French locales).
- Bundled SDK prefixed with PHP-Scoper (`LabyrintheConnect\Vendor\`), reproducible zip build.

[Unreleased]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/wordpress-v1.1.0...HEAD
[1.1.0]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/wordpress-v1.0.0...wordpress-v1.1.0
[1.0.0]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/releases/tag/wordpress-v1.0.0

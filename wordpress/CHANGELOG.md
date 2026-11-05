# Changelog

All notable changes to the `labyrinthe-connect` WordPress plugin are documented here. This project follows
[Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).
The wordpress.org changelog lives in `readme.txt`.

## [Unreleased]

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

[Unreleased]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/compare/wordpress-v1.0.0...HEAD
[1.0.0]: https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/releases/tag/wordpress-v1.0.0

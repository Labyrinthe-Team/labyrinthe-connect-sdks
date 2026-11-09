# Labyrinthe Connect for WordPress & WooCommerce

![Version](https://img.shields.io/badge/version-1.2.1-008374)
[![CI](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/wordpress.yml/badge.svg)](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/wordpress.yml)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
![WordPress](https://img.shields.io/badge/wordpress-6.4%2B-21759b)
![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.1-777bb4)

Official WordPress plugin of [Labyrinthe Connect](https://connect.labyrinthe-rdc.com), the SMS and OTP platform for the DR
Congo and Central Africa (slug `labyrinthe-connect`). Without writing code: WooCommerce order SMS for every status,
a shop manager SMS for each new order, phone verification by SMS at registration and checkout, and login with a code
received by SMS (login form, shortcode, block). SMS are sent in the background and never twice, every SMS leaves an
order note, and a `lsk_test_` key lets you test everything without sending or paying. Plugin 1.2.0, bundling the
[PHP SDK](../php) 1.2.0 (API specification 1.3.0).

*Version française : [README.fr.md](README.fr.md). The end-user documentation for wordpress.org is
[`readme.txt`](readme.txt).*

[Requirements](#requirements) · [Installation](#installation) · [Quick start](#quick-start) ·
[Settings](#settings) · [WooCommerce order SMS](#woocommerce-order-sms) · [OTP](#otp-phone-verification-and-login) ·
[Webhooks](#webhooks) · [SMS log, test mode and security](#sms-log-test-mode-and-security) ·
[Errors and maintenance](#errors-and-maintenance) · [Retries and idempotency](#retries-and-idempotency) ·
[Hooks for developers](#hooks-and-filters-for-developers) · [Troubleshooting](#troubleshooting) ·
[Development](#development)

## Requirements

| | |
|---|---|
| WordPress | 6.4 or newer (tested up to 7.1) |
| PHP | **8.1 or newer** (required by the PHP SDK). On older PHP the plugin shows a notice and stays inactive. |
| WooCommerce | 8.0 or newer, **optional** (tested up to 11.2), HPOS and block checkout compatible. Without it, OTP registration and login still work. |
| Account | a Labyrinthe Connect account and an API key (client area > *Developers > API keys*) |
| License | GPL-2.0-or-later (bundled SDK and dependencies: MIT) |

## Installation

| From | How |
|---|---|
| Zip file | Download `labyrinthe-connect.zip` from the [GitHub releases](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/releases) (tags `wordpress-vX.Y.Z`), then *Plugins > Add New > Upload Plugin*, and activate. |
| WP-CLI | `wp plugin install labyrinthe-connect.zip --activate` |
| wordpress.org | *Plugins > Add New*, search "Labyrinthe Connect" (the plugin is ready but not yet published there). |
| Sources | `composer install && composer build` in this folder gives `dist/labyrinthe-connect.zip` (see [Development](#development)). A copy of the folder without `composer install` lacks `vendor-prefixed/` and shows a notice. |

## Quick start

1. Activate the plugin, open *Labyrinthe Connect > Settings*.
2. Paste a `lsk_test_…` key, save, click **Test the connection**, then **Send a test SMS** (nothing is sent, nothing
   is billed).
3. *WooCommerce* tab: enable the statuses that send an SMS. *OTP* tab: login by SMS, verification at registration or
   checkout.
4. Optional, for delivery reports: add the webhook URL shown in the settings to the client area
   (*Developers > Webhooks*) and paste its secret.
5. Replace the test key with your `lsk_live_…` key.

## Settings

*Labyrinthe Connect > Settings* (capability `manage_options`), three tabs. Everything is stored in the
`labyrinthe_connect_settings` option.

**General**

| Setting | Default | |
|---|---|---|
| API key | — | encrypted in the database, never shown again (only `lsk_test_…ABCD`); empty field = keep the current key |
| Sender | platform default | an approved Sender ID of the account (**Refresh** reloads the list) |
| Default country code | `243` | for numbers typed in local format; WooCommerce orders use the billing country first |
| Accents | off | "Remove accents and typographic quotes before sending": done by the API (`strip_accents`) for order SMS, test SMS and OTP codes |
| Webhook URL | — | `https://your-site/wp-json/labyrinthe/v1/webhook`, to copy into the client area |
| Webhook secret | — | encrypted, used to check every delivery |
| SMS log retention | `90` days | `0` keeps forever; older rows are purged daily |
| Tools | — | **Test the connection** (mode and balance) and **Send a test SMS** |

**WooCommerce**

| Setting | Default | |
|---|---|---|
| SMS to the customer when the order status changes | *Processing* and *Completed* enabled | one switch and one template per status (custom statuses included) |
| SMS to the shop manager for each new order | off | one or more phone numbers (comma separated) and a template |
| Available variables | — | list of the `{variables}` below, with a live preview of characters, encoding and segments |

**OTP (verification and login)**

| Setting | Default | |
|---|---|---|
| Login by SMS | off | allow users to log in with a code; add it to the WordPress and WooCommerce login forms (on); also allow administrators (off, not recommended) |
| Registration | off | require a phone number verified by SMS to create an account (WordPress and WooCommerce) |
| Checkout | off | require the billing phone to be verified by SMS to place an order (classic and block checkout) |
| Message | account OTP template | optional, must contain `{code}`; `{app_name}` and `{minutes}` are available |
| Automatic code filling | on | Web OTP: adds `@domain #code` to the SMS so Chrome on Android fills the code in |
| Domain | site domain | the domain of the page where the code is typed; ignored for `localhost` or an IP address |
| Limit per visitor | `5` | codes per hour and per IP address, on top of the Labyrinthe Connect limits |

Code length, validity and attempts come from the OTP settings of your client area. Users get a *Phone number for SMS
login* field in their profile (editable by administrators).

**`wp-config.php` constants** (they take precedence over the settings and are never stored in the database):

```php
define( 'LABYRINTHE_CONNECT_API_KEY', 'lsk_test_…' );
define( 'LABYRINTHE_CONNECT_WEBHOOK_SECRET', '…' );
define( 'LABYRINTHE_CONNECT_BASE_URL', 'http://localhost:8000' );   // another environment (default: production)
```

## WooCommerce order SMS

- **Never blocks checkout**: `woocommerce_order_status_changed` only schedules an Action Scheduler action
  (`labyrinthe_connect_order_sms`, group `labyrinthe-connect`, unique per order and status); the API is called a few
  seconds later. New orders schedule `labyrinthe_connect_admin_order_sms` for the shop manager
  (`woocommerce_checkout_order_processed` and the Store API equivalent).
- **Recipient**: the billing phone (else the shipping phone), normalised with the billing country. An order without
  a phone number gets a note and no SMS.
- **Order notes**: one per SMS: sent (masked number, segments, encoding, message ID), failed (error code and
  **request ID** for support), then delivered or finally failed when the webhook is configured. Order SMS carry the
  reference `order-<order ID>`.
- **Variables**: `{order_number}`, `{order_id}`, `{order_status}`, `{order_total}` (`25.00 USD`, plain ASCII),
  `{order_date}`, `{order_url}`, `{payment_method}`, `{customer_name}`, `{customer_first_name}`, `{customer_phone}`,
  `{shop_name}`, `{shop_url}`, `{tracking_number}`, `{tracking_url}` (Shipment Tracking extensions). Add your own with
  the `labyrinthe_connect_order_sms_variables` filter.
- **Cost**: one accent, curly quote or emoji switches the SMS to Unicode (70 characters instead of 160, often twice
  the price). The preview under each template is counted by the API (`POST /sms/estimate`, nothing sent); enable
  *Accents* to stay in GSM-7. The default French templates have no accents on purpose.

## OTP: phone verification and login

Codes are generated, sent and checked by Labyrinthe Connect (an SMS costs the same as any SMS; checking is free).

| Where | What the visitor does |
|---|---|
| Registration (WordPress and WooCommerce) | types a phone number, receives a code, types it; the account cannot be created without it. The verified number is saved in the `labyrinthe_phone` user meta. |
| Checkout (classic and blocks) | verifies the billing phone before placing the order. |
| Login | "Log in with a code received by SMS" on `wp-login.php` and WooCommerce *My account*, the shortcode `[labyrinthe_otp_login redirect="/my-account/" title="…"]` or the block **SMS login (Labyrinthe Connect)** (`labyrinthe-connect/otp-login`, attributes `redirect` and `title`). |

- The account is found by its `labyrinthe_phone` meta, set only by an OTP verification or by an administrator. An
  unknown number gets the same answer as a known one, without any SMS (no account enumeration, no credit spent). A
  number shared by several accounts cannot log in; administrators are refused unless allowed.
- The `otp_id` never reaches the browser: it stays in a server-side transient keyed by a random httpOnly cookie
  (`labyrinthe_otp`, 1 hour).
- The code field follows the API: `code_length`, numeric or text keyboard (`code_type`),
  `autocomplete="one-time-code"`; the resend countdown follows `resend_after_seconds`; a wrong code shows the
  attempts left.
- **Web OTP** (Chrome on Android) fills in and checks the code automatically when *Automatic code filling* is on.
- **Limits**: *Limit per visitor* (per IP, default 5 codes / hour), plus the platform limits (resend delay and hourly
  cap per number and per account). Behind a proxy or CDN, see the `labyrinthe_connect_client_ip` filter.
- **Test mode**: with a `lsk_test_` key no SMS is sent and administrators see the code on screen.

## Webhooks

Copy the *Webhook URL* (`/wp-json/labyrinthe/v1/webhook`) into the client area (*Developers > Webhooks*) and paste
the secret in the settings. Each delivery is checked by the SDK on the raw body (HMAC-SHA256 in constant time, signed
timestamp within 5 minutes), deduplicated on its `id` (deliveries are retried up to 5 times), then:

| Event | Effect | WordPress action |
|---|---|---|
| every event | — | `labyrinthe_webhook_received( $envelope )` |
| `message.status_changed` | SMS log updated (a final status is never downgraded), order note on delivery or final failure | `labyrinthe_message_status_changed( $envelope, $log_row )` |
| `otp.verified` / `otp.failed` | — (never the code) | `labyrinthe_otp_verified( $envelope )`, `labyrinthe_otp_failed( $envelope )` |
| `campaign.completed` | — | `labyrinthe_campaign_completed( $envelope )` |
| `balance.low` | admin notice "your SMS credit balance is low" (7 days, dismissible) | `labyrinthe_balance_low( $envelope )` |
| `test.ping` | "Last test event received" shown in the settings | `labyrinthe_webhook_test_ping( $envelope )` |

`$envelope` is the decoded `{ id, event, data, timestamp }`. Answers: `200` (processed or duplicate), `400` (invalid
signature or payload), `503` while no secret is configured (Labyrinthe Connect retries later).

## SMS log, test mode and security

- *Labyrinthe Connect > SMS log*: every SMS and OTP send of the site, filterable by type and status, searchable by
  order, message ID, error code or request ID. Numbers are stored **masked**, texts are **never** stored. Daily purge
  after the retention period.
- **Test mode** (`lsk_test_` key): the API simulates everything, nothing is sent, nothing is billed, the order notes
  and the log look like production and say "test mode".
- **Secrets**: the API key and the webhook secret are encrypted with libsodium (key derived from the `wp-config.php`
  salts), never sent to the browser, never logged. The SDK never logs codes or full numbers.
- **No API call on public page loads**: only AJAX actions, scheduled actions and the settings page call the API.
- **Privacy**: a suggested text for the privacy policy is added to *Settings > Privacy*. The external service is
  described in [`readme.txt`](readme.txt) (*External services*).
- **Uninstall** removes options, tables, transients, user and order meta and scheduled actions, on every site of a
  network.

## Errors and maintenance

| Situation | What happens |
|---|---|
| Invalid phone number, recipient refused (`INVALID_PHONE`, `RECIPIENT_NOT_SENDABLE`) | order note / clear message to the visitor; not retried |
| `INSUFFICIENT_CREDITS` | order note with the error; not retried: buy credits in the client area |
| `UNAUTHENTICATED` | "The API key was refused (invalid or revoked)" in the settings tools |
| `ACCOUNT_SUSPENDED` | error notice in the administration with the request ID, cleared by the next successful call; visitors see a neutral message |
| Network error, 5xx, `TOO_MANY_REQUESTS` | order SMS: retried (see below); OTP: "please try again" |
| `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED` | countdown / "too many codes requested" |
| `IDEMPOTENCY_KEY_UNRESOLVED` | the outcome of the first attempt is unknown (an SMS may have left): final failure, never retried; the order note asks to check the messages in the Labyrinthe Connect dashboard first. OTP: neutral "request a new code if you did not receive it". |
| **Maintenance** `503 FEATURE_DISABLED` / `OTP_SERVICE_DISABLED` | nothing was sent, no credit used. Visitors see the administrator's message (or a translated "temporarily unavailable") with the expected return time when known. Order SMS are rescheduled at the announced return time (`Retry-After`), otherwise after 5, 10 then 15 minutes; never retried in a loop. |

## Retries and idempotency

- **Never the same SMS twice**: each order SMS carries `Idempotency-Key: wc-<site>-<order>-<status>`, reused by every
  attempt (the API replays it for 24 hours), the scheduled action is unique, and the order remembers the SMS sent
  (`_labyrinthe_sms_sent` meta).
- Order SMS: the SDK retries once when it is safe (network error, 5xx, 429), then the action is rescheduled after 5,
  10 and 15 minutes (4 attempts in total). Business errors are not retried.
- OTP: every send carries a stable key built from the session, the context, the number and the rank of the send, so
  a double click or a network retry replays the same send (same code, no second SMS); only "Send a new code" after a
  successful send creates a new one. Verifications are never retried after a network error.
- Every API call carries an `X-Request-Id` (`wp-<uuid>`), written in failed order notes and in the SMS log: give it
  to support (connect@labyrinthe-rdc.com).

## Hooks and filters for developers

**Actions**

| Action | Arguments | When |
|---|---|---|
| `labyrinthe_connect_sms_sent` | `array $result, array $meta` | after every order or test SMS attempt; `$result`: `success`, `recipient` (masked), `message_id`, `status`, `segments`, `encoding`, `error_code`, `error_message`, `retryable`, `test_mode`, `replayed`, `log_id`, `request_id`, `retry_after`; `$meta`: `context` (`order_status`, `admin_new_order`, `test`), `order_id`… |
| `labyrinthe_webhook_received` | `array $envelope` | every verified, new webhook |
| `labyrinthe_message_status_changed` | `array $envelope, ?array $log_row` | `message.status_changed` (`data`: `message_id`, `status`, `reference`, `error_code`, `error_message`…) |
| `labyrinthe_otp_verified`, `labyrinthe_otp_failed` | `array $envelope` | `otp.verified` / `otp.failed` (`data`: `otp_id`, `phone`, `status`, `reason`, `verified_at`) |
| `labyrinthe_campaign_completed`, `labyrinthe_balance_low`, `labyrinthe_webhook_test_ping` | `array $envelope` | the matching webhooks |

**Filters**

| Filter | Arguments | Use |
|---|---|---|
| `labyrinthe_connect_send_order_sms` | `bool $send, WC_Order $order, string $status` | skip some order SMS |
| `labyrinthe_connect_order_sms_variables` | `array $vars, WC_Order $order, string $status` | add or change `{variables}` |
| `labyrinthe_connect_order_sms_body` | `string $body, WC_Order $order, string $status` | final text of a customer order SMS |
| `labyrinthe_connect_sms_body` | `string $body, array $meta` | final text of any SMS sent by the plugin (not OTP) |
| `labyrinthe_connect_order_total` | `string $total, WC_Order $order` | `{order_total}` (`25.00 USD`) |
| `labyrinthe_connect_tracking_url` | `string $url, WC_Order $order` | `{tracking_url}` |
| `labyrinthe_connect_otp_login_user` | `?WP_User $user, string $phone` | find the account of a phone number yourself |
| `labyrinthe_connect_otp_login_allowed` | `bool $allowed, WP_User $user` | allow or refuse SMS login for an account |
| `labyrinthe_connect_client_ip` | `string $ip` | visitor IP for the per-IP limit (only `REMOTE_ADDR` is trusted by default) |
| `labyrinthe_connect_web_otp_domain` | `string $domain` | Web OTP domain; `''` turns Web OTP off |
| `labyrinthe_connect_webhook_tolerance` | `int $seconds` | signed timestamp tolerance (300) |
| `labyrinthe_connect_delivery_order_notes` | `bool $enabled, array $row` | order notes on delivery reports |
| `labyrinthe_connect_base_url` | `?string $url` | API URL (default production; also `LABYRINTHE_CONNECT_BASE_URL`) |
| `labyrinthe_connect_http_client` | PSR-18 client | replace the HTTP client (default: WordPress HTTP API, so proxies and `pre_http_request` apply) |

```php
// No SMS for "on hold" cash-on-delivery orders.
add_filter( 'labyrinthe_connect_send_order_sms', function ( bool $send, WC_Order $order, string $status ): bool {
	return ( 'on-hold' === $status && 'cod' === $order->get_payment_method() ) ? false : $send;
}, 10, 3 );
```

A complete must-use plugin (custom variable, alert on final failure, low balance email) is in
[`examples/labyrinthe-connect-customizations.php`](examples/labyrinthe-connect-customizations.php).

The SDK is bundled under the `LabyrintheConnect\Vendor\` prefix and is not meant to be called by other plugins. To
call the API from your own code, require [`labyrinthe/connect-php`](../php) in your plugin (no conflict thanks to the
prefix), or call the REST API directly (one SMS: `POST https://api.connect.labyrinthe-rdc.com/api/public/v1/sms` with
`Authorization: Bearer lsk_test_…` and `{"recipient": "+243812345678", "body": "…"}`; see the
[OpenAPI specification](../openapi/openapi.yaml)).

## Troubleshooting

| Symptom | Fix |
|---|---|
| "Labyrinthe Connect needs PHP 8.1 or newer" | ask your host to switch to PHP 8.1, 8.2 or 8.3 (control panel, "PHP version"). |
| "Labyrinthe Connect is incomplete (vendor-prefixed/ is missing)" | install the plugin from its zip, or run `composer install` in its folder. |
| "The saved API key can no longer be read" | the salts of `wp-config.php` changed: enter the key again (or use `LABYRINTHE_CONNECT_API_KEY`). |
| **Test the connection**: "The API key was refused" | wrong, revoked or truncated key: create a new one in the client area. |
| "The Labyrinthe Connect API could not be reached" | outgoing HTTPS blocked by the host or a firewall, a wrong `LABYRINTHE_CONNECT_BASE_URL`, or a plugin filtering `pre_http_request`. |
| No order SMS | is the status enabled in the *WooCommerce* tab? Does the order have a phone number (see its notes)? Check *WooCommerce > Status > Scheduled Actions*, group `labyrinthe-connect`: actions stay pending when WP-Cron does not run (set up a real cron). |
| An SMS costs 2 credits | an accent or a special character switched it to Unicode: enable *Accents* and check the preview. |
| Delivery statuses never update | the webhook URL is missing in the client area, or the secret differs (`400` invalid signature); a security plugin or a proxy must not block or rewrite `POST /wp-json/labyrinthe/v1/webhook`. |
| The OTP form says "Your session has expired" | the page was served from a page cache: exclude login, registration, checkout and account pages from caching. |
| All visitors hit the per-IP limit | the site is behind a proxy or CDN: return the real client IP with `labyrinthe_connect_client_ip` (only from a trusted header). |
| "This account cannot log in by SMS" | the account has no verified number, the number is shared, or it is an administrator (see *Login by SMS*). |
| Visitors see "temporarily unavailable (maintenance)" | Labyrinthe Connect shut the service down for a while; order SMS will be sent again automatically. |

Give the **request ID** of the order note or the SMS log to support: connect@labyrinthe-rdc.com.

## Development

```bash
composer install                 # dev tools + vendor-prefixed/ (PHP-Scoper runs automatically)
php bin/install-wp-tests.php     # WordPress + WooCommerce in .wp-tests/, database labyrinthe_wp_tests
                                 # (--wp=6.4.5 --wc=8.5.2 --db-user=root --db-pass= --db-host=127.0.0.1)
composer check                   # PHPCS (WordPress Coding Standards + PHPCompatibilityWP 8.1+), PHPStan level 8, tests
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 composer test:integration
composer i18n                    # languages/*.pot, .po, .mo, .l10n.php and .json (WP-CLI in tools/wp-cli)
composer build                   # dist/labyrinthe-connect.zip + dist/labyrinthe-connect/
php bin/smoke-test.php           # checks the built plugin
```

Building needs PHP 8.2+ (running needs 8.1+). The build uses a whitelist (`labyrinthe-connect.php`, `uninstall.php`,
`readme.txt`, `LICENSE`, `includes/`, `assets/`, `blocks/`, `languages/`, `vendor-prefixed/`), fixed timestamps and
checks that the header version, `LABYRINTHE_CONNECT_VERSION`, the readme `Stable tag` and `block.json` agree.

**Technical choices.** The runtime dependencies (`labyrinthe/connect-php`, Guzzle, PSR interfaces) are copied by
PHP-Scoper into `vendor-prefixed/` under `LabyrintheConnect\Vendor\`, so another plugin bundling another Guzzle cannot
conflict. HTTP goes through `wp_remote_request()` (a PSR-18 adapter in `includes/http`). OTP uses the `admin-ajax.php`
actions `labyrinthe_otp_send` / `labyrinthe_otp_verify` (nonce-protected) and vanilla JavaScript (no build step).

```
labyrinthe-connect.php    plugin header, PHP check, autoloaders, hooks
uninstall.php             removes all data
includes/                 admin/ (settings, SMS log, profile field), http/, otp/, webhook/, woocommerce/
assets/  blocks/          widget and admin scripts/styles, otp-login block
languages/                .pot, fr_FR translations
examples/                 hook examples (not shipped in the zip)
bin/  tools/  tests/      build and test scripts, pinned build tools, PHPUnit suite
```

## Upgrading, releasing, contributing

- Changes: [CHANGELOG.md](CHANGELOG.md) (developers) and the changelog of [`readme.txt`](readme.txt) (site owners).
  Updates need no setting change.
- Releases: tag `wordpress-vX.Y.Z` (see [CONTRIBUTING.md](../CONTRIBUTING.md)); the workflow builds the zip, creates a
  GitHub release and deploys to the wordpress.org SVN.
- Issues and pull requests: [Labyrinthe-Team/labyrinthe-connect-sdks](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks).

## License

GPL-2.0-or-later — see [LICENSE](LICENSE). Bundled SDK and dependencies: MIT. Security issues: please email
connect@labyrinthe-rdc.com rather than opening a public issue.

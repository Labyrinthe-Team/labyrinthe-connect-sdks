# Labyrinthe Connect for WordPress & WooCommerce

Official WordPress plugin of [Labyrinthe Connect](https://labyrinthe-rdc.com) (slug `labyrinthe-connect`): WooCommerce
order SMS, phone verification and login with a one-time code received by SMS. Built on the
[PHP SDK](../php) and the [API specification](../openapi/openapi.yaml) (1.3.0), through the PHP SDK 1.2.0.

*Version française : [README.fr.md](README.fr.md). The user documentation for wordpress.org is [`readme.txt`](readme.txt).*

| | |
|---|---|
| WordPress | 6.4+ (tested up to 7.1) |
| PHP | **8.1+** — required by the PHP SDK (`labyrinthe/connect-php` needs `^8.1`). On older PHP the plugin shows a notice and stays inactive. |
| WooCommerce | 8.0+, optional (tested up to 11.2), HPOS and block checkout compatible |
| License | GPL-2.0-or-later (bundled SDK and dependencies: MIT) |

## Five-minute start (site owner)

1. Install `dist/labyrinthe-connect.zip` (*Plugins > Add New > Upload*), activate.
2. *Labyrinthe Connect > Settings*: paste a `lsk_test_…` key, **Test the connection**, **Send a test SMS**.
3. *WooCommerce* tab: enable the statuses that send an SMS. *OTP* tab: login by SMS, verification at registration / checkout.
4. Optional: add the webhook URL (`/wp-json/labyrinthe/v1/webhook`) in the client area and paste its secret.
5. Switch to the `lsk_live_…` key.

## What it does

| Feature | How |
|---|---|
| Order SMS per status | `woocommerce_order_status_changed` only **schedules** an Action Scheduler action (`labyrinthe_connect_order_sms`, unique per order + status). The action renders the template and sends it with the SDK. |
| No duplicates | Unique action, `_labyrinthe_sms_sent` order meta, and `Idempotency-Key: wc-<site>-<order>-<status>` reused by every retry (API replays it for 24 h). |
| Retries | Network error, 5xx or `TOO_MANY_REQUESTS`: the SDK retries once (safe, same key), then the action is rescheduled (5, 10, 15 min; 4 attempts). Business errors (`INSUFFICIENT_CREDITS`, `RECIPIENT_NOT_SENDABLE`…) are not retried. |
| Order notes | One note per SMS: sent (masked number, segments, encoding, message ID) or failed with the error code and the **request ID** (`X-Request-Id`, to give to the support); delivery / final failure (with `error_code` / `error_message`) from webhooks. Order SMS carry `reference: order-<order ID>`, so a delivery report finds its order even when the log row is gone. |
| Shop manager SMS | `woocommerce_checkout_order_processed` and `woocommerce_store_api_checkout_order_processed` → `labyrinthe_connect_admin_order_sms`. |
| Templates | Variables `{order_number}`, `{order_id}`, `{order_status}`, `{order_total}` (`25.00 USD`, ASCII), `{order_date}`, `{order_url}`, `{payment_method}`, `{customer_name}`, `{customer_first_name}`, `{customer_phone}`, `{shop_name}`, `{shop_url}`, `{tracking_number}`, `{tracking_url}` (Shipment Tracking meta). Preview of characters, encoding and segments counted by the API (`POST /sms/estimate`, nothing sent), with a local estimate while typing or without API key (any non-ASCII character → UCS-2). Optional accent removal, done by the API (`strip_accents`) for order SMS, test SMS and OTP codes. |
| OTP | `admin-ajax.php` actions `labyrinthe_otp_send` / `labyrinthe_otp_verify` (nonce). The `otp_id` stays in a server-side transient keyed by a random httpOnly cookie; it never reaches the browser. Every send carries a stable `Idempotency-Key` (`wp-otp-<site>-<hash of session + context + number>-<rank>`): a double click or a retry after a network error replays the same send (same code, no second SMS), only "Send a new code" after a successful send creates another one; the SDK retries once (network, 5xx, 429). The code field follows `code_length` / `code_type` (length, numeric or text keyboard, `autocomplete="one-time-code"`), the resend countdown follows `resend_after_seconds`, a wrong code shows `attempts_remaining`. **Web OTP**: `web_otp_domain` (the site domain by default, setting to change or turn off; ignored for `localhost` or an IP) adds `@domain #code` to the SMS and Chrome on Android fills in and checks the code. Per-IP limit (settings, default 5 codes / hour) on top of the backend limits. `test_code` is only returned to administrators with a `lsk_test_` key. |
| Login by SMS | WordPress login form, WooCommerce "My account", `[labyrinthe_otp_login redirect="/my-account/"]`, block `labyrinthe-connect/otp-login`. The account is found by the `labyrinthe_phone` user meta (set only by an OTP verification or by an administrator). Unknown numbers get the same answer without any SMS (no enumeration, no credit spent). Administrators are refused unless allowed. |
| Verification | Registration (WordPress and WooCommerce) and checkout (classic: `woocommerce_after_checkout_validation`; blocks: `RouteException` in `woocommerce_store_api_checkout_update_order_from_request`). |
| Webhooks | `POST /wp-json/labyrinthe/v1/webhook`: SDK signature check on the raw body (constant time, 5-minute timestamp tolerance), deduplication on `id` (primary key, `INSERT IGNORE`), SMS log update (a final status is never downgraded; `error_code` / `error_message` saved), order notes, `otp.verified` / `otp.failed` → WordPress actions. |
| SMS log | Table `{prefix}labyrinthe_sms_log`, `WP_List_Table` with filters and search (order, message ID, error code, request ID); numbers stored masked, texts never stored; request ID of every call (`wp-<uuid>`, sent as `X-Request-Id`, also for network errors); daily purge (setting, default 90 days). |
| Unknown outcome | `409 IDEMPOTENCY_KEY_UNRESOLVED` (`IdempotencyKeyUnresolvedException`): the original request with this key failed unexpectedly on the API side and an SMS may have been sent. Final failure, never retried nor rescheduled: order note and SMS log row with the error code and the request ID, asking to check the messages in the Labyrinthe Connect dashboard before sending manually. OTP: the visitor sees a neutral message ("request a new code if you did not receive it"); their next request uses a new idempotency key. |
| Maintenance | `503 FEATURE_DISABLED` / `OTP_SERVICE_DISABLED` (`FeatureDisabledException`): the platform shut the API, SMS sending or OTP down; nothing was sent. OTP: failure code `FEATURE_DISABLED` with the administrator's message (plain text) or a translated "temporarily unavailable" one, the expected return time when known, and `retry_after` for the countdown. Order SMS: rescheduled at the announced return time (`Retry-After`), otherwise 5, 10, 15 min; never retried in a loop. `Send_Result::$retry_after` exposes the delay. |
| Suspended account | `403 ACCOUNT_SUSPENDED` (`AccountSuspendedException`): clear error notice in the administration with the request ID, never retried; cleared by the next successful call. Visitors only see a neutral message. |
| Phone numbers | Normalised by the plugin (billing country calling code for local numbers), then sent as `+<international number>` (the `+` stops the SDK from adding 243 to a foreign number of 10 digits or fewer; SDK 1.1.1 sends it to the API with its `+`). The API keeps the country code for SMS and OTP alike (`+2481234567` → `2481234567`; `BACKEND-TODO.md` n° 34 fixed on the API side). |

## Hooks for developers

```php
// Events received by the webhook (decoded envelope { id, event, data, timestamp }).
add_action( 'labyrinthe_webhook_received', fn( array $envelope ) => null );
add_action( 'labyrinthe_message_status_changed', fn( array $envelope, ?array $log_row ) => null, 10, 2 ); // data: reference, error_code, error_message…
add_action( 'labyrinthe_otp_verified', fn( array $envelope ) => null );  // data: otp_id, phone, status, reason, verified_at (never the code)
add_action( 'labyrinthe_otp_failed', fn( array $envelope ) => null );    // status failed (max_attempts_reached) or expired
add_action( 'labyrinthe_campaign_completed', fn( array $envelope ) => null );
add_action( 'labyrinthe_balance_low', fn( array $envelope ) => null );
add_action( 'labyrinthe_webhook_test_ping', fn( array $envelope ) => null );

// After every SMS attempt of the plugin (no text, masked number).
add_action( 'labyrinthe_connect_sms_sent', fn( array $result, array $meta ) => null, 10, 2 );

// Filters.
add_filter( 'labyrinthe_connect_send_order_sms', fn( bool $send, WC_Order $order, string $status ) => $send, 10, 3 );
add_filter( 'labyrinthe_connect_order_sms_variables', fn( array $vars, WC_Order $order, string $status ) => $vars, 10, 3 );
add_filter( 'labyrinthe_connect_order_sms_body', fn( string $body, WC_Order $order, string $status ) => $body, 10, 3 );
add_filter( 'labyrinthe_connect_sms_body', fn( string $body, array $meta ) => $body, 10, 2 );
add_filter( 'labyrinthe_connect_tracking_url', fn( string $url, WC_Order $order ) => $url, 10, 2 );
add_filter( 'labyrinthe_connect_order_total', fn( string $total, WC_Order $order ) => $total, 10, 2 );
add_filter( 'labyrinthe_connect_otp_login_user', fn( ?WP_User $user, string $phone ) => $user, 10, 2 );
add_filter( 'labyrinthe_connect_otp_login_allowed', fn( bool $allowed, WP_User $user ) => $allowed, 10, 2 );
add_filter( 'labyrinthe_connect_client_ip', fn( string $ip ) => $ip );          // behind a trusted proxy / CDN
add_filter( 'labyrinthe_connect_webhook_tolerance', fn( int $seconds ) => 300 );
add_filter( 'labyrinthe_connect_delivery_order_notes', '__return_true' );
add_filter( 'labyrinthe_connect_web_otp_domain', fn( string $domain ) => 'shop.example.com' ); // '' turns Web OTP off
add_filter( 'labyrinthe_connect_base_url', fn( ?string $url ) => 'http://localhost:8000' ); // or LABYRINTHE_CONNECT_BASE_URL
```

`wp-config.php` constants: `LABYRINTHE_CONNECT_API_KEY`, `LABYRINTHE_CONNECT_WEBHOOK_SECRET` (take precedence over the
settings), `LABYRINTHE_CONNECT_BASE_URL`.

## Technical choices

- **Prefixed SDK.** The runtime dependencies (`labyrinthe/connect-php`, Guzzle, PSR interfaces) are copied by
  [PHP-Scoper](https://github.com/humbug/php-scoper) into `vendor-prefixed/` under `LabyrintheConnect\Vendor\`, with a
  generated PSR-4 autoloader: another plugin bundling another Guzzle or PSR version cannot conflict. PHP-Scoper is
  pinned in `tools/php-scoper/composer.lock`; `bin/prefix-dependencies.php` rebuilds the folder from scratch and checks
  the result (Strauss 0.31 was tried first: it does not run on Windows, the `getFsRoot()` regular expression does not
  match `E:\`).
- **HTTP through WordPress.** The SDK receives a PSR-18 adapter over `wp_remote_request()` (`includes/http`): proxies,
  certificates and `pre_http_request` work as for any plugin (the tests use it as a mock server).
- **Secrets.** API key and webhook secret are encrypted with libsodium `secretbox` (key derived from `wp_salt()`),
  never printed back (only `lsk_test_…ABCD`), never sent to the browser, never logged.
- **No API call on public page loads**: only AJAX actions, scheduled actions and the settings page call the API.
- **Uninstall** (`uninstall.php`) removes options, tables, transients, user and order meta (posts and HPOS tables) and
  scheduled actions, on every site of a multisite network.

## Development

```bash
composer install                 # dev tools + vendor-prefixed/ (PHP-Scoper runs automatically)
php bin/install-wp-tests.php     # WordPress + WooCommerce in .wp-tests/, database labyrinthe_wp_tests
                                 # (--wp=6.4.5 --wc=8.5.2 --db-user=root --db-pass= --db-host=127.0.0.1)
composer lint                    # PHPCS, WordPress Coding Standards + PHPCompatibilityWP 8.1+
composer analyse                 # PHPStan level 8 (WordPress + WooCommerce stubs)
composer test                    # PHPUnit with the WordPress test suite (HPOS; LABYRINTHE_TEST_HPOS=no for posts storage)
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 composer test:integration
composer i18n                    # languages/*.pot, update .po, compile .mo, .l10n.php and .json (WP-CLI in tools/wp-cli)
composer build                   # dist/labyrinthe-connect.zip + dist/labyrinthe-connect/
```

The build uses a whitelist (`labyrinthe-connect.php`, `uninstall.php`, `readme.txt`, `LICENSE`, `includes/`, `assets/`,
`blocks/`, `languages/`, `vendor-prefixed/`), fixed timestamps (`SOURCE_DATE_EPOCH`), checks that the header version,
the `LABYRINTHE_CONNECT_VERSION` constant, the readme `Stable tag` and `block.json` agree, and lints every PHP file.

### Layout

```
labyrinthe-connect.php    plugin header, PHP check, autoloaders, hooks
uninstall.php             removes all data
includes/                 plugin code (namespace LabyrintheConnect, WordPress file names)
  admin/                  settings page, SMS log (WP_List_Table), user profile field, AJAX tools
  http/                   PSR-18 client over the WordPress HTTP API
  otp/                    OTP service, session, IP limit, widget, login, registration, checkout
  webhook/                REST endpoint and event store
  woocommerce/            order notifications (Action Scheduler) and template variables
assets/                   admin and OTP widget scripts/styles (vanilla JS, no build step)
blocks/otp-login/         block.json + editor script (server-side rendered)
languages/                .pot, fr_FR .po/.mo/.l10n.php/.json
vendor-prefixed/          generated: prefixed SDK and dependencies (git-ignored)
bin/                      build, prefixing, i18n, test setup scripts
tools/                    pinned build tools (PHP-Scoper, WP-CLI i18n), isolated from the plugin dependencies
tests/                    PHPUnit (WordPress test suite + WooCommerce), mocked HTTP, integration test
```

## Releasing

Tag `wordpress-vX.Y.Z` (see [`CONTRIBUTING.md`](../CONTRIBUTING.md)): the `wordpress-release.yml` workflow checks the
versions, runs the checks, builds the zip, creates a GitHub release and deploys `dist/labyrinthe-connect/` to the
wordpress.org SVN repository with [10up/action-wordpress-plugin-deploy](https://github.com/10up/action-wordpress-plugin-deploy)
(secrets `SVN_USERNAME` / `SVN_PASSWORD`, assets from `.wordpress-org/`).

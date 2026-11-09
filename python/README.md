# Labyrinthe Connect — Python SDK

[![CI](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/python.yml/badge.svg)](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/python.yml)
[![PyPI](https://img.shields.io/pypi/v/labyrinthe-connect.svg)](https://pypi.org/project/labyrinthe-connect/)
![Python](https://img.shields.io/badge/python-3.9%20%E2%86%92%203.13-3776AB)
![Typed](https://img.shields.io/badge/typing-mypy%20strict-2A6DB2)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Official Python client for [Labyrinthe Connect](https://connect.labyrinthe-rdc.com): send SMS and verify phone numbers
with one-time codes (OTP), in the DR Congo and Central Africa.

*Version française : [README.fr.md](README.fr.md).*

- Synchronous **and** asyncio clients with the same API. Fully typed (`py.typed`, mypy `--strict`).
- Frozen dataclasses, `str` enums, timezone-aware `datetime`s, one exception class per error.
- Safe by default: automatic retries **only** when they cannot send an SMS twice.
- One dependency: [`httpx`](https://www.python-httpx.org/). Optional Django, FastAPI and Flask helpers.
- Never logs or prints your API key, OTP codes or full phone numbers.

SDK **1.2.1**, built against the API specification **1.3.0** ([`openapi/openapi.yaml`](../openapi/openapi.yaml)).

## Contents

- [Requirements](#requirements) · [Installation](#installation) · [Quick start](#quick-start) ·
  [Configuration](#configuration)
- [Sending SMS](#sending-sms) · [Balance and Sender IDs](#balance-and-sender-ids) ·
  [OTP](#otp-verify-a-phone-number) · [Webhooks](#webhooks)
- [Errors](#errors) · [Retries and idempotency](#retries-and-idempotency) · [Test mode](#test-mode) ·
  [Logging and security](#logging-and-security) · [Request IDs](#request-ids) · [Typing](#typing)
- [Without the package (raw HTTP)](#without-the-package-raw-http) · [Upgrading](#upgrading) ·
  [Contributing](#contributing) · [License](#license)

## Requirements

- Python **3.9 to 3.13** (CPython).
- `httpx` 0.25 or later (installed automatically).
- Optional: Django 3.2+, FastAPI 0.95+ or Flask 2.3+ for the webhook integrations.
- A Labyrinthe Connect API key, created in the client area (*Developers > API keys*). Start with a **test key**
  (`lsk_test_…`): nothing is sent, nothing is billed.

## Installation

```bash
pip install labyrinthe-connect
pip install "labyrinthe-connect[django]"        # or [fastapi], [flask]

uv add labyrinthe-connect                       # uv
uv add "labyrinthe-connect[fastapi]"

poetry add labyrinthe-connect                   # Poetry
poetry add "labyrinthe-connect[flask]"
```

## Quick start

```python
from labyrinthe_connect import Labyrinthe

client = Labyrinthe(api_key="lsk_test_…")            # or set LABYRINTHE_API_KEY
message = client.sms.send(to="0812345678", body="Your order 1042 is ready.")
print(message.status)                                 # queued
```

**Keep your keys server-side**, never in a browser or a mobile app.

| Key | Mode | Effect |
|---|---|---|
| `lsk_test_…` | Sandbox | Nothing is sent, no credit is used. Same responses as production. OTP sends return the code (`test_code`). |
| `lsk_live_…` | Production | SMS are really sent and credits are used. |

`client.is_test_mode` (and `client.is_live_mode`) tells you which one you are using. Every example below works with
a test key.

### Synchronous and asynchronous

```python
from labyrinthe_connect import AsyncLabyrinthe, Labyrinthe

with Labyrinthe() as client:                          # closes the HTTP connections at the end
    client.balance.get()

async with AsyncLabyrinthe() as client:               # same methods, awaited
    await client.balance.get()
```

`AsyncLabyrinthe` has exactly the same resources and parameters (`await client.sms.send(...)`,
`await client.otp.verify(...)`…). Close it with `async with` or `await client.aclose()`, and the sync client with
`with` or `client.close()`. Create one client per process and reuse it: it keeps the HTTP connections open.

## Configuration

```python
import os

import httpx
from labyrinthe_connect import Labyrinthe

client = Labyrinthe(
    api_key=os.environ["LABYRINTHE_API_KEY"],   # default: the LABYRINTHE_API_KEY environment variable
    base_url="https://api.connect.labyrinthe-rdc.com/api/public/v1",  # default (or LABYRINTHE_BASE_URL); a bare host is completed
    timeout=30.0,                # seconds, per attempt
    max_retries=2,               # 0 disables retries (max 10)
    max_retry_wait=10.0,         # longest Retry-After the SDK waits for
    default_country_code="243",  # added to local numbers: 0812345678 → 243812345678
    default_sender="MYSHOP",     # used when send() / send_many() get no sender
    http_client=httpx.Client(proxy="http://proxy:3128"),   # your own client (never closed by the SDK)
)
```

| Environment variable | Used for |
|---|---|
| `LABYRINTHE_API_KEY` | API key when `api_key` is not passed |
| `LABYRINTHE_BASE_URL` | API root when `base_url` is not passed (e.g. `http://localhost:8000`, completed with `/api/public/v1`) |
| `LABYRINTHE_WEBHOOK_SECRET` | webhook secret of the Django, FastAPI and Flask integrations |

`client.base_url` returns the API root in use. Every method also accepts `timeout=` (seconds, per attempt) and
`request_id=` for one call. The async client takes an `httpx.AsyncClient` as `http_client`.

## Sending SMS

```python
message = client.sms.send(
    to="+243 81 234 5678",       # +243…, 00243…, 243…, 0812…, 812…, 33612345678 are all accepted
    body="Your order 1042 is ready.",
    sender="MYSHOP",             # optional: an approved Sender ID (client.sender_ids.list())
    reference="order-1042",      # optional: your own reference, max 64 characters
    strip_accents=False,         # optional: see "Cost before sending"
    idempotency_key=None,        # optional: see "Retries and idempotency"
)

message.id                # "9d5c0a3e-…"
message.recipient         # "243812345678"
message.status            # MessageStatus.QUEUED
message.segments          # 1
message.credits_charged   # 1
message.created_at        # datetime (UTC, aware)
```

`body` holds up to 1600 characters. Sending is asynchronous: `202 Accepted` means *queued and paid for*, not
*delivered*. Follow the status with webhooks (`message.status_changed`, recommended) or by polling:

```python
from labyrinthe_connect import MessageStatus

latest = client.sms.get(message.id)
if latest.status.is_final:
    print("Delivered" if latest.status is MessageStatus.DELIVERED else f"Failed: {latest.error_code}")
```

`MessageStatus`: `queued`, `submitted`, `sent`, `delivered`, `failed`, `rejected`, `expired`, `undeliverable`, and
`unknown` for a status added after this SDK version. Helpers: `is_final`, `is_failed`, `is_pending`.

### Several recipients: all or nothing

```python
from labyrinthe_connect import RecipientNotSendableError

try:
    batch = client.sms.send_many(recipients=["0812345678", "0899999999"], body="Meeting tomorrow at 9am.")
    batch.messages        # one message per distinct number (duplicates are merged)
    batch.replayed        # True when the Idempotency-Key was replayed
except RecipientNotSendableError as error:
    # NOTHING was sent: fix the list and try again.
    for rejected in error.rejected:
        print(rejected.recipient, rejected.reason)    # invalid_phone, blacklisted, opted_out
```

Up to 1000 recipients per call. Every recipient, the Sender ID and the balance are checked **before** anything is
sent: one refused number (or an insufficient balance) means no SMS at all. Numbers the SDK can already tell are
invalid are refused locally (`InvalidArgumentError`, with their index only), before any network call.
`send()` returns a `Message`; `send_many()` returns a `MessageBatch` (`messages`, `idempotency_key`, `replayed`).

### Cost before sending

1 credit = 1 SMS segment. A pure ASCII message (GSM-7) holds 160 characters (153 per segment beyond). **Any
non-ASCII character** (é, à, ç, emoji, curly quotes…) switches to UCS-2: 70 characters (67 per segment beyond).

```python
estimate = client.sms.estimate(body="Réunion demain à 9h", recipients=["0812345678", "0899999999"])
estimate.encoding              # Encoding.UCS2
estimate.characters            # 19
estimate.segments_per_message  # 1
estimate.recipients_count      # 2 (normalised, duplicates merged, invalid numbers ignored)
estimate.total_credits         # 2
estimate.balance_after         # balance after such a send
estimate.is_affordable         # does the balance cover it?
```

`strip_accents=True` (on `send`, `send_many`, `estimate` and `otp.send`) asks the API to replace accents and
typographic characters with ASCII before sending: the message goes back to GSM-7, often half the price. The
transliteration depends on the server (`Café` may become `Cafe` or `Caf'e`): do not rely on it for an exact text.

```python
client.sms.estimate(body="Votre réservation est confirmée", recipients="0812345678", strip_accents=True).encoding
# Encoding.GSM7
```

### Listing messages and pagination

```python
from datetime import date, datetime, timezone

page = client.sms.list(status="failed", from_=date(2026, 11, 1), to=datetime.now(timezone.utc), per_page=50)
page.data, page.total, page.current_page, page.last_page, page.has_more
next_page = page.next_page()                     # None on the last page

for message in client.sms.list(reference="order-1042").auto_paging_iter():   # every page, fetched lazily
    ...

async for message in async_client.sms.list(reference="order-1042").auto_paging_iter():
    ...
first_page = await async_client.sms.list()       # awaiting the async paginator gives the first page
```

Filters: `status`, `recipient` (any format), `reference`, `from_`, `to` (a `date` covers the whole day; a
`datetime` must be timezone-aware), `page`, `per_page` (max 100). Only messages sent **with the calling key** are
listed. `client.sms.get(id)` reads one message of the account, by `id` only.

## Balance and Sender IDs

```python
balance = client.balance.get()
balance.balance, balance.held          # credits available, credits held by sends in progress

for sender_id in client.sender_ids.list():   # approved Sender IDs, usable as `sender`
    sender_id.value, sender_id.status, sender_id.is_default
```

## OTP: verify a phone number

The code is generated, sent by SMS and checked by Labyrinthe Connect. It is never returned by the API (except
with a test key) and never logged by the SDK.

```python
from labyrinthe_connect import OtpFailureReason

# 1. Send the code. Keep otp_id SERVER-SIDE (session, cache, database), never in the browser.
otp = client.otp.send(phone="0812345678")
request.session["otp_id"] = otp.otp_id
otp.code_length, otp.code_type      # 6, OtpCodeType.NUMERIC (or ALPHANUMERIC): pick the right input field
otp.expires_at, otp.max_attempts    # aware datetime, 5
otp.resend_after_seconds            # 60: countdown before "Resend the code"

# 2. The user types the code received by SMS.

# 3. Verify it.
result = client.otp.verify(otp_id=request.session["otp_id"], code=typed_code)
if result.verified:
    del request.session["otp_id"]          # log the user in / mark the number as verified
elif result.reason is OtpFailureReason.INVALID_CODE:
    ...                                     # wrong code: result.attempts_remaining tries left
else:
    ...                                     # EXPIRED, ALREADY_USED, MAX_ATTEMPTS_REACHED: ask for a new code
```

- A wrong code is **not** an exception: `verify()` returns `OtpVerifyResult(verified=False, reason=…)`. Verifying is
  free.
- A new `send()` to the same number invalidates the previous code.
- Length, validity, attempts, Sender ID and template come from the account's OTP settings. You can override the
  template (`template="Your MyShop code: {code}"`, must contain `{code}`; optional `{app_name}`, `{minutes}`) and
  the language (`locale="fr"` or `"en"`, or `OtpLocale`).
- `attempts_remaining`: an integer after `invalid_code`, `0` with `max_attempts_reached`, `None` when verified,
  already used or expired.
- `web_otp_domain="app.example.com"` adds the `@app.example.com #123456` line used by the
  [Web OTP API](https://developer.mozilla.org/docs/Web/API/WebOTP_API) to fill the code automatically (Chrome,
  Android). `strip_accents=True` keeps the message in GSM-7.
- Limits: `OtpResendTooSoonError` (wait `retry_after` seconds; a new send is accepted right after a successful
  verification) and `OtpRateLimitedError` (`scope` = `phone` or `account`, `limit`, `retry_after`). The API also
  allows at most 10 `otp/send` calls per minute per key.
- Like SMS sends, every `otp.send()` carries an `Idempotency-Key` (a `uuid4`, or yours: `idempotency_key=`): a
  replay returns the same `otp_id` (and the same `test_code` in test mode) without a second SMS, with
  `otp.replayed` set to `True`.

See [`examples/otp_login.py`](examples/otp_login.py) for the whole flow.

## Webhooks

Configure an endpoint in the client area (*Developers > Webhooks*) and copy its secret. Events are `POST`ed as
JSON `{ id, event, data, timestamp }` and signed (`X-Signature: sha256=<HMAC-SHA256 of the raw body>`). The SDK
verifies the signature in constant time (`hmac.compare_digest`) on the **raw body**, then checks the signed
timestamp (300 seconds by default, `tolerance=`).

```python
from labyrinthe_connect import MessageStatusChanged, SignatureVerificationError, webhooks

try:
    event = webhooks.construct_event(raw_body, headers.get("X-Signature"), secret)   # bytes, never parsed JSON
except SignatureVerificationError:
    ...   # answer 400
if isinstance(event, MessageStatusChanged):
    update_message(event.data.message_id, event.data.status)
```

**Always pass the raw body** (the exact bytes received): `request.body` in Django, `await request.body()` in
FastAPI / Starlette, `request.get_data()` in Flask. Re-serialising parsed JSON changes the bytes and breaks the
signature.

Every event has `id` (deduplicate on it: deliveries are retried up to 5 times and may arrive out of order),
`type` (the event name as sent), `timestamp` (aware `datetime`), `raw` (the decoded envelope) and a typed `data`:

| Class | `event.type` | `data` |
|---|---|---|
| `MessageStatusChanged` | `message.status_changed` | `message_id`, `recipient`, `status`, `provider_message_id`, `campaign_id`, `reference`, `error_code`, `error_message` |
| `CampaignCompleted` | `campaign.completed` | `campaign_id`, `name`, `recipients_count`, `messages_by_status`, `credits_charged`, `completed_at` |
| `BalanceLow` | `balance.low` | `balance`, `threshold` |
| `OtpVerified` | `otp.verified` | `otp_id`, `phone`, `status` (`verified`), `reason` (`None`), `verified_at` |
| `OtpFailed` | `otp.failed` | `otp_id`, `phone`, `status` (`failed` / `expired`), `reason` (`max_attempts_reached` / `expired`), `verified_at` (`None`) |
| `TestPing` | `test.ping` | `{}` |
| `UnknownEvent` | a type added after this SDK version | as sent; acknowledge it with a 2xx |

Invalid signature or expired timestamp: `SignatureVerificationError`; signed body that is not an envelope:
`InvalidWebhookPayloadError` — answer `400` to both. Answer `2xx` within 10 seconds; queue slow work.
`webhooks.sign(body, secret)` builds a valid header for your tests and `webhooks.verify_signature(body, header,
secret)` only checks the signature. The three integrations below read the secret from `LABYRINTHE_WEBHOOK_SECRET`
by default.

### Django

```python
# urls.py
from django.urls import path
from labyrinthe_connect.contrib.django import WebhookView

urlpatterns = [path("webhooks/labyrinthe/", WebhookView.as_view())]   # settings.LABYRINTHE_WEBHOOK_SECRET

# receivers.py (imported from AppConfig.ready())
from django.dispatch import receiver
from labyrinthe_connect.contrib.django import message_status_changed, otp_verified

@receiver(message_status_changed)
def on_status(sender, event, **kwargs):
    Order.objects.filter(sms_id=event.data.message_id).update(sms_status=event.data.status)

@receiver(otp_verified)
def on_otp_verified(sender, event, **kwargs):
    PhoneCheck.objects.filter(otp_id=event.data.otp_id).update(verified=True)
```

The secret comes from `WebhookView.as_view(secret=...)`, then `settings.LABYRINTHE_WEBHOOK_SECRET`, then the
environment variable. CSRF-exempt, POST only, `400` on a bad signature, `200 {"received": true}` otherwise. Signals:
`webhook_received` (all), `message_status_changed`, `campaign_completed`, `balance_low`, `otp_verified`,
`otp_failed`, `test_ping`, `unknown_event`; receivers get `sender` (the event class), `event` and `request`. A
receiver that raises gives a `500` (the delivery is retried). Override `WebhookView.handle_event()` to handle events
without signals.

### FastAPI

```python
from fastapi import Depends, FastAPI
from labyrinthe_connect import WebhookEvent
from labyrinthe_connect.contrib.fastapi import WebhookVerifier, create_webhook_router

app = FastAPI()
verify_webhook = WebhookVerifier()          # secret from LABYRINTHE_WEBHOOK_SECRET (or WebhookVerifier(secret=...))

@app.post("/webhooks/labyrinthe")
async def labyrinthe_webhook(event: WebhookEvent = Depends(verify_webhook)) -> dict:
    ...
    return {"received": True}

# or a ready-made route calling a sync or async handler:
app.include_router(create_webhook_router(on_event=handle_event, path="/hooks/labyrinthe"))
```

The dependency reads the raw body (`await request.body()`) and raises `HTTPException(400)` on a bad signature.
See [`examples/webhook_fastapi.py`](examples/webhook_fastapi.py).

### Flask

```python
from flask import Flask
from labyrinthe_connect import MessageStatusChanged
from labyrinthe_connect.contrib.flask import create_webhook_blueprint

def handle_event(event):
    if isinstance(event, MessageStatusChanged):
        update_message(event.data.message_id, event.data.status)

app = Flask(__name__)
app.config["LABYRINTHE_WEBHOOK_SECRET"] = "…"                       # or the environment variable
app.register_blueprint(create_webhook_blueprint(handle_event))     # POST /webhooks/labyrinthe
```

Options: `secret=`, `tolerance=`, `rule=`, `name=`, `url_prefix=`. Every verified event is also sent through the
`webhook_received` blinker signal (`labyrinthe_connect.contrib.flask.webhook_received`). See
[`examples/webhook_flask.py`](examples/webhook_flask.py).

## Errors

Every exception extends `LabyrintheError` and has `code` (stable, for your code), `message`, `status` (HTTP status,
or `None` without a response), `details` (always a `dict`) and `request_id`. API errors extend `ApiError` and also
expose `headers` and `api_version` (`X-Api-Version`). API messages are in French: branch on `code` or on the
exception class, never on the message.

| Exception | When |
|---|---|
| `AuthenticationError` | 401 — missing, invalid or revoked key |
| `PermissionError` | 403 — IP not allowed (`FORBIDDEN`), OTP disabled for the account (`OTP_DISABLED_FOR_ACCOUNT`) |
| `AccountSuspendedError` | 403 `ACCOUNT_SUSPENDED` — account suspended by the platform (extends `PermissionError`) |
| `NotFoundError` | 404 — unknown message or `otp_id` |
| `ValidationError` | 422 `VALIDATION_ERROR` — `field_errors` |
| `RecipientNotSendableError` | 422 — `rejected`, `reason`; nothing was sent |
| `SenderIdNotApprovedError` | 422 — `sender` is not an approved Sender ID |
| `InvalidPhoneError` | 422 `INVALID_PHONE` (OTP) |
| `InsufficientCreditsError` | 409 — `available`, `required`; nothing was sent |
| `IdempotencyKeyReusedError` | 409 — same key, different request |
| `IdempotencyRequestInProgressError` | 409 — original request still running (already retried); `retry_after` |
| `IdempotencyKeyUnresolvedError` | 409 `IDEMPOTENCY_KEY_UNRESOLVED` — outcome of the original request unknown; never retried: check, then send with a new key |
| `RateLimitError` | 429 `TOO_MANY_REQUESTS` — `retry_after` (seconds) |
| `OtpResendTooSoonError`, `OtpRateLimitedError` | 429 — extend `RateLimitError`; `retry_after`, `scope`, `limit` |
| `ServiceUnavailableError` | 5xx |
| `FeatureDisabledError` | 503 `FEATURE_DISABLED` / `OTP_SERVICE_DISABLED` — maintenance, nothing was done (extends `ServiceUnavailableError`); `feature`, `blocked_by`, `admin_message`, `until`, `retry_after` |
| `ApiError` | any other code (the list is open: new codes may appear, e.g. `INVALID_OTP_TEMPLATE`) |
| `APIConnectionError`, `APITimeoutError` | network error (`CONNECTION_ERROR`) or timeout (`TIMEOUT`) |
| `InvalidArgumentError` | invalid input detected by the SDK, before any call (also a `ValueError`) |
| `UnexpectedResponseError` | a response the SDK cannot read |
| `SignatureVerificationError`, `InvalidWebhookPayloadError` | webhook verification (answer 400) |

`PermissionError` shadows the built-in of the same name: prefer `from labyrinthe_connect import errors` and
`errors.PermissionError`.

```python
import logging

from labyrinthe_connect import ApiError, InsufficientCreditsError, LabyrintheError, ValidationError

logger = logging.getLogger(__name__)

try:
    client.sms.send(to="0812345678", body="Hello")
except InsufficientCreditsError as error:
    alert_finance(error.available, error.required)
except ValidationError as error:
    logger.warning("Invalid fields: %s", error.field_errors)      # {"body": ("Le champ body est obligatoire.",)}
except ApiError as error:
    logger.error("Labyrinthe Connect error %s (HTTP %s, request %s)", error.code, error.status, error.request_id)
except LabyrintheError as error:                                   # network, timeout, local validation
    logger.error("Labyrinthe Connect unreachable: %s (request %s)", error.code, error.request_id)
```

### Maintenance (`FeatureDisabledError`)

The platform can switch off the whole API (`blocked_by == "api"`, refused before authentication) or one function
(`api.sms_send`, `api.otp_send`, `api.otp_verify`, or the whole OTP service `otp` with `OTP_SERVICE_DISABLED`).
Nothing is sent and no credit is used. `admin_message` is the administrator's message (`None` without one), `until`
the scheduled re-enable (aware `datetime`, `None` when unknown) and `retry_after` the seconds left (`Retry-After`,
`None` when no date is scheduled). The SDK already waited and retried when `retry_after` fits in `max_retry_wait`;
never retry in a loop: queue the work or tell the user.

```python
from labyrinthe_connect import FeatureDisabledError

try:
    client.sms.send(to="0812345678", body="Your order has shipped")
except FeatureDisabledError as error:
    # Nothing was sent: try again later, after error.until when it is known.
    queue.enqueue_in(error.retry_after or 600, send_shipping_sms, order_id)
    logger.warning("SMS paused by Labyrinthe Connect (%s): %s", error.blocked_by, error.admin_message or error.message)
```

When only the OTP service is off, codes already sent can still be verified.

## Retries and idempotency

### Retries and timeouts

The SDK retries automatically (default: 2 retries, exponential backoff 0.5 s → 1 s → 2 s with jitter) **only when a
replay cannot cause a side effect twice**:

| Request | Network error / timeout | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` | 503 `FEATURE_DISABLED` / `OTP_SERVICE_DISABLED` |
|---|---|---|---|---|---|
| `get`, `list`, `balance.get`, `sender_ids.list`, `estimate` | retried | retried | retried after `Retry-After` | — | only after `Retry-After` |
| `send` / `send_many` / `otp.send` (always with an `Idempotency-Key`) | retried, same key | retried, same key | retried after `Retry-After` | retried after `Retry-After`, same key | only after `Retry-After`, same key |
| `otp.verify` | **never** | **never** | retried (refused before processing) | — | only after `Retry-After` (refused before processing) |

Business errors (other 4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, `IDEMPOTENCY_KEY_UNRESOLVED`) are never
retried. When the API asks to wait longer than `max_retry_wait` (default 10 s), the SDK raises the error
(`RateLimitError`…) instead of waiting. A maintenance 503 (`FeatureDisabledError`) is never retried with a backoff:
only after exactly `Retry-After`, when it is present and within `max_retry_wait`. Redirects are never followed.
`timeout=` (seconds) applies to each attempt.

### Idempotency (no double sends)

Every send carries an `Idempotency-Key`. When you do not give one, the SDK generates a `uuid4` and reuses it for its
own retries, so a network error never produces a second SMS. To also protect against **your** retries (task
queues, double clicks, crashes), pass a key derived from your business event (255 characters max). A replay within
24 hours returns the original messages without sending or billing again:

```python
batch = client.sms.send_many(recipients=["0812345678"], body="Your order 1042 is ready.",
                             idempotency_key="order-1042-ready")
batch.replayed          # True when this was a replay
batch.idempotency_key   # "order-1042-ready" (or the generated uuid4)
```

The same key with a different content is refused with `IdempotencyKeyReusedError` (409). The fingerprint uses the
normalised, de-duplicated recipients: `0812345678` then `243812345678` with the same key is a replay. While the
original request is still running, the API answers `409 IDEMPOTENCY_REQUEST_IN_PROGRESS`: the SDK waits
`Retry-After` (5 s) and retries with the same key; once its retries are exhausted it raises
`IdempotencyRequestInProgressError`, and retrying with the same key is still safe. `/sms` and `/otp/send` have
separate key spaces.

If the original request failed unexpectedly on the API side, its outcome is unknown (an SMS or an OTP code may have
been sent) and every replay with that key gets `409 IDEMPOTENCY_KEY_UNRESOLVED`, raised as
`IdempotencyKeyUnresolvedError`. The SDK never retries it, and **you must not retry it automatically** either:

1. check what happened: `client.sms.list(reference="order-1042")` for an SMS (send a `reference` for this), or ask the
   user whether a code arrived for an OTP;
2. only if nothing was sent, send again with a **new** `Idempotency-Key` (the old one stays unresolved).

```python
from labyrinthe_connect import IdempotencyKeyUnresolvedError

try:
    client.sms.send(to="0812345678", body="Order ready", reference="order-1042",
                    idempotency_key="order-1042-ready")
except IdempotencyKeyUnresolvedError as error:
    logger.warning("Outcome unknown, request %s: check before sending again", error.request_id)
    already_sent = client.sms.list(reference="order-1042", per_page=1).data
```

`otp.send()` is retried after a network error, a 5xx, a `TOO_MANY_REQUESTS` or an
`IDEMPOTENCY_REQUEST_IN_PROGRESS` (same key, so never a second code), never after `OTP_RESEND_TOO_SOON` or
`OTP_RATE_LIMITED`. Pass your own key (e.g. derived from the login attempt) to make your own retries safe too.

## Test mode

With a `lsk_test_…` key the provider is simulated: **nothing is sent and no credit is used**, and responses have the
same shape as in production, with two differences:

- the balance is not checked, so `InsufficientCreditsError` is never raised in the sandbox;
- `otp.send()` returns the code in `otp.test_code`, so you can test the whole OTP flow end to end. It is always
  `None` with a live key, hidden from `repr()` and dropped when the result is pickled.

Every other check (validation, Sender IDs, idempotency, rate limits, maintenance) behaves like production. Use
`client.is_test_mode` to branch, e.g. to read the code in tests:

```python
otp = client.otp.send(phone="0812345678")
code = otp.test_code if client.is_test_mode else input("Code: ")
```

## Logging and security

- **Logging**: the `labyrinthe_connect` logger (`logging`) emits debug/info/warning/error records — never the key,
  a code, a body or a full phone number (`24381*****78`). Nothing is printed unless your application configures
  logging, e.g. `logging.getLogger("labyrinthe_connect").setLevel(logging.DEBUG)`.
- `repr(client)` shows `lsk_test_****`; clients refuse to be pickled (they hold the key): create one per process.
- Keep the key in an environment variable or a secret manager, never in the source code, a browser or a mobile app.
  A key can be restricted to a list of IP addresses (403 `FORBIDDEN` otherwise).
- Phone numbers are personal data: mask them in your own logs with `labyrinthe_connect.phone.mask()`.
- Phone helpers: `labyrinthe_connect.phone.normalize("0812345678")` returns `"243812345678"` (or `None`) with the
  same rules as the API: without `+`, 11 digits or more not starting with 0 are already international
  (`33612345678` stays `33612345678`); otherwise a local number gets the default country code. For a short foreign
  number (10 digits or fewer), always use the `+`. Requests send the normalised number in E.164 with its `+`
  (`+243812345678`), so a short foreign number keeps its country code. `is_valid()` and `mask()` are also
  available. International numbers are never blocked.
- `User-Agent: labyrinthe-python/<version> Python/<version>`.

## Request IDs

Every API response carries an `X-Request-Id` header, to give to support. The SDK exposes it as `request_id` on every
error and on every returned object (`Message`, `MessageBatch`, `Estimate`, `Balance`, `SenderId`, `OtpSendResult`,
`OtpVerifyResult`, pages); it is left out of equality and `repr()`. Every method also accepts `request_id=` to send
your own identifier (1 to 128 characters among `A-Z a-z 0-9 . _ : -`, checked locally), reused by every retry of the
call and echoed by the API — handy to correlate with your own logs, also on network errors.

```python
balance = client.balance.get(request_id="checkout-1042")
balance.request_id   # "checkout-1042"
```

## Typing

The package ships `py.typed` and passes `mypy --strict`. Results are frozen dataclasses (`Message`, `MessageBatch`,
`Estimate`, `Balance`, `SenderId`, `OtpVerifyResult`…) with timezone-aware `datetime`s. Statuses are `str` enums
(`MessageStatus`, `Encoding`, `SenderIdStatus`, `RejectionReason`, `OtpFailureReason`, `OtpCodeType`,
`OtpRateLimitScope`, `OtpVerificationStatus`, `WebhookEventType`) that compare equal to their value
(`message.status == "queued"`); a value added to the API later is read as `UNKNOWN`. `WebhookEvent` is a `Union` of
the event classes: narrow it with `isinstance` (or `match` on Python 3.10+).

```python
from labyrinthe_connect import Message, MessageStatus, SyncPage

def failed(page: SyncPage[Message]) -> list[Message]:
    return [m for m in page.data if m.status is MessageStatus.FAILED]
```

## Without the package (raw HTTP)

The API is plain JSON over HTTPS ([`openapi/openapi.yaml`](../openapi/openapi.yaml)): you can call it with `httpx`
or `requests` directly. You then handle yourself what the SDK does for you: the `Idempotency-Key`, safe retries,
error mapping and webhook verification.

```python
import os
import uuid

import httpx

BASE_URL = "https://api.connect.labyrinthe-rdc.com/api/public/v1"
headers = {"Authorization": f"Bearer {os.environ['LABYRINTHE_API_KEY']}", "Accept": "application/json"}

response = httpx.post(
    f"{BASE_URL}/sms",
    headers={**headers, "Idempotency-Key": str(uuid.uuid4())},   # reuse the SAME key if you retry
    json={"recipient": "+243812345678", "body": "Your order 1042 is ready.", "reference": "order-1042"},
    timeout=30,
)
if response.status_code == 202:
    message = response.json()["data"][0]           # a list of messages, one per recipient
    print(message["id"], message["status"])
else:
    error = response.json()["error"]               # {"code", "message", "details"}
    print(error["code"], response.headers.get("X-Request-Id"))
```

With `requests`, the call is the same (`requests.post(url, headers=..., json=..., timeout=30)`). Other routes:
`POST /sms` with `recipients` (list), `POST /sms/estimate`, `GET /sms`, `GET /sms/{id}`, `GET /balance`,
`GET /sender-ids`, `POST /otp/send` (`phone`), `POST /otp/verify` (`otp_id`, `code`).

Verify a webhook on the raw body, in constant time, and reject old timestamps:

```python
import hashlib
import hmac
import json
import time

def verify(raw_body: bytes, signature_header: str, secret: str) -> dict:
    expected = "sha256=" + hmac.new(secret.encode(), raw_body, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, (signature_header or "").strip().lower()):
        raise ValueError("invalid signature")      # answer 400
    event = json.loads(raw_body)
    if abs(time.time() - event["timestamp"]) > 300:
        raise ValueError("timestamp too old")      # answer 400 (replay)
    return event                                   # {"id", "event", "data", "timestamp"}: deduplicate on id
```

## Upgrading

The SDK follows [SemVer](https://semver.org/); see the [CHANGELOG](CHANGELOG.md).

- **1.1.x → 1.2.0** (spec 1.3.0, backwards compatible): new `FeatureDisabledError` (503 `FEATURE_DISABLED`), a
  subclass of `ServiceUnavailableError`, so existing `except ServiceUnavailableError` blocks still catch it.
  `OTP_SERVICE_DISABLED` is now raised as `FeatureDisabledError` too. Maintenance 503s are only retried after
  `Retry-After`, never with a backoff.
- **1.1.0 → 1.1.1**: `IdempotencyKeyUnresolvedError`; phone numbers are sent in E.164 with their `+`.
- **1.0 → 1.1**: `request_id` everywhere, automatic `Idempotency-Key` on `otp.send()`, `strip_accents`,
  `web_otp_domain`, OTP webhooks.

The API adds fields, error codes and webhook events without notice in v1: the SDK ignores unknown fields and maps
unknown values to `UNKNOWN` / `UnknownEvent` / `ApiError`, so an older SDK keeps working.

## Contributing

Issues and pull requests go to the monorepo
[`Labyrinthe-Team/labyrinthe-connect-sdks`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks); see
[`CONTRIBUTING.md`](../CONTRIBUTING.md). For a security issue, email connect@labyrinthe-rdc.com rather than opening a
public issue.

```bash
python -m venv .venv && . .venv/bin/activate     # Windows: .venv\Scripts\activate
pip install -r requirements-dev.txt -e .
ruff check . && ruff format --check . && mypy
pytest                         # unit + OpenAPI contract tests (mocked transport, no network)
python -m build && twine check --strict dist/*

# integration tests against a local backend, with a TEST key only
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 pytest -m integration
```

The API contract lives in [`openapi/openapi.yaml`](../openapi/openapi.yaml); the contract tests keep the SDK
aligned with it. Examples: [`examples/`](examples) (`send_sms.py`, `async_send.py`, `otp_login.py`,
`webhook_fastapi.py`, `webhook_flask.py`). Update the [CHANGELOG](CHANGELOG.md) under `[Unreleased]`.

## License

MIT — see [LICENSE](LICENSE).

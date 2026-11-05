# Labyrinthe Connect — Python SDK

[![CI](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/python.yml/badge.svg)](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/python.yml)
[![PyPI](https://img.shields.io/pypi/v/labyrinthe-connect.svg)](https://pypi.org/project/labyrinthe-connect/)
![Python](https://img.shields.io/badge/python-3.9%20%E2%86%92%203.13-3776AB)

Official Python client for [Labyrinthe Connect](https://labyrinthe-rdc.com): send SMS and verify phone numbers
with one-time codes (OTP), in the DR Congo and Central Africa.

*Version française : [README.fr.md](README.fr.md).*

- Synchronous **and** asyncio clients with the same API. Fully typed (`py.typed`, mypy `--strict`).
- Frozen dataclasses, `str` enums, timezone-aware `datetime`s, one exception class per error.
- Safe by default: automatic retries **only** when they cannot send an SMS twice.
- One dependency: [`httpx`](https://www.python-httpx.org/). Optional Django, FastAPI and Flask helpers.
- Never logs or prints your API key, OTP codes or full phone numbers.

## Installation

```bash
pip install labyrinthe-connect
pip install "labyrinthe-connect[django]"     # or [fastapi], [flask]
```

## Quick start

```python
from labyrinthe_connect import Labyrinthe

client = Labyrinthe(api_key="lsk_test_…")            # or set LABYRINTHE_API_KEY
message = client.sms.send(to="0812345678", body="Your order 1042 is ready.")
print(message.status)                                 # queued
```

Create your keys in the client area (*Developers > API keys*). **Keep them server-side**, never in a browser or a
mobile app.

| Key | Mode | Effect |
|---|---|---|
| `lsk_test_…` | Sandbox | Nothing is sent, no credit is used. Same responses as production. OTP sends return the code (`test_code`). |
| `lsk_live_…` | Production | SMS are really sent and credits are used. |

`client.is_test_mode` tells you which one you are using. Every example below works with a test key.

### Synchronous and asynchronous

```python
from labyrinthe_connect import AsyncLabyrinthe, Labyrinthe

with Labyrinthe() as client:                          # closes the HTTP connections at the end
    client.balance.get()

async with AsyncLabyrinthe() as client:               # same methods, awaited
    await client.balance.get()
```

## Sending SMS

```python
message = client.sms.send(
    to="+243 81 234 5678",       # +243…, 00243…, 243…, 0812…, 812… are all accepted
    body="Your order 1042 is ready.",
    sender="MYSHOP",             # optional: an approved Sender ID (client.sender_ids.list())
    reference="order-1042",      # optional: your own reference, max 64 characters
)

message.id                # "9d5c0a3e-…"
message.recipient         # "243812345678"
message.status            # MessageStatus.QUEUED
message.segments          # 1
message.credits_charged   # 1
message.created_at        # datetime (UTC, aware)
```

Sending is asynchronous: `202 Accepted` means *queued and paid for*, not *delivered*. Follow the status with
webhooks (`message.status_changed`, recommended) or by polling:

```python
from labyrinthe_connect import MessageStatus

latest = client.sms.get(message.id)
if latest.status.is_final:
    print("Delivered" if latest.status is MessageStatus.DELIVERED else f"Failed: {latest.error_code}")
```

### Cost before sending

1 credit = 1 SMS segment. A pure ASCII message (GSM-7) holds 160 characters (153 per segment beyond). **Any
non-ASCII character** (é, à, ç, emoji, curly quotes…) switches to UCS-2: 70 characters (67 per segment beyond).

```python
estimate = client.sms.estimate(body="Réunion demain à 9h", recipients=["0812345678", "0899999999"])
estimate.encoding        # Encoding.UCS2
estimate.total_credits   # 2
estimate.is_affordable   # does the balance cover it?
```

### Several recipients: all or nothing

```python
from labyrinthe_connect import RecipientNotSendableError

try:
    batch = client.sms.send_many(recipients=["0812345678", "0899999999"], body="Meeting tomorrow at 9am.")
    batch.messages        # one message per distinct number (duplicates are merged)
except RecipientNotSendableError as error:
    # NOTHING was sent: fix the list and try again.
    for rejected in error.rejected:
        print(rejected.recipient, rejected.reason)    # invalid_phone, blacklisted, opted_out
```

Up to 1000 recipients per call. Every recipient, the Sender ID and the balance are checked **before** anything is
sent: one refused number (or an insufficient balance) means no SMS at all. Numbers the SDK can already tell are
invalid are refused locally (`InvalidArgumentError`, with their index only), before any network call.

### Idempotency (no double sends)

Every send carries an `Idempotency-Key`. When you do not give one, the SDK generates a `uuid4` and reuses it for its
own retries, so a network error never produces a second SMS. To also protect against **your** retries (task
queues, double clicks, crashes), pass a key derived from your business event. A replay within 24 hours returns the
original messages without sending or billing again:

```python
batch = client.sms.send_many(recipients=["0812345678"], body="Your order 1042 is ready.",
                             idempotency_key="order-1042-ready")
batch.replayed   # True when this was a replay
```

The same key with a different content is refused with `IdempotencyKeyReusedError` (409).

### Listing messages

```python
from datetime import date, datetime, timezone

page = client.sms.list(status="failed", from_=date(2026, 11, 1), to=datetime.now(timezone.utc), per_page=50)
page.total, page.data, page.has_more, page.next_page()

for message in client.sms.list(reference="order-1042").auto_paging_iter():   # every page, fetched lazily
    ...

async for message in async_client.sms.list(reference="order-1042").auto_paging_iter():
    ...
```

Filters: `status`, `recipient` (any format), `reference`, `from_`, `to` (a `date` covers the whole day; a
`datetime` must be timezone-aware), `page`, `per_page` (max 100). Only messages sent **with the calling key**.
With the async client, `await client.sms.list()` gives the first page.

### Balance and Sender IDs

```python
balance = client.balance.get()         # balance.balance, balance.held
sender_ids = client.sender_ids.list()  # approved names, usable as `sender`
```

## OTP: verify a phone number

The code is generated, sent by SMS and checked by Labyrinthe Connect. It is never returned by the API (except
with a test key) and never logged by the SDK.

```python
from labyrinthe_connect import OtpFailureReason

# 1. Send the code. Keep otp_id SERVER-SIDE (session, cache, database), never in the browser.
otp = client.otp.send(phone="0812345678")
request.session["otp_id"] = otp.otp_id

# 2. The user types the code received by SMS.

# 3. Verify it.
result = client.otp.verify(otp_id=request.session["otp_id"], code=typed_code)
if result.verified:
    del request.session["otp_id"]          # log the user in / mark the number as verified
elif result.reason is OtpFailureReason.INVALID_CODE:
    ...                                     # wrong code: one attempt consumed, let the user try again
else:
    ...                                     # EXPIRED, ALREADY_USED, MAX_ATTEMPTS_REACHED: ask for a new code
```

- A wrong code is **not** an exception: `verify()` returns `OtpVerifyResult(verified=False, reason=…)`.
- A new `send()` to the same number invalidates the previous code.
- Length, validity, attempts, Sender ID and template come from the account's OTP settings. You can override the
  template (`template="Your MyShop code: {code}"`, must contain `{code}`; optional `{app_name}`, `{minutes}`) and
  the language (`locale="fr"` or `"en"`).
- Limits: `OtpResendTooSoonError` (wait `retry_after` seconds) and `OtpRateLimitedError` (`scope`, `limit`).
- **Test mode**: with a `lsk_test_` key no SMS is sent and `otp.test_code` holds the code, so you can test the
  whole flow. Always `None` with a live key; hidden from `repr()` and dropped when the result is pickled.

`otp.send()` is never retried after a network error or a 5xx (the SMS may have left): show an error and let the
user ask for a new code.

## Webhooks

Configure an endpoint in the client area (*Developers > Webhooks*) and copy its secret. Events are `POST`ed as
JSON and signed (`X-Signature: sha256=<HMAC-SHA256 of the raw body>`). The SDK verifies the signature in constant
time (`hmac.compare_digest`) on the **raw body**, then checks the signed timestamp (5 minutes by default).

```python
from labyrinthe_connect import MessageStatusChanged, SignatureVerificationError, webhooks

try:
    event = webhooks.construct_event(raw_body, headers.get("X-Signature"), secret)   # bytes, never parsed JSON
except SignatureVerificationError:
    ...   # answer 400
if isinstance(event, MessageStatusChanged):
    update_message(event.data.message_id, event.data.status)
```

Every event has `id` (deduplicate on it: deliveries are retried up to 5 times and may arrive out of order),
`type`, `timestamp` (aware `datetime`), `raw` and a typed `data`:

| Class | `event.type` | `data` |
|---|---|---|
| `MessageStatusChanged` | `message.status_changed` | `message_id`, `recipient`, `status`, `provider_message_id`, `campaign_id` |
| `CampaignCompleted` | `campaign.completed` | `campaign_id`, `name`, `recipients_count`, `messages_by_status`, `credits_charged`, `completed_at` |
| `BalanceLow` | `balance.low` | `balance`, `threshold` |
| `TestPing` | `test.ping` | `{}` |
| `UnknownEvent` | a type added after this SDK version | as sent; acknowledge it with a 2xx |

Invalid signature or expired timestamp: `SignatureVerificationError`; signed body that is not an envelope:
`InvalidWebhookPayloadError` — answer `400` to both. Answer `2xx` within 10 seconds; queue slow work.
`webhooks.sign(body, secret)` builds a valid header for your tests. The three integrations below read the secret
from `LABYRINTHE_WEBHOOK_SECRET` by default.

### Django

```python
# urls.py
from labyrinthe_connect.contrib.django import WebhookView
urlpatterns = [path("webhooks/labyrinthe/", WebhookView.as_view())]   # settings.LABYRINTHE_WEBHOOK_SECRET

# receivers.py (imported from AppConfig.ready())
from django.dispatch import receiver
from labyrinthe_connect.contrib.django import message_status_changed

@receiver(message_status_changed)
def on_status(sender, event, **kwargs):
    Order.objects.filter(sms_id=event.data.message_id).update(sms_status=event.data.status)
```

CSRF-exempt, POST only, 400 on a bad signature. Signals: `webhook_received` (all), `message_status_changed`,
`campaign_completed`, `balance_low`, `test_ping`, `unknown_event`. A receiver that raises gives a 500 (retried).

### FastAPI

```python
from fastapi import Depends, FastAPI
from labyrinthe_connect import WebhookEvent
from labyrinthe_connect.contrib.fastapi import WebhookVerifier, create_webhook_router

app = FastAPI()

@app.post("/webhooks/labyrinthe")
async def labyrinthe_webhook(event: WebhookEvent = Depends(WebhookVerifier())):
    ...
    return {"received": True}

# or a ready-made route calling a sync or async handler:
app.include_router(create_webhook_router(on_event=handle_event, path="/hooks/labyrinthe"))
```

The dependency reads the raw body (`await request.body()`) and raises `HTTPException(400)` on a bad signature.
See [`examples/webhook_fastapi.py`](examples/webhook_fastapi.py).

### Flask

```python
from labyrinthe_connect.contrib.flask import create_webhook_blueprint, webhook_received

app.config["LABYRINTHE_WEBHOOK_SECRET"] = "…"
app.register_blueprint(create_webhook_blueprint(handle_event))     # POST /webhooks/labyrinthe
```

Every verified event is also sent through the `webhook_received` blinker signal.

## Errors

Every exception extends `LabyrintheError` and has `code` (stable, for your code), `message`, `status` (HTTP status,
or `None` without a response) and `details`. API errors extend `ApiError` and also expose `headers` and `request_id`.

| Exception | When |
|---|---|
| `AuthenticationError` | 401 — missing, invalid or revoked key |
| `PermissionError` | 403 — IP not allowed (`FORBIDDEN`), OTP disabled for the account (`OTP_DISABLED_FOR_ACCOUNT`) |
| `NotFoundError` | 404 — unknown message or `otp_id` |
| `ValidationError` | 422 `VALIDATION_ERROR` — `field_errors` |
| `RecipientNotSendableError` | 422 — `rejected`, `reason`; nothing was sent |
| `SenderIdNotApprovedError` | 422 — `sender` is not an approved Sender ID |
| `InvalidPhoneError` | 422 `INVALID_PHONE` (OTP) |
| `InsufficientCreditsError` | 409 — `available`, `required`; nothing was sent |
| `IdempotencyKeyReusedError` | 409 — same key, different request |
| `RateLimitError` | 429 `TOO_MANY_REQUESTS` — `retry_after` (seconds) |
| `OtpResendTooSoonError`, `OtpRateLimitedError` | 429 — extend `RateLimitError`; `scope`, `limit` |
| `ServiceUnavailableError` | 5xx, including 503 `OTP_SERVICE_DISABLED` |
| `ApiError` | any other code (the list is open: new codes may appear) |
| `APIConnectionError`, `APITimeoutError` | network error (`CONNECTION_ERROR`) or timeout (`TIMEOUT`) |
| `InvalidArgumentError` | invalid input detected by the SDK, before any call (also a `ValueError`) |
| `UnexpectedResponseError` | a response the SDK cannot read |
| `SignatureVerificationError`, `InvalidWebhookPayloadError` | webhook verification (answer 400) |

`PermissionError` shadows the built-in of the same name: prefer `from labyrinthe_connect import errors` and
`errors.PermissionError`.

```python
from labyrinthe_connect import ApiError, InsufficientCreditsError

try:
    client.sms.send(to="0812345678", body="Hello")
except InsufficientCreditsError as error:
    alert_finance(error.available, error.required)
except ApiError as error:
    logger.error("Labyrinthe Connect error %s (HTTP %s)", error.code, error.status)
```

## Retries and timeouts

The SDK retries automatically (default: 2 retries, exponential backoff 0.5 s → 1 s → 2 s with jitter) **only when a
replay cannot cause a side effect twice**:

| Request | Network error / timeout | 5xx | 429 `TOO_MANY_REQUESTS` |
|---|---|---|---|
| `get`, `list`, `balance.get`, `sender_ids.list`, `estimate` | retried | retried | retried after `Retry-After` |
| `send` / `send_many` (always with an `Idempotency-Key`) | retried, same key | retried, same key | retried after `Retry-After` |
| `otp.send`, `otp.verify` | **never** | **never** | retried (refused before processing) |

Business errors (4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`) are never retried. When the API asks to wait longer
than `max_retry_wait` (default 10 s), the SDK raises `RateLimitError` instead of waiting. Redirects are never
followed. Every method accepts `timeout=` (seconds, per attempt) to override the client's.

## Configuration

```python
client = Labyrinthe(
    api_key=os.environ["LABYRINTHE_API_KEY"],   # default
    base_url="https://api.labyrinthe-rdc.com/api/public/v1",  # default (or LABYRINTHE_BASE_URL); a bare host is completed
    timeout=30.0,                # seconds, per attempt
    max_retries=2,               # 0 disables retries (max 10)
    max_retry_wait=10.0,         # longest Retry-After the SDK waits for
    default_country_code="243",  # added to local numbers: 0812345678 → 243812345678
    default_sender="MYSHOP",     # used when send() gets no sender
    http_client=httpx.Client(proxy="http://proxy:3128"),   # your own client (never closed by the SDK)
)
```

- **Logging**: the `labyrinthe_connect` logger (`logging`) emits debug/info/warning/error records — never the key,
  a code, a body or a full phone number (`24381*****78`). Nothing is printed unless your application configures
  logging.
- `repr(client)` shows `lsk_test_****`; clients refuse to be pickled (they hold the key): create one per process.
- `labyrinthe_connect.phone.normalize("0812345678")` returns `"243812345678"` (or `None`) with the same rules as the
  API; `is_valid()` and `mask()` are also available. International numbers are never blocked.
- `User-Agent: labyrinthe-python/<version> Python/<version>`.

## Development

```bash
python -m venv .venv && . .venv/bin/activate
pip install -r requirements-dev.txt -e .
ruff check . && ruff format --check . && mypy
pytest                         # unit + OpenAPI contract tests (mocked transport, no network)
python -m build && twine check --strict dist/*

# integration tests against a local backend, with a TEST key only
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 pytest -m integration
```

The API contract lives in [`openapi/openapi.yaml`](../openapi/openapi.yaml); the contract tests keep the SDK
aligned with it. Examples: [`examples/`](examples).

## License

MIT — see [LICENSE](LICENSE).

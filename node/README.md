# Labyrinthe Connect — Node.js / TypeScript SDK

[![CI](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/node.yml/badge.svg)](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/node.yml)
[![npm](https://img.shields.io/npm/v/@labyrinthe/connect.svg)](https://www.npmjs.com/package/@labyrinthe/connect)
![Node](https://img.shields.io/badge/node-%E2%89%A5%2018-339933)

Official JavaScript / TypeScript client for [Labyrinthe Connect](https://labyrinthe-rdc.com): send SMS and verify
phone numbers with one-time codes (OTP), in the DR Congo and Central Africa.

*Version française : [README.fr.md](README.fr.md).*

- Fully typed: camelCase objects, `Date`s, string-literal enums, one error class per error.
- Safe by default: automatic retries **only** when they cannot send an SMS twice.
- Zero runtime dependency. ESM + CommonJS. Node.js 18+, Vercel / Cloudflare Workers, Deno, Bun.
- Never logs or prints your API key, OTP codes or full phone numbers.

> **New in 1.2.0** (API specification 1.3.0): `FeatureDisabledError` for 503 `FEATURE_DISABLED` (API or function
> temporarily shut down by the platform, see [Maintenance](#maintenance-503-feature_disabled)), also used for
> `OTP_SERVICE_DISABLED`; retried only after a short `Retry-After`.
>
> **New in 1.1.0** (API specification 1.2.0): idempotent `otp.send()` (retried safely, `replayed`), code shape
> (`codeLength`, `codeType`) and resend delay (`resendAfterSeconds`), `attemptsRemaining` on `otp.verify()`,
> Web OTP (`webOtpDomain`), `stripAccents`, `requestId` on every error and response (and your own `X-Request-Id`),
> `AccountSuspendedError`, `IdempotencyRequestInProgressError` (retried automatically), `otp.verified` /
> `otp.failed` webhooks, foreign numbers without "+" kept as is. See the [CHANGELOG](CHANGELOG.md).

## Installation

```bash
npm install @labyrinthe/connect
```

## Quick start

```ts
import { Labyrinthe } from '@labyrinthe/connect';

const client = new Labyrinthe({ apiKey: 'lsk_test_…' });       // or set LABYRINTHE_API_KEY
const message = await client.sms.send({ to: '0812345678', body: 'Your order 1042 is ready.' });
console.log(message.status);                                     // "queued"
```

Create your keys in the client area (*Developers > API keys*). **Keep them server-side**, never in a browser or a
mobile app (the client refuses to start in a browser).

| Key | Mode | Effect |
|---|---|---|
| `lsk_test_…` | Sandbox | Nothing is sent, no credit is used. Same responses as production. OTP sends return the code (`testCode`). |
| `lsk_live_…` | Production | SMS are really sent and credits are used. |

`client.isTestMode` tells you which one you are using. Every example below works with a test key.

## Sending SMS

```ts
const message = await client.sms.send({
  to: '+243 81 234 5678',      // +243…, 00243…, 243…, 0812…, 812… are all accepted
  body: 'Your order 1042 is ready.',
  sender: 'MYSHOP',            // optional: an approved Sender ID (client.senderIds.list())
  reference: 'order-1042',     // optional: your own reference, max 64 characters
});

message.id;              // "9d5c0a3e-…"
message.recipient;       // "243812345678"
message.status;          // "queued"
message.segments;        // 1
message.creditsCharged;  // 1
message.createdAt;       // Date
```

Sending is asynchronous: `202 Accepted` means *queued and paid for*, not *delivered*. Follow the status with
webhooks (`message.status_changed`, recommended) or by polling:

```ts
import { isFinalStatus } from '@labyrinthe/connect';

const latest = await client.sms.get(message.id);
if (isFinalStatus(latest.status)) {
  console.log(latest.status === 'delivered' ? 'Delivered' : `Failed: ${latest.errorCode}`);
}
```

### Cost before sending

1 credit = 1 SMS segment. A pure ASCII message (GSM-7) holds 160 characters (153 per segment beyond). **Any
non-ASCII character** (é, à, ç, emoji, curly quotes…) switches to UCS-2: 70 characters (67 per segment beyond),
often doubling the cost.

```ts
const estimate = await client.sms.estimate({ body: 'Réunion demain à 9h', recipients: ['0812345678', '0899999999'] });
estimate.encoding;      // "ucs2"
estimate.totalCredits;  // 2
estimate.isAffordable;  // does the balance cover it?

// stripAccents: accents and typographic characters replaced with ASCII → GSM-7, often half the cost
(await client.sms.estimate({ body: 'Réunion demain à 9h', recipients: '0812345678', stripAccents: true })).encoding; // "gsm7"
await client.sms.send({ to: '0812345678', body: 'Réunion demain à 9h', stripAccents: true });
```

`stripAccents` (default `false`) exists on `send()`, `sendMany()`, `estimate()` and `otp.send()`. The
transliteration depends on the server (`Café` may become `Cafe` or `Caf'e`): do not use it for an exact text. The
estimate counts recipients like a send (normalised, duplicates merged, invalid numbers ignored).

### Several recipients: all or nothing

```ts
import { RecipientNotSendableError } from '@labyrinthe/connect';

try {
  const batch = await client.sms.sendMany({ recipients: ['0812345678', '0899999999'], body: 'Meeting tomorrow at 9am.' });
  batch.messages.length;   // one message per distinct number (duplicates are merged)
} catch (error) {
  if (error instanceof RecipientNotSendableError) {
    // NOTHING was sent: fix the list and try again.
    for (const { recipient, reason } of error.rejected) console.log(recipient, reason); // invalid_phone, blacklisted, opted_out
  }
}
```

Up to 1000 recipients per call. Every recipient, the Sender ID and the balance are checked **before** anything is
sent: one refused number (or an insufficient balance) means no SMS at all. Numbers the SDK can already tell are
invalid are refused locally (`InvalidArgumentError`, with their index), before any network call.

### Idempotency (no double sends)

Every send carries an `Idempotency-Key`. When you do not give one, the SDK generates a UUID (`crypto.randomUUID()`)
and reuses it for its own retries, so a network error never produces a second SMS.

To also protect against **your** retries (queue jobs, double clicks, crashes), pass a key derived from your
business event. A replay within 24 hours returns the original messages without sending or billing again:

```ts
const batch = await client.sms.sendMany({ recipients: ['0812345678'], body: 'Your order 1042 is ready.', idempotencyKey: 'order-1042-ready' });
batch.replayed;   // true when this was a replay
```

The same key with a different content (recipients, body, sender or reference) is refused with
`IdempotencyKeyReusedError` (409).

### Listing messages

```ts
const page = await client.sms.list({ status: 'failed', from: '2026-11-01', to: new Date(), perPage: 50 });
page.total; page.data; page.hasMore; await page.nextPage();

for await (const message of client.sms.list({ reference: 'order-1042' })) {
  // every page, fetched lazily
}
```

Filters: `status`, `recipient` (any format), `reference`, `from`, `to` (a date covers the whole day), `page`,
`perPage` (max 100). The list only contains messages sent **with the calling API key**.

### Balance and Sender IDs

```ts
const { balance, held } = await client.balance.get();
const senderIds = await client.senderIds.list();   // approved names, usable as `sender`
```

## OTP: verify a phone number

The code is generated, sent by SMS and checked by Labyrinthe Connect. It is never returned by the API (except
with a test key) and never logged by the SDK.

```ts
// 1. Send the code. Keep otpId SERVER-SIDE (session, cache, database), never in the browser.
const otp = await client.otp.send({ phone: '0812345678' });
req.session.otpId = otp.otpId;
otp.codeLength;          // 6 — show that many boxes
otp.codeType;            // "numeric" | "alphanumeric" (case-insensitive)
otp.resendAfterSeconds;  // 60 — "Resend the code" countdown

// 2. The user types the code received by SMS.

// 3. Verify it.
const result = await client.otp.verify({ otpId: req.session.otpId, code: req.body.code });
if (result.verified) {
  delete req.session.otpId;
  // log the user in / mark the number as verified
} else if (result.reason === 'invalid_code') {
  // wrong code: one attempt consumed, let the user try again
  console.log(`${String(result.attemptsRemaining)} attempt(s) left`);
} else {
  // 'expired' | 'already_used' | 'max_attempts_reached': ask for a new code
}
```

- A wrong code is **not** an error: `verify()` returns `{ verified: false, reason, attemptsRemaining }`
  (`attemptsRemaining`: an integer after `invalid_code`, `0` with `max_attempts_reached`, otherwise `null`).
- A new `send()` to the same number invalidates the previous code. After a successful verification, a new send is
  accepted right away (no anti-resend delay).
- Length, alphabet, validity, attempts, resend delay, Sender ID and message template come from the account's OTP
  settings (client area > *OTP verification*) and are returned by `send()`. You can override the template
  (`template: 'Your MyShop code: {code}'`, must contain `{code}`; optional `{app_name}`, `{minutes}`), the language
  (`locale: 'fr' | 'en'`) and remove accents (`stripAccents: true`).
- Limits: `OtpResendTooSoonError` (wait `retryAfterMs` before resending to this number) and `OtpRateLimitedError`
  (hourly cap per number or per account, `scope`, `limit`, `retryAfterMs`). Both are never retried.
- **Test mode**: with a `lsk_test_` key no SMS is sent and `otp.testCode` holds the code, so you can test the whole
  flow. It is always `null` with a live key, and hidden from `console.log()` and `JSON.stringify()`.

### Idempotent OTP sends

`otp.send()` always carries an `Idempotency-Key` (generated when you give none), so the SDK retries it safely after
a network error, a timeout, a 5xx, a 429 `TOO_MANY_REQUESTS` or a 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`: a replay
returns the same `otpId` (and the same `testCode` in test mode) without a second SMS or charge. Pass your own key
to also protect your own retries (double click, job retry) for 24 hours:

```ts
const otp = await client.otp.send({ phone: '0812345678', idempotencyKey: `login-${loginAttemptId}` });
otp.replayed;   // true: nothing was sent or billed again
```

### Web OTP (automatic code fill on Android)

With `webOtpDomain`, the API ends the SMS with the line `@app.example.com #123456`, the format of the
[Web OTP API](https://developer.mozilla.org/docs/Web/API/WebOTP_API): Chrome on Android then offers to fill the code
in your page served from **exactly that host**, over HTTPS (top-level page).

```ts
await client.otp.send({ phone: '0812345678', webOtpDomain: 'app.example.com' }); // host only: no scheme, port or path
```

```js
// In the browser (the @labyrinthe/otp-widget does it for you)
const { code } = await navigator.credentials.get({ otp: { transport: ['sms'] } });
```

## Webhooks

Configure an endpoint in the client area (*Developers > Webhooks*) and copy its secret. Events are `POST`ed as
JSON and signed (`X-Signature: sha256=<HMAC-SHA256 of the raw body>`). The SDK verifies the signature in constant
time on the **raw body**, then checks the signed timestamp (5 minutes by default) to block replays.

Every event has `id` (deduplicate on it: deliveries are retried up to 5 times and may arrive out of order),
`type`, `timestamp` (`Date`), `raw` and a typed `data`, discriminated on `event`:

| `event` | `data` |
|---|---|
| `message.status_changed` | `messageId`, `recipient`, `status`, `providerMessageId`, `campaignId`, `reference`, `errorCode`, `errorMessage` |
| `campaign.completed` | `campaignId`, `name`, `recipientsCount`, `messagesByStatus`, `creditsCharged`, `completedAt` |
| `balance.low` | `balance`, `threshold` |
| `otp.verified` | `otpId`, `phone`, `status` (`verified`), `reason` (`null`), `verifiedAt` — sent once, never the code |
| `otp.failed` | same fields: `status` `failed` (`reason` `max_attempts_reached`) or `expired` (`reason` `expired`), `verifiedAt` `null` |
| `test.ping` | `{}` |
| `unknown` | a type added after this SDK version: its name is in `type`, its content in `data` (as sent). Acknowledge it with a 2xx. |

### Express

```ts
import express from 'express';
import { labyrintheWebhook } from '@labyrinthe/connect/express';

const app = express();
// BEFORE express.json() (or after express.raw({ type: 'application/json' })): the raw body is needed.
app.post('/webhooks/labyrinthe', labyrintheWebhook({
  secret: process.env.LABYRINTHE_WEBHOOK_SECRET,
  onEvent: async (event) => {
    if (event.event === 'message.status_changed') await updateMessage(event.data.messageId, event.data.status);
  },
}));
app.use(express.json());
```

Invalid signature or payload → `400`. `onEvent` throws → `next(error)` (500, the delivery is retried). Without
`onEvent`, the verified event is set on `req.labyrintheEvent` and `next()` is called.

### Next.js (App Router)

```ts
// app/api/webhooks/labyrinthe/route.ts
import { labyrintheWebhookHandler } from '@labyrinthe/connect/next';

export const POST = labyrintheWebhookHandler({
  secret: process.env.LABYRINTHE_WEBHOOK_SECRET,   // default
  onEvent: async (event) => {
    switch (event.event) {
      case 'balance.low':
        await alertFinance(event.data.balance, event.data.threshold);
        break;
    }
  },
});
```

Works on the Node.js and Edge runtimes, and anywhere a handler takes a Fetch `Request` and returns a `Response`
(Hono, `Bun.serve`, `Deno.serve`…). `onEvent` may return its own `Response`; if it throws, the handler answers 500.

### Cloudflare Workers (or any runtime)

```ts
import { SignatureVerificationError, Webhooks } from '@labyrinthe/connect';

export default {
  async fetch(request: Request, env: { LABYRINTHE_WEBHOOK_SECRET: string }) {
    try {
      const event = await Webhooks.constructEvent(
        await request.arrayBuffer(), request.headers.get('x-signature'), env.LABYRINTHE_WEBHOOK_SECRET,
      );
      // … handle event
      return new Response('ok');
    } catch (error) {
      if (error instanceof SignatureVerificationError) return new Response('invalid', { status: 400 });
      throw error;
    }
  },
};
```

`Webhooks.constructEvent(rawBody, signatureHeader, secret, { toleranceSeconds })` is asynchronous (Web Crypto) and
accepts a string, a `Uint8Array`/`Buffer` or an `ArrayBuffer` — never a parsed object. It throws
`SignatureVerificationError` (bad or missing signature, timestamp out of tolerance) or `InvalidWebhookPayloadError`
(signed body that is not an envelope): answer `400`. `Webhooks.sign(body, secret)` builds a valid header to test
your endpoint. Answer `2xx` within 10 seconds; queue slow work.

## Errors

Every error extends `LabyrintheError` and has `code` (stable, for your code), `message`, `status` (HTTP status, or
`null` without a response) and `details`. API errors extend `ApiError` and also expose `headers` and `requestId`.

| Error | When |
|---|---|
| `AuthenticationError` | 401 — missing, invalid or revoked key |
| `PermissionError` | 403 — IP not allowed (`FORBIDDEN`), OTP disabled for the account (`OTP_DISABLED_FOR_ACCOUNT`) |
| `AccountSuspendedError` | 403 `ACCOUNT_SUSPENDED` — extends `PermissionError`; the whole API is closed, contact the support |
| `NotFoundError` | 404 — unknown message or `otpId` |
| `ValidationError` | 422 `VALIDATION_ERROR` — `fieldErrors` |
| `RecipientNotSendableError` | 422 — `rejected`, `reason`; nothing was sent |
| `SenderIdNotApprovedError` | 422 — `sender` is not an approved Sender ID |
| `InvalidPhoneError` | 422 `INVALID_PHONE` (OTP) |
| `InsufficientCreditsError` | 409 — `available`, `required`; nothing was sent |
| `IdempotencyKeyReusedError` | 409 — same key, different request |
| `IdempotencyRequestInProgressError` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` — already retried; call again with the same key after `retryAfterMs` |
| `IdempotencyKeyUnresolvedError` | 409 `IDEMPOTENCY_KEY_UNRESOLVED` — outcome unknown, an SMS may have been sent; **never** retried (see below) |
| `RateLimitError` | 429 `TOO_MANY_REQUESTS` — `retryAfterMs` |
| `OtpResendTooSoonError`, `OtpRateLimitedError` | 429 — extend `RateLimitError`; `retryAfterMs`, `scope`, `limit` |
| `FeatureDisabledError` | 503 `FEATURE_DISABLED` / `OTP_SERVICE_DISABLED` — extends `ServiceUnavailableError`; `feature`, `blockedBy`, `adminMessage`, `until`, `retryAfter` (see [Maintenance](#maintenance-503-feature_disabled)) |
| `ServiceUnavailableError` | other 5xx |
| `ApiError` | any other code (the list is open: new codes may appear) |
| `ConnectionError`, `TimeoutError` | network error (`CONNECTION_ERROR`) or timeout (`TIMEOUT`) |
| `RequestAbortedError` | the call was cancelled with its `signal` |
| `InvalidArgumentError` | invalid input detected by the SDK, before any call |
| `UnexpectedResponseError` | a response the SDK cannot read |

```ts
import { ApiError, InsufficientCreditsError } from '@labyrinthe/connect';

try {
  await client.sms.send({ to: '0812345678', body: 'Hello' });
} catch (error) {
  if (error instanceof InsufficientCreditsError) alertFinance(error.available, error.required);
  else if (error instanceof ApiError) console.error(error.code, error.status, error.requestId);
  else throw error;
}
```

`error.details` is always an object (`{}` when empty). Messages are in French, for humans: switch on `code`.

### Maintenance (503 `FEATURE_DISABLED`)

A platform administrator can temporarily shut down the whole API (`blockedBy === 'api'`, every call, even before the
key is checked) or one function (`'api.sms_send'`, `'api.otp_send'`, `'api.otp_verify'`). The call throws
`FeatureDisabledError` and **nothing was done** (no SMS, no credit used). The whole OTP service off throws the same
class with the historical code `OTP_SERVICE_DISABLED` (`feature === blockedBy === 'otp'`).

- `feature`: the function requested; `blockedBy`: the one actually off (itself or a parent);
- `adminMessage`: the administrator's message, or `null`; `until`: scheduled re-enable (`Date`), or `null`;
- `retryAfter`: seconds until then (`Retry-After` header), or `null` when unknown.

The SDK never retries it with backoff: only when `Retry-After` is present and no longer than `maxRetryWaitMs`, after
exactly that delay. Otherwise treat it as a temporary failure:

```ts
import { FeatureDisabledError } from '@labyrinthe/connect';

try {
  await client.sms.send({ to, body, idempotencyKey: `order-${orderId}` });
} catch (error) {
  if (error instanceof FeatureDisabledError) {
    // Same idempotency key later: nothing can be sent twice.
    await queue.retryLater({ orderId }, { at: error.until ?? new Date(Date.now() + 15 * 60_000) });
    log.warn('Labyrinthe maintenance', { blockedBy: error.blockedBy, message: error.adminMessage });
  } else throw error;
}
```

### Request id

Every API response carries `X-Request-Id`: `error.requestId` on errors, `requestId` on `sendMany()` batches and
`otp.send()` results, and `onResponse` for any call. Give it to the support. You can also send your own (1 to 128
characters among `A-Z a-z 0-9 . _ : -`, reused on every retry of the call):

```ts
await client.sms.send({ to: '0812345678', body: 'Hello' }, {
  requestId: `order-1042`,
  onResponse: ({ requestId, apiVersion, rateLimit, replayed }) => log.debug({ requestId, rateLimit }),
});
```

## Retries, timeouts and cancellation

The SDK retries automatically (default: 2 retries, exponential backoff 0.5 s → 1 s → 2 s with jitter) **only when a
replay cannot cause a side effect twice**:

| Request | Network error / timeout | 5xx | 429 `TOO_MANY_REQUESTS` |
|---|---|---|---|
| `get`, `list`, `balance.get`, `senderIds.list`, `estimate` | retried | retried | retried after `Retry-After` |
| `send` / `sendMany` / `otp.send` (always with an `Idempotency-Key`) | retried, same key | retried, same key | retried after `Retry-After` |
| `otp.verify` | **never** (an attempt may be consumed) | **never** | retried (refused before processing) |

`503 FEATURE_DISABLED` and `OTP_SERVICE_DISABLED` (refused before processing) are an exception to the 5xx column, for
every call including `otp.verify`: retried only when `Retry-After` is present and no longer than `maxRetryWaitMs`,
after exactly that delay, never with exponential backoff.

`409 IDEMPOTENCY_REQUEST_IN_PROGRESS` (the original request with this key is still running) is retried after
`Retry-After` with the same key, for `send`, `sendMany` and `otp.send`. Other business errors (4xx,
`OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, `ACCOUNT_SUSPENDED`) are never retried. When the API asks to wait longer
than `maxRetryWaitMs` (default 10 s), the SDK throws the error instead of waiting.

`409 IDEMPOTENCY_KEY_UNRESOLVED` (`sms.send`, `sms.sendMany`, `otp.send`) means that the original request with this
key failed unexpectedly on the API side: its outcome is unknown and an SMS may already have been sent. The SDK
**never** retries it and throws `IdempotencyKeyUnresolvedError`. Do not retry automatically either: first check
what happened (`client.sms.list({ reference })` for an SMS sent with a `reference`, or ask the user whether the code
arrived), then, only if nothing was sent, send again with a **new** idempotency key (the same key keeps returning
this error).

```ts
try {
  await client.sms.send({ to, body, reference, idempotencyKey });
} catch (error) {
  if (error instanceof IdempotencyKeyUnresolvedError) {
    const { data } = await client.sms.list({ reference });
    if (data.length === 0) await client.sms.send({ to, body, reference, idempotencyKey: crypto.randomUUID() });
  } else {
    throw error;
  }
}
```

Every method takes a last `options` argument: `{ signal?, timeoutMs?, requestId?, onResponse? }`.

```ts
await client.balance.get({ signal: AbortSignal.timeout(5_000) });
```

## Configuration

```ts
const client = new Labyrinthe({
  apiKey: process.env.LABYRINTHE_API_KEY,   // default
  baseUrl: 'https://api.labyrinthe-rdc.com/api/public/v1', // default (or LABYRINTHE_BASE_URL); "http://localhost:8000" is completed
  timeoutMs: 30_000,              // per attempt
  maxRetries: 2,                  // 0 disables retries
  maxRetryWaitMs: 10_000,         // longest Retry-After the SDK waits for
  defaultCountryCode: '243',      // added to local numbers: 0812345678 → 243812345678
  defaultSender: 'MYSHOP',        // used when send() gets no sender
  fetch: customFetch,             // any WHATWG fetch (defaults to the global one)
  logger: console,                // debug/info/warn/error(message, context): no key, no code, no body, masked numbers
});
```

`PhoneNumber.normalize('0812345678')` returns `"243812345678"` (or `null`) with the same rules as the API;
`PhoneNumber.isValid()` and `PhoneNumber.mask()` are also exported. International numbers (`+33…`, `+1…`) are never
blocked. Without "+", 11 digits or more not starting with 0 are already international (`33612345678` stays
`33612345678`); for a short foreign number (10 digits or less), always use the "+". The SDK sends every
number to the API in E.164 with "+" (`+243812345678`, `+2481234567`), so the country code you typed is kept; API
responses use digits without "+".
 `JSON.stringify(client)` and `console.log(client)` show `lsk_test_****`, never the key.

## Runtime compatibility

| Runtime | Support |
|---|---|
| Node.js 18, 20, 22, 24 | ✅ (global `fetch`; on Node 18, Web Crypto is loaded from the crypto module at first use) |
| Vercel Edge, Next.js Edge routes | ✅ |
| Cloudflare Workers | ✅ (no `nodejs_compat` flag needed) |
| Deno, Bun | ✅ (`npm:@labyrinthe/connect` on Deno) |
| Browsers | ❌ refused by default: the API key must stay server-side |

The core imports no Node.js module and has no dependency; `express` and `next` are optional peer dependencies of
the `/express` and `/next` helpers only. The `User-Agent` is `labyrinthe-node/<version> <runtime>/<version>`.

## Development

```bash
npm ci
npm run lint && npm run typecheck
npm test                 # unit + OpenAPI contract tests (mocked fetch, no network)
npm run build && npm run check:dist && npm run publint && npm run attw

# integration tests against a local backend, with a TEST key only
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 npm run test:integration
```

The API contract lives in [`openapi/openapi.yaml`](../openapi/openapi.yaml); the contract tests keep the SDK aligned
with it. Examples: [`examples/`](examples).

## License

MIT — see [LICENSE](LICENSE).

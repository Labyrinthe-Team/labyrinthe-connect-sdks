# Labyrinthe Connect — Node.js / TypeScript SDK

[![npm](https://img.shields.io/npm/v/@labyrinthe/connect.svg)](https://www.npmjs.com/package/@labyrinthe/connect)
[![CI](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/node.yml/badge.svg)](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/node.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
![Node](https://img.shields.io/badge/node-%E2%89%A5%2018-339933)

Official JavaScript / TypeScript client for [Labyrinthe Connect](https://labyrinthe-rdc.com), the SMS and OTP
platform for the DR Congo and Central Africa: send SMS, follow their delivery and verify phone numbers with one-time
codes (OTP).

*Version française : [README.fr.md](README.fr.md).*

- **Fully typed**: camelCase objects, `Date`s, string-literal enums, one error class per error code.
- **Never a double SMS**: every send carries an `Idempotency-Key`; automatic retries only happen when a replay is safe.
- **Zero runtime dependency**, ESM + CommonJS, Node.js 18+, Vercel / Next.js Edge, Cloudflare Workers, Deno and Bun.
- **Secure by default**: your API key, OTP codes and full phone numbers never reach logs, `console.log()` or
  `JSON.stringify()`; webhooks are verified in constant time on the raw body.
- Webhook helpers for **Express** and **Next.js** (App Router, and any Fetch `Request` / `Response` runtime).

Version **1.2.0**, built against the API specification **1.3.0**
([`openapi.yaml`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/openapi/openapi.yaml)).
What changed: [CHANGELOG](CHANGELOG.md).

## Contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Quick start](#quick-start)
4. [Configuration](#configuration)
5. [Sending SMS](#sending-sms)
6. [Balance and Sender IDs](#balance-and-sender-ids)
7. [OTP: verify a phone number](#otp-verify-a-phone-number)
8. [Webhooks](#webhooks)
9. [Errors](#errors)
10. [Retries and idempotency](#retries-and-idempotency)
11. [Test mode, logging, request ids and types](#test-mode-logging-request-ids-and-types)
12. [Without the package: raw HTTP](#without-the-package-raw-http)
13. [Upgrading](#upgrading)
14. [Contributing](#contributing) · [License](#license)

## Requirements

| Runtime | Support |
|---|---|
| Node.js 18, 20, 22, 24 (`engines: >=18`) | ✅ global `fetch`; on Node 18, Web Crypto is loaded from the crypto module at first use |
| Vercel Edge, Next.js Edge routes | ✅ |
| Cloudflare Workers | ✅ no `nodejs_compat` flag needed |
| Deno, Bun | ✅ (`npm:@labyrinthe/connect` on Deno) |
| Browsers | ❌ refused by default: the API key must stay server-side |

Types are bundled for ESM (`.d.ts`) and CommonJS (`.d.cts`). `express` (≥ 4.17) and `next` (≥ 13.4) are optional
peer dependencies, used only by the `@labyrinthe/connect/express` and `@labyrinthe/connect/next` helpers.

## Installation

```bash
npm install @labyrinthe/connect
# or
pnpm add @labyrinthe/connect
# or
yarn add @labyrinthe/connect
```

Deno: `import { Labyrinthe } from 'npm:@labyrinthe/connect';` — Bun: `bun add @labyrinthe/connect`.

## Quick start

Create a key in the client area (*Developers > API keys*). Start with a **test key** (`lsk_test_…`): nothing is sent
and nothing is billed, but responses have the same shape as in production.

```ts
// ESM / TypeScript
import { Labyrinthe } from '@labyrinthe/connect';

const client = new Labyrinthe({ apiKey: 'lsk_test_…' });   // or set LABYRINTHE_API_KEY and call new Labyrinthe()
const message = await client.sms.send({ to: '0812345678', body: 'Your order 1042 is ready.' });
console.log(message.id, message.status);                    // "9d5c0a3e-…" "queued"
```

```js
// CommonJS
const { Labyrinthe } = require('@labyrinthe/connect');

async function main() {
  const client = new Labyrinthe();                           // reads LABYRINTHE_API_KEY
  const message = await client.sms.send({ to: '0812345678', body: 'Your order 1042 is ready.' });
  console.log(message.status);                               // "queued"
}
main();
```

| Key | Mode | Effect |
|---|---|---|
| `lsk_test_…` | Sandbox | Simulated provider: nothing is sent, no credit is used, the balance is not checked. `otp.send()` returns the code (`testCode`). |
| `lsk_live_…` | Production | SMS are really sent and credits are used. |

**Keep the key server-side**: never in a browser, a mobile app or a `NEXT_PUBLIC_…` variable. The client refuses to
start in a browser (`InvalidArgumentError`) unless you pass `dangerouslyAllowBrowser: true`. To verify phone numbers
from a web page, use [`@labyrinthe/otp-widget`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/tree/main/widget-otp),
which calls *your* server.

Runnable examples: [`examples/send-sms.ts`](examples/send-sms.ts), [`examples/otp-login.ts`](examples/otp-login.ts),
[`examples/webhook-express.ts`](examples/webhook-express.ts), [`examples/webhook-next-route.ts`](examples/webhook-next-route.ts).

## Configuration

```ts
import { Labyrinthe } from '@labyrinthe/connect';

const client = new Labyrinthe({
  apiKey: process.env.LABYRINTHE_API_KEY,                  // default
  baseUrl: 'https://api.labyrinthe-rdc.com/api/public/v1', // default (or LABYRINTHE_BASE_URL)
  timeoutMs: 30_000,
  maxRetries: 2,
  maxRetryWaitMs: 10_000,
  defaultCountryCode: '243',
  defaultSender: 'MYSHOP',
  fetch: globalThis.fetch,
  logger: console,
});
```

| Option | Default | Description |
|---|---|---|
| `apiKey` | `LABYRINTHE_API_KEY` | `lsk_live_…` or `lsk_test_…`. Missing → `InvalidArgumentError`. |
| `baseUrl` | `LABYRINTHE_BASE_URL`, then `https://api.labyrinthe-rdc.com/api/public/v1` | A bare host (`http://localhost:8000`) is completed with `/api/public/v1`. |
| `timeoutMs` | `30000` | Timeout of **each attempt**, in milliseconds. |
| `maxRetries` | `2` | Automatic retries when safe (0 to 10; `0` disables them). |
| `maxRetryWaitMs` | `10000` | Longest `Retry-After` the SDK waits for; beyond, the error is thrown. Also caps the backoff. |
| `defaultCountryCode` | `'243'` (DR Congo) | Added to local numbers: `0812345678` → `243812345678`. 1 to 3 digits, no `+`. |
| `defaultSender` | — | Sender ID used when `sms.send()` / `sendMany()` get no `sender` (otherwise the platform's default). |
| `fetch` | global `fetch` | Any WHATWG-compatible `fetch` (undici, a proxy agent, a mock). |
| `logger` | — | `{ debug?, info?, warn?, error? }(message, context)`: `console`, pino, winston… See [logging](#logging-and-secrets). |
| `dangerouslyAllowBrowser` | `false` | Allows the client in a browser. Your key would be public: don't. |

| Environment variable | Read by |
|---|---|
| `LABYRINTHE_API_KEY` | `new Labyrinthe()` when `apiKey` is not given |
| `LABYRINTHE_BASE_URL` | `new Labyrinthe()` when `baseUrl` is not given (local backend, staging) |
| `LABYRINTHE_WEBHOOK_SECRET` | `labyrintheWebhook()` (Express) and `labyrintheWebhookHandler()` (Next.js) when `secret` is not given |

Read-only properties: `client.isTestMode`, `client.isLiveMode`, `client.baseUrl`. `console.log(client)` and
`JSON.stringify(client)` show `{ apiKey: 'lsk_test_****', baseUrl, testMode }`, never the key.

Every method accepts a last, optional argument of per-call options (`RequestOptions`):

| Option | Description |
|---|---|
| `signal` | `AbortSignal`: cancels the call, including the waits between retries (`RequestAbortedError`). |
| `timeoutMs` | Timeout of each attempt for this call. |
| `requestId` | Your own `X-Request-Id` (1 to 128 characters among `A-Z a-z 0-9 . _ : -`), sent on every retry. |
| `onResponse` | Receives `{ status, requestId, apiVersion, replayed, rateLimit: { limit, remaining }, headers }` of the successful response. |

```ts
await client.balance.get({ signal: AbortSignal.timeout(5_000) });
```

### Phone numbers

The SDK normalises numbers exactly like the API and validates them before any call (`InvalidArgumentError`; the
number is never quoted in the message):

| Input | Normalised |
|---|---|
| `+243 81 234 5678`, `00243812345678`, `243812345678` | `243812345678` |
| `0812345678`, `812345678` (local) | `243812345678` (with `defaultCountryCode`) |
| `+33 6 12 34 56 78`, `33612345678` (no `+`, 11+ digits not starting with 0) | `33612345678` |

Numbers are sent to the API in E.164 with `+`, so a short foreign number keeps its country code; for a foreign number
of 10 digits or less, always type the `+`. Responses return digits without `+`. Helpers:
`PhoneNumber.normalize(raw, countryCode?)` (`string | null`), `PhoneNumber.isValid()`, `PhoneNumber.normalizeOrThrow()`
and `PhoneNumber.mask('243812345678')` → `24381*****78`.

## Sending SMS

### One recipient

```ts
const message = await client.sms.send({
  to: '+243 81 234 5678',      // +243…, 00243…, 243…, 0812…, 812… are all accepted
  body: 'Your order 1042 is ready.',
  sender: 'MYSHOP',            // optional: an approved Sender ID (see client.senderIds.list())
  reference: 'order-1042',     // optional: your own reference (max 64 characters), filterable in sms.list()
  stripAccents: false,         // optional: see "Cost before sending"
  idempotencyKey: 'order-1042-ready', // optional: see "Retries and idempotency"
});

message.id;              // "9d5c0a3e-…"
message.recipient;       // "243812345678"
message.status;          // "queued"
message.encoding;        // "gsm7" | "ucs2"
message.segments;        // 1
message.creditsCharged;  // 1 (fictitious with a test key)
message.reference;       // "order-1042"
message.createdAt;       // Date
```

Limits checked locally: `body` 1 to 1600 characters, `sender` 1 to 11 characters, `reference` ≤ 64 characters. A
`Message` never contains the text (it may hold a code).

Sending is asynchronous: `202 Accepted` means *queued and paid for*, not *delivered*. Follow the status with the
`message.status_changed` webhook (recommended) or by polling `get()`:

```ts
import { isFailedStatus, isFinalStatus } from '@labyrinthe/connect';

const latest = await client.sms.get(message.id);       // NotFoundError if unknown
if (isFinalStatus(latest.status)) {
  console.log(isFailedStatus(latest.status) ? `Failed: ${latest.errorCode}` : 'Delivered');
}
```

Statuses (`MessageStatus`): `queued` → `submitted` → `sent` → `delivered`, or `failed`, `rejected`, `expired`,
`undeliverable` (final). `unknown` means a status added to the API after this SDK version. Helpers:
`isPendingStatus()`, `isFinalStatus()`, `isFailedStatus()`.

### Several recipients: all or nothing

```ts
import { RecipientNotSendableError } from '@labyrinthe/connect';

try {
  const batch = await client.sms.sendMany({
    recipients: ['0812345678', '0899999999', '+33612345678'],
    body: 'Meeting tomorrow at 9am.',
    reference: 'meeting-2026-11-07',
  });
  batch.messages;        // one Message per distinct number (duplicates are merged after normalisation)
  batch.idempotencyKey;  // the key used (yours or generated)
  batch.replayed;        // true if the API replayed an earlier identical send
  batch.requestId;       // X-Request-Id of the response
} catch (error) {
  if (error instanceof RecipientNotSendableError) {
    // NOTHING was sent and nothing was billed: remove these numbers and send again.
    for (const { recipient, reason } of error.rejected) console.log(recipient, reason); // invalid_phone | blacklisted | opted_out
  } else {
    throw error;
  }
}
```

Up to 1000 recipients per call. Every recipient, the Sender ID and the balance are checked **before** anything is
sent: one refused number (or an insufficient balance) means no SMS at all. Numbers the SDK can already tell are
invalid are refused locally (`InvalidArgumentError`, with their index in `recipients`).

### Cost before sending

1 credit = 1 SMS segment. A pure ASCII message (GSM-7) holds 160 characters (153 per segment beyond). **Any non-ASCII
character** (é, à, ç, emoji, curly quotes, long dash…) switches the message to UCS-2: 70 characters (67 per segment
beyond), often doubling the cost.

```ts
const estimate = await client.sms.estimate({
  body: 'Réunion demain à 9h',
  recipients: ['0812345678', '0899999999'],   // a string or an array
});
estimate.encoding;           // "ucs2"
estimate.characters;         // 19
estimate.segmentsPerMessage; // 1
estimate.recipientsCount;    // 2 (normalised, deduplicated, invalid numbers ignored)
estimate.totalCredits;       // 2
estimate.balanceAfter;       // balance after the send (negative if insufficient), null if the account never had credits
estimate.isAffordable;       // balanceAfter !== null && balanceAfter >= 0

// stripAccents: accents and typographic characters replaced with ASCII → GSM-7, often half the cost
const ascii = await client.sms.estimate({ body: 'Réunion demain à 9h', recipients: '0812345678', stripAccents: true });
ascii.encoding;              // "gsm7"
```

`stripAccents` (default `false`) exists on `send()`, `sendMany()`, `estimate()` and `otp.send()`. The transliteration
is done by the server: do not use it when the exact text matters. `estimate()` sends nothing.

### Listing messages

```ts
const page = await client.sms.list({
  status: 'failed',            // MessageStatus
  recipient: '0812345678',     // any format, normalised
  reference: 'order-1042',     // exact match
  from: '2026-11-01',          // inclusive: a date, a date-time or a Date
  to: new Date(),              // inclusive: a date covers the whole day
  page: 1,                     // default 1
  perPage: 50,                 // 1 to 100, default 15
});
page.data;          // Message[]
page.total; page.currentPage; page.lastPage; page.perPage;
page.hasMore;       // currentPage < lastPage
const next = await page.nextPage();   // Page | null

// Every message of every page, fetched lazily:
for await (const message of client.sms.list({ reference: 'order-1042' })) {
  console.log(message.createdAt, message.status);
}
```

`list()` returns a `PagePromise`: `await` it for one page, or iterate it with `for await` (a `Page` is also
async-iterable, from that page on). The list contains only messages sent **with the calling API key**; `get(id)` finds
any message of the account by its `id`.

## Balance and Sender IDs

```ts
const { balance, held } = await client.balance.get();
// balance: available credits; held: credits held by running campaigns

const senderIds = await client.senderIds.list();   // approved names only, usable as `sender`
for (const s of senderIds) console.log(s.value, s.isDefault, s.status); // "MYSHOP" true "approved"
```

`SenderId`: `id`, `value` (≤ 11 characters), `status`, `purpose`, `rejectionReason`, `isDefault`, `createdAt`. Sender
IDs are requested and approved in the client area.

## OTP: verify a phone number

The code is generated, sent by SMS and checked by Labyrinthe Connect. It is stored hashed: the API never returns it in
live mode, and the SDK never logs it.

```ts
import { Labyrinthe } from '@labyrinthe/connect';

const client = new Labyrinthe();

// 1. Send the code. Keep otpId SERVER-SIDE (session, cache, database), never in the browser.
const otp = await client.otp.send({ phone: '0812345678' });
req.session.otpId = otp.otpId;
otp.expiresAt;           // Date
otp.maxAttempts;         // 5
otp.codeLength;          // 6 — show that many boxes (4 to 8, account's OTP settings)
otp.codeType;            // "numeric" | "alphanumeric" (typing is case-insensitive)
otp.resendAfterSeconds;  // 60 — "Resend the code" countdown
otp.testCode;            // the code with a lsk_test_ key, null with a live key

// 2. The user types the code received by SMS.

// 3. Verify it. A wrong code is NOT an exception.
const result = await client.otp.verify({ otpId: req.session.otpId, code: req.body.code });
if (result.verified) {
  delete req.session.otpId;
  // log the user in / mark the number as verified
} else if (result.reason === 'invalid_code') {
  console.log(`${String(result.attemptsRemaining)} attempt(s) left`);   // let the user try again
} else {
  // 'expired' | 'already_used' | 'max_attempts_reached': ask for a new code
}
```

`otp.send()` options:

| Option | Description |
|---|---|
| `phone` | Required. Any accepted format (see [Phone numbers](#phone-numbers)). |
| `template` | Must contain `{code}`; optional `{app_name}`, `{minutes}`. ≤ 1600 characters. Default: the account's OTP settings. |
| `locale` | `'fr'` or `'en'`, language of the standard template. Default: the account's OTP settings. |
| `webOtpDomain` | Host of your site for the Web OTP API (below). Validated locally, lower-cased. |
| `stripAccents` | ASCII-only SMS (GSM-7). Default `false`. |
| `idempotencyKey` | ≤ 255 characters; generated when absent (see [Retries](#retries-and-idempotency)). |

`OtpSendResult` also has `idempotencyKey`, `replayed` (a replay returns the same `otpId`, without a new SMS) and
`requestId`. `testCode` is hidden from `console.log()` and `JSON.stringify()`.

`otp.verify({ otpId, code })` returns `{ verified, reason, attemptsRemaining }` and is **free**:

| `verified` | `reason` | `attemptsRemaining` | What to do |
|---|---|---|---|
| `true` | `null` | `null` | Trust the number. |
| `false` | `invalid_code` | integer | Wrong code, one attempt consumed: let the user try again. |
| `false` | `max_attempts_reached` | `0` | The code is blocked: send a new one. |
| `false` | `expired` | `null` | Send a new code. |
| `false` | `already_used` | `null` | Each code is single-use: send a new one. |

An unknown `otpId` (or one from another account) throws `NotFoundError`. The API ignores spaces and case in the code.

Rules and limits:

- A new `send()` to the same number invalidates the previous code. After a successful verification, a new send is
  accepted right away.
- Length (4 to 8), alphabet, validity, attempts, resend delay, Sender ID and template come from the account's OTP
  settings (client area > *OTP verification*).
- `OtpResendTooSoonError` (429 `OTP_RESEND_TOO_SOON`): a code was sent to this number too recently; show a countdown
  of `retryAfterMs`. `OtpRateLimitedError` (429 `OTP_RATE_LIMITED`): hourly cap reached for this number or the whole
  account (`scope`: `'phone' | 'account'`, `limit`, `retryAfterMs`). Neither is retried by the SDK.
- `otp/send` is limited to 10 requests per minute per API key (on top of the general 120 per minute).
- The outcome of each verification is also sent by the `otp.verified` / `otp.failed` webhooks.

### Web OTP (automatic code fill on Android)

With `webOtpDomain`, the API ends the SMS with the line `@app.example.com #123456`, the format of the
[Web OTP API](https://developer.mozilla.org/docs/Web/API/WebOTP_API): Chrome on Android then offers to fill the code in
your page served from **exactly that host**, over HTTPS (top-level page).

```ts
await client.otp.send({ phone: '0812345678', webOtpDomain: 'app.example.com' }); // host only: no scheme, port or path
```

```js
// In the browser (the @labyrinthe/otp-widget does it for you)
const { code } = await navigator.credentials.get({ otp: { transport: ['sms'] } });
```

## Webhooks

Configure an endpoint in the client area (*Developers > Webhooks*) and copy its secret (there is no API to manage
webhooks). Events are `POST`ed as JSON `{ id, event, data, timestamp }` and signed:
`X-Signature: sha256=<hex HMAC-SHA256(secret, raw body)>`. The SDK checks the signature in constant time on the **raw
body**, before parsing, then the signed `timestamp` (5 minutes by default) to block replays.

Answer `2xx` within 10 seconds (queue slow work). Otherwise the delivery is retried up to 5 times (after 30 s, 2 min,
5 min, 15 min): **deduplicate on `event.id`**, stable across attempts. Order is not guaranteed.

| `event` | `data` |
|---|---|
| `message.status_changed` | `messageId`, `recipient`, `status`, `providerMessageId`, `campaignId`, `reference`, `errorCode`, `errorMessage` |
| `campaign.completed` | `campaignId`, `name`, `recipientsCount`, `messagesByStatus`, `creditsCharged`, `completedAt` |
| `balance.low` | `balance`, `threshold` |
| `otp.verified` | `otpId`, `phone`, `status` (`verified`), `reason` (`null`), `verifiedAt` — never the code |
| `otp.failed` | same fields: `status` `failed` (`reason` `max_attempts_reached`) or `expired` (`reason` `expired`), `verifiedAt` `null` |
| `test.ping` | `{}` — sent by the *Test* button of the client area |
| `unknown` | an event added after this SDK version: its name is in `type`, its content in `data` (as sent). Acknowledge it with a 2xx. |

Every event also has `id`, `type` (the name as sent), `timestamp` (`Date`) and `raw` (the decoded envelope). Switch
on `event.event` to get the typed `data`.

### Express

```ts
import express from 'express';
import { labyrintheWebhook } from '@labyrinthe/connect/express';

const app = express();

// BEFORE express.json() (or with express.raw({ type: 'application/json' }) on this route): the raw body is needed.
app.post('/webhooks/labyrinthe', labyrintheWebhook({
  secret: process.env.LABYRINTHE_WEBHOOK_SECRET,   // default
  toleranceSeconds: 300,                           // default; 0 disables the timestamp check
  onEvent: async (event) => {
    if (!(await markProcessed(event.id))) return;  // your own dedup (e.g. a unique insert): already handled
    switch (event.event) {
      case 'message.status_changed':
        await updateMessage(event.data.messageId, event.data.status);
        break;
      case 'otp.failed':
        await flagPhone(event.data.phone, event.data.reason);
        break;
    }
  },
}));

app.use(express.json());
```

Invalid signature or payload → `400` (`{ error: { code, message } }`). `onEvent` resolves → `200 { received: true }`
(unless you already answered). `onEvent` throws → `next(error)` (500, the delivery is retried). Without `onEvent`, the
verified event is set on `req.labyrintheEvent` and `next()` is called. Works with Connect and plain `node:http` too.

### Next.js (App Router)

```ts
// app/api/webhooks/labyrinthe/route.ts
import { labyrintheWebhookHandler } from '@labyrinthe/connect/next';

export const POST = labyrintheWebhookHandler({
  secret: process.env.LABYRINTHE_WEBHOOK_SECRET,   // default
  onEvent: async (event) => {
    if (event.event === 'balance.low') {
      await alertFinance(event.data.balance, event.data.threshold);
    }
    // return nothing → 200 { received: true }; or return your own Response
  },
});
```

Works on the Node.js and Edge runtimes, and anywhere a handler takes a Fetch `Request` and returns a `Response`
(Hono, `Bun.serve`, `Deno.serve`…). Invalid signature → `400`; no secret configured → `500`
`WEBHOOK_SECRET_MISSING`; `onEvent` throws → `500` `WEBHOOK_HANDLER_FAILED` (logged with the event id; the delivery
is retried).

### Any runtime (Cloudflare Workers, Deno, Bun…)

```ts
import { InvalidWebhookPayloadError, SignatureVerificationError, Webhooks } from '@labyrinthe/connect';

export default {
  async fetch(request: Request, env: { LABYRINTHE_WEBHOOK_SECRET: string }): Promise<Response> {
    try {
      const event = await Webhooks.constructEvent(
        await request.arrayBuffer(),               // RAW body: string, Uint8Array/Buffer or ArrayBuffer
        request.headers.get('x-signature'),
        env.LABYRINTHE_WEBHOOK_SECRET,
        { toleranceSeconds: 300 },
      );
      // … handle event (deduplicate on event.id)
      return new Response('ok');
    } catch (error) {
      if (error instanceof SignatureVerificationError || error instanceof InvalidWebhookPayloadError) {
        return new Response('invalid', { status: 400 });
      }
      throw error;
    }
  },
};
```

`Webhooks.constructEvent()` is asynchronous (Web Crypto) and never accepts a parsed object. It throws
`SignatureVerificationError` (missing or wrong signature, timestamp out of tolerance) or `InvalidWebhookPayloadError`
(signed body that is not an envelope): answer `400`. `Webhooks.verifySignature(body, header, secret)` returns a
boolean; `Webhooks.sign(body, secret)` builds a valid `X-Signature` value to test your endpoint.
`Webhooks.SIGNATURE_HEADER` is `'X-Signature'`.

## Errors

Every error extends `LabyrintheError` and has `code` (stable: switch on it), `message`, `status` (HTTP status, or
`null` without a response) and `details` (always an object). API errors extend `ApiError` and also expose `headers`,
`requestId` and `apiVersion`. API messages are in French, for humans; the list of codes is open, so always keep a
generic branch.

| Class | HTTP | `code` | What to do |
|---|---|---|---|
| `AuthenticationError` | 401 | `UNAUTHENTICATED` | Missing, invalid or revoked key: check `LABYRINTHE_API_KEY`. |
| `PermissionError` | 403 | `FORBIDDEN`, `OTP_DISABLED_FOR_ACCOUNT` | IP not allowed for this key; OTP disabled for the account. |
| `AccountSuspendedError` | 403 | `ACCOUNT_SUSPENDED` | Whole API closed: contact the support with `requestId`. Extends `PermissionError`. |
| `NotFoundError` | 404 | `NOT_FOUND` | Unknown message id or `otpId` (or one of another account). |
| `ValidationError` | 422 | `VALIDATION_ERROR` | Fix the fields in `fieldErrors` (`{ body: ['…'] }`). |
| `RecipientNotSendableError` | 422 | `RECIPIENT_NOT_SENDABLE` | Nothing was sent: remove `rejected` (`reason`: `invalid_phone`, `blacklisted`, `opted_out`). |
| `SenderIdNotApprovedError` | 422 | `SENDER_ID_NOT_APPROVED` | Use a name from `senderIds.list()`. |
| `InvalidPhoneError` | 422 | `INVALID_PHONE` | OTP number unusable: ask the user for another one. |
| `ApiError` | 422 | `INVALID_OTP_TEMPLATE` | Your `template` is invalid (must contain `{code}`). |
| `InsufficientCreditsError` | 409 | `INSUFFICIENT_CREDITS` | Nothing was sent: buy credits (`available`, `required`). Never in test mode. |
| `IdempotencyKeyReusedError` | 409 | `IDEMPOTENCY_KEY_REUSED` | Same key, different content: use a new key for a new message. |
| `IdempotencyRequestInProgressError` | 409 | `IDEMPOTENCY_REQUEST_IN_PROGRESS` | Already retried by the SDK; wait `retryAfterMs`, call again with the **same** key. |
| `IdempotencyKeyUnresolvedError` | 409 | `IDEMPOTENCY_KEY_UNRESOLVED` | Outcome unknown, an SMS may have been sent: check, then use a **new** key ([below](#retries-and-idempotency)). |
| `RateLimitError` | 429 | `TOO_MANY_REQUESTS` | Already retried when safe; wait `retryAfterMs`. |
| `OtpResendTooSoonError` | 429 | `OTP_RESEND_TOO_SOON` | Show a countdown of `retryAfterMs`. Extends `RateLimitError`. |
| `OtpRateLimitedError` | 429 | `OTP_RATE_LIMITED` | Hourly cap (`scope`, `limit`, `retryAfterMs`). Extends `RateLimitError`. |
| `FeatureDisabledError` | 503 | `FEATURE_DISABLED`, `OTP_SERVICE_DISABLED` | Maintenance: nothing was done ([below](#maintenance-503-feature_disabled)). Extends `ServiceUnavailableError`. |
| `ServiceUnavailableError` | 5xx | any | Already retried when safe; try again later. |
| `ApiError` | other | `HTTP_ERROR` (405), unknown codes | Log `code`, `status`, `requestId`. |
| `ConnectionError` | — | `CONNECTION_ERROR` | API unreachable (DNS, TLS…). Sends were already retried; calling again with the same `idempotencyKey` is safe. |
| `TimeoutError` | — | `TIMEOUT` | An attempt exceeded `timeoutMs`. Extends `ConnectionError`. |
| `RequestAbortedError` | — | `REQUEST_ABORTED` | Cancelled through `signal`. Never retried. |
| `InvalidArgumentError` | — | `INVALID_ARGUMENT` | Invalid input detected before any call (number, length, option). |
| `UnexpectedResponseError` | 2xx | `UNEXPECTED_RESPONSE` | A response the SDK cannot read (proxy page…). |
| `SignatureVerificationError` | — | `SIGNATURE_VERIFICATION_FAILED` | Webhook signature or timestamp invalid: answer 400. |
| `InvalidWebhookPayloadError` | — | `INVALID_WEBHOOK_PAYLOAD` | Signed webhook body that is not an envelope: answer 400. |

```ts
import { ApiError, InsufficientCreditsError, LabyrintheError, ValidationError } from '@labyrinthe/connect';

try {
  await client.sms.send({ to: '0812345678', body: 'Hello' });
} catch (error) {
  if (error instanceof InsufficientCreditsError) {
    await alertFinance(error.available, error.required);
  } else if (error instanceof ValidationError) {
    console.error(error.fieldErrors);
  } else if (error instanceof ApiError) {
    console.error(error.code, error.status, error.requestId);   // give requestId to the support
  } else if (error instanceof LabyrintheError) {
    console.error(error.code);                                  // CONNECTION_ERROR, TIMEOUT, INVALID_ARGUMENT…
  } else {
    throw error;
  }
}
```

### Maintenance (503 `FEATURE_DISABLED`)

A platform administrator can temporarily shut down the whole API (`blockedBy === 'api'`: every call, refused even
before the key is checked) or one function (`'api.sms_send'`, `'api.otp_send'`, `'api.otp_verify'`). The call throws
`FeatureDisabledError` and **nothing was done**: no SMS, no credit used. The whole OTP service off throws the same
class with the historical code `OTP_SERVICE_DISABLED` (`feature === blockedBy === 'otp'`; verifying codes already sent
keeps working).

| Property | Type | Description |
|---|---|---|
| `feature` | `string \| null` | Function requested by the call (`api`, `api.sms_send`, `api.otp_send`, `api.otp_verify`, `otp`… open list). |
| `blockedBy` | `string \| null` | Function actually shut down: `feature` itself or a parent (`api`). |
| `adminMessage` | `string \| null` | The administrator's message (`details.message`); `error.message` repeats it or is generic. |
| `until` | `Date \| null` | Scheduled automatic re-enable; `null` when unknown. |
| `retryAfter` / `retryAfterMs` | `number \| null` | Time left until then (`Retry-After` header), in seconds / milliseconds. |

The SDK never retries it with backoff: only when `Retry-After` is present and no longer than `maxRetryWaitMs`, after
exactly that delay (for every call, `otp.verify()` included, since the request was refused before processing).
Otherwise treat it as a temporary failure:

```ts
import { FeatureDisabledError } from '@labyrinthe/connect';

try {
  await client.sms.send({ to, body, idempotencyKey: `order-${orderId}-ready` });
} catch (error) {
  if (error instanceof FeatureDisabledError) {
    // Same idempotency key later: nothing can be sent twice.
    await queue.retryLater({ orderId }, { at: error.until ?? new Date(Date.now() + 15 * 60_000) });
    logger.warn('Labyrinthe maintenance', {
      feature: error.feature, blockedBy: error.blockedBy, message: error.adminMessage, requestId: error.requestId,
    });
  } else {
    throw error;
  }
}
```

## Retries and idempotency

The SDK retries automatically (default 2 retries, exponential backoff 0.5 s → 1 s → 2 s… with jitter, capped by
`maxRetryWaitMs`) **only when a replay cannot cause a side effect twice**:

| Call | Network error / timeout | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` |
|---|---|---|---|---|
| `sms.get`, `sms.list`, `sms.estimate`, `balance.get`, `senderIds.list` | retried | retried | after `Retry-After` | — |
| `sms.send`, `sms.sendMany`, `otp.send` (always with an `Idempotency-Key`) | retried, same key | retried, same key | after `Retry-After` | after `Retry-After`, same key |
| `otp.verify` | **never** (an attempt may be consumed) | **never** | after `Retry-After` | — |

- `503 FEATURE_DISABLED` / `OTP_SERVICE_DISABLED`: for every call, only after a `Retry-After` ≤ `maxRetryWaitMs`, never
  with backoff (see [Maintenance](#maintenance-503-feature_disabled)).
- Never retried: other 4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, `ACCOUNT_SUSPENDED`,
  `IDEMPOTENCY_KEY_UNRESOLVED`. When the API asks to wait longer than `maxRetryWaitMs`, the error is thrown at once.

**Idempotency.** Every SMS and OTP send carries an `Idempotency-Key`. Without `idempotencyKey`, the SDK generates a
UUID (`crypto.randomUUID()`) once per call and reuses it for its own retries, so a network error never produces a
second SMS. To also protect **your** retries (queue jobs, double clicks, crashes), pass a key derived from your business
event. A replay within 24 hours returns the original response without sending or billing again (`replayed: true`):

```ts
const batch = await client.sms.sendMany({
  recipients: ['0812345678'], body: 'Your order 1042 is ready.', idempotencyKey: 'order-1042-ready',
});
batch.replayed;   // true when this was a replay

const otp = await client.otp.send({ phone: '0812345678', idempotencyKey: `login-${loginAttemptId}` });
otp.replayed;     // true: same otpId (and same testCode in test mode), no new SMS
```

Keys are scoped to your API key and to the operation (`/sms` and `/otp/send` have separate key spaces), at most 255
characters, and bound to the **normalised** content: the same key with other content throws
`IdempotencyKeyReusedError`. A request that ended in error does not record the key.

**Outcome unknown.** `IdempotencyKeyUnresolvedError` (409 `IDEMPOTENCY_KEY_UNRESOLVED`) means the original request with
this key failed unexpectedly on the API side: an SMS may already have been sent. The SDK never retries it; do not retry
automatically either. Check first, then send again with a **new** key (the same key keeps returning this error):

```ts
import { IdempotencyKeyUnresolvedError } from '@labyrinthe/connect';

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

For an OTP, ask the user whether the code arrived before sending a new one.

**Rate limits.** 120 requests per minute per API key, 10 per minute per key on `otp/send`, 120 per minute per IP.
`onResponse` exposes `rateLimit: { limit, remaining }` (`X-RateLimit-*` headers).

## Test mode, logging, request ids and types

### Test mode

With a `lsk_test_…` key the provider is simulated: nothing is sent, no credit is used, statuses and responses have
the production shape. Two differences: the balance is not checked (`INSUFFICIENT_CREDITS` never happens in test mode)
and `otp.send()` returns the code in `testCode`, so the whole OTP flow can be tested. `client.isTestMode` tells you
which mode you are in. Use test keys in development, CI and examples.

### Logging and secrets

Pass `logger: console` (or pino, winston…) to receive `debug` (each request and response: method, path, status,
duration, `replayed`, `requestId`), `warn` (retries) and `info` / `error` (API and network errors) entries. Logs never
contain the API key, OTP codes, request or response bodies, nor full phone numbers (`recipient` / `phone` query
parameters are masked). Network error messages never quote the URL query. `console.log()` / `JSON.stringify()` of the
client, its resources and `OtpSendResult` redact the key and `testCode`.

### Request ids

Every API response carries `X-Request-Id`: `error.requestId` on errors, `requestId` on `sendMany()` batches and
`otp.send()` results, and `onResponse` for any call. Give it to the support. You can send your own (reused on every
retry of the call):

```ts
await client.sms.send({ to: '0812345678', body: 'Hello' }, {
  requestId: 'order-1042',
  onResponse: ({ requestId, apiVersion, rateLimit, replayed }) => logger.debug({ requestId, apiVersion, rateLimit, replayed }),
});
```

The `User-Agent` is `labyrinthe-node/<version> <runtime>/<version>`; `VERSION` is exported.

### TypeScript types

Everything is exported from `@labyrinthe/connect`:

- Objects: `Message`, `MessageBatch`, `Estimate`, `Balance`, `SenderId`, `RejectedRecipient`, `OtpSendResult`,
  `OtpVerifyResult` (discriminated on `verified`), `Page`, `PagePromise`, `ResponseInfo`, `RequestOptions`,
  `LabyrintheOptions`.
- Parameters: `SendSmsParams`, `SendManySmsParams`, `EstimateSmsParams`, `ListSmsParams`, `SendOtpParams`,
  `VerifyOtpParams`, `OtpLocale`.
- Enums (constant object + union type of the same name): `MessageStatus`, `Encoding`, `SenderIdStatus`,
  `RejectionReason`, `OtpFailureReason`, `OtpCodeType`, `OtpVerificationStatus`, `OtpRateLimitScope`,
  `WebhookEventType`. Each one except `Encoding` and `WebhookEventType` has an `unknown` member for values added to
  the API later.
- Webhooks: `WebhookEvent` (union), `MessageStatusChangedEvent`, `CampaignCompletedEvent`, `BalanceLowEvent`,
  `OtpVerifiedEvent`, `OtpFailedEvent`, `OtpWebhookData`, `TestPingEvent`, `UnknownEvent`, `RawBody`,
  `ConstructEventOptions`.
- Errors: every class above, `ApiErrorCode`, `SdkErrorCode`, `ErrorCode`, `ErrorDetails`.
- Transport: `FetchLike`, `FetchInit`, `FetchResponse`, `Logger`.

```ts
import { MessageStatus, type Message } from '@labyrinthe/connect';

const failed = (m: Message) => m.status === MessageStatus.Failed;
```

## Without the package: raw HTTP

The API is plain JSON over HTTPS; the SDK only adds validation, retries, typing and webhook helpers. With `fetch`
(Node 18+):

```ts
const API = 'https://api.labyrinthe-rdc.com/api/public/v1';
const headers = {
  Authorization: `Bearer ${process.env.LABYRINTHE_API_KEY}`,   // lsk_test_… while testing
  'Content-Type': 'application/json',
  Accept: 'application/json',
};

// Send an SMS (202). Idempotency-Key: replay safely with the SAME key for 24 h.
const res = await fetch(`${API}/sms`, {
  method: 'POST',
  headers: { ...headers, 'Idempotency-Key': crypto.randomUUID() },
  body: JSON.stringify({ recipient: '+243812345678', body: 'Your order 1042 is ready.', reference: 'order-1042' }),
});
const json = await res.json();
if (!res.ok) {
  // { error: { code, message, details } }
  throw new Error(`${json.error.code} (HTTP ${res.status}, request ${res.headers.get('x-request-id')})`);
}
const [message] = json.data;   // [{ id, recipient, status: "queued", segments, credits_charged, … }]

// OTP: send (201), then verify (200)
const sent = await (await fetch(`${API}/otp/send`, {
  method: 'POST', headers: { ...headers, 'Idempotency-Key': crypto.randomUUID() },
  body: JSON.stringify({ phone: '+243812345678', locale: 'fr' }),
})).json();                    // { data: { otp_id, expires_at, max_attempts, code_length, code_type, resend_after_seconds, test_code? } }

const checked = await (await fetch(`${API}/otp/verify`, {
  method: 'POST', headers, body: JSON.stringify({ otp_id: sent.data.otp_id, code: '123456' }),
})).json();                    // { data: { verified, reason, attempts_remaining } }
```

Webhook signature with `node:crypto`, on the raw body:

```ts
import { createHmac, timingSafeEqual } from 'node:crypto';

function verifyWebhook(rawBody: Buffer, signatureHeader: string | undefined, secret: string): boolean {
  const expected = Buffer.from('sha256=' + createHmac('sha256', secret).update(rawBody).digest('hex'));
  const given = Buffer.from(signatureHeader ?? '');
  if (given.length !== expected.length || !timingSafeEqual(given, expected)) return false;
  const { timestamp } = JSON.parse(rawBody.toString('utf8'));
  return Math.abs(Date.now() / 1000 - timestamp) <= 300;   // replay protection
}
```

Reference: the OpenAPI 3.1 specification
[`openapi/openapi.yaml`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/openapi/openapi.yaml)
(also served by the API at `https://api.labyrinthe-rdc.com/api/public/openapi.yaml`) and the
[Postman collection](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/tree/main/postman).

## Upgrading

- **1.1.x → 1.2.0** (API 1.3.0): no code change. New `FeatureDisabledError` for 503 `FEATURE_DISABLED`; 503
  `OTP_SERVICE_DISABLED` is now thrown as `FeatureDisabledError` too. Both remain `instanceof ServiceUnavailableError`,
  so existing `catch` branches keep working. They are no longer retried with backoff, only after a short `Retry-After`.
- **1.1.0 → 1.1.1**: numbers are sent to the API in E.164 with `+` (short foreign numbers keep their country code).
  `IdempotencyKeyUnresolvedError` added. No code change.
- **1.0.x → 1.1.0** (API 1.2.0): `OtpVerifyResult` gains `attemptsRemaining` (update deep-equality checks on the whole
  result); `otp.send()` is idempotent and retried safely; numbers without `+` of 11 digits or more not starting with 0
  are kept as international (`33612345678`).

Details: [CHANGELOG](CHANGELOG.md). Within v1, the API only adds compatible changes (fields, codes, events): the SDK
ignores what it does not know.

## Contributing

Issues and pull requests go to the monorepo
[Labyrinthe-Team/labyrinthe-connect-sdks](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks)
(see [CONTRIBUTING.md](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/CONTRIBUTING.md)). In
`node/`:

```bash
npm ci
npm run check            # lint + typecheck + tests (mocked fetch, OpenAPI contract) + build + check:dist + publint + attw

# integration tests against a local backend, with a TEST key only
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 npm run test:integration
```

Security issues: email connect@labyrinthe-rdc.com rather than opening a public issue.

## License

MIT — see [LICENSE](LICENSE).

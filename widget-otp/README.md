# @labyrinthe/otp-widget

[![npm](https://img.shields.io/npm/v/@labyrinthe/otp-widget.svg)](https://www.npmjs.com/package/@labyrinthe/otp-widget)
[![CI](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/widget-otp.yml/badge.svg)](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/widget-otp.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
![min+gzip](https://img.shields.io/badge/min%2Bgzip-8.1%20kB-008374)

Drop-in phone verification for [Labyrinthe Connect](https://connect.labyrinthe-rdc.com), the SMS and OTP platform for the
DR Congo and Central Africa: a `<labyrinthe-otp>` web component, a React wrapper, and the two server endpoints it
needs (Express, Next.js and any Fetch API runtime).

*Version française : [README.fr.md](README.fr.md).*

- **No API key in the browser.** The widget only calls *your* server; your server calls Labyrinthe with
  [`@labyrinthe/connect`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/tree/main/node). `otp_id` and the
  code never reach the browser.
- Phone step (DR Congo `+243` by default, same normalisation as the SDK), separate code boxes, paste,
  `autocomplete="one-time-code"`, Web OTP API on Android, auto-submit, resend countdown, attempts left, every error
  explained.
- Accessible (labels, `role="alert"`, live region, keyboard, focus management), responsive, light / dark, themable
  with CSS custom properties and `::part()`, French (default) and English, every text overridable.
- Zero dependencies, Shadow DOM, **8.1 kB min+gzip** (standalone script), SSR-safe.

Version **1.2.1**, built against the API specification **1.3.0** and `@labyrinthe/connect` 1.2. What changed:
[CHANGELOG](CHANGELOG.md).

## Contents

1. [How it works](#how-it-works)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Quick start](#quick-start)
5. [Server handlers](#server-handlers) (Express, Next.js, Hono, Bun, Deno, other languages)
6. [Server options](#server-options)
7. [The element](#the-element) (script tag, bundler, attributes, properties, events)
8. [React](#react)
9. [Styling and theming](#styling-and-theming)
10. [Texts and languages](#texts-and-languages)
11. [Accessibility](#accessibility)
12. [Errors](#errors) and [maintenance](#maintenance-503-service_unavailable)
13. [Security](#security)
14. [Test mode](#test-mode) · [Size](#size)
15. [Upgrading](#upgrading) · [Contributing](#contributing) · [License](#license)

## How it works

A Labyrinthe API key (`lsk_live_…`) gives full access to your account: whoever holds it can send SMS on your credits.
It must stay on your server; `@labyrinthe/connect` even refuses to run in a browser. So the widget never talks to
Labyrinthe: it talks to two endpoints on **your** server, which this package also provides.

```
 Browser                          Your server                               Labyrinthe API
 <labyrinthe-otp>                 @labyrinthe/otp-widget/server             (lsk_live_… / lsk_test_…)
      │ POST /api/otp/send              │                                         │
      │  { phone, locale, send_id }     │                                         │
      │────────────────────────────────▶│ beforeSend() (your anti-abuse checks)   │
      │                                 │ client.otp.send({ phone, … }) ─────────▶│ SMS
      │                                 │◀──────────── { otp_id, expires_at, … }  │
      │◀── { sent, phone_masked, … } ───│ Set-Cookie: encrypted { otp_id, phone } │
      │    (no otp_id, no code)         │   (HttpOnly, SameSite=Strict, Secure)   │
      │ POST /api/otp/verify { code }   │                                         │
      │────────────────────────────────▶│ decrypt cookie → otp_id                 │
      │                                 │ client.otp.verify({ otpId, code }) ────▶│
      │                                 │◀───── { verified, reason, attempts… }   │
      │                                 │ onVerified({ phone }) → your session    │
      │◀── { verified: true, data } ────│ cookie cleared                          │
```

The browser only ever sees a masked number, the expiry, the attempts left and the resend delay.

## Requirements

| Part | Needs |
|---|---|
| `<labyrinthe-otp>` | A browser with Custom Elements and Shadow DOM: Chrome / Edge 84+, Firefox 90+, Safari 15+ |
| `@labyrinthe/otp-widget/react` | React ≥ 18 (18 and 19, Next.js App Router) |
| `@labyrinthe/otp-widget/server` | Node.js ≥ 18 (`engines`), or any runtime with Fetch and Web Crypto (edge, Deno, Bun), and `@labyrinthe/connect` ^1.2.1 |

`@labyrinthe/connect` and `react` are optional peer dependencies: install them only where you use the server helper
or the React wrapper.

## Installation

```bash
npm install @labyrinthe/otp-widget @labyrinthe/connect
# or
pnpm add @labyrinthe/otp-widget @labyrinthe/connect
# or
yarn add @labyrinthe/otp-widget @labyrinthe/connect
```

Without a bundler, load the standalone script from a CDN (pin the version):

```html
<script src="https://cdn.jsdelivr.net/npm/@labyrinthe/otp-widget@1.2.1/dist/labyrinthe-otp.min.js" defer></script>
<!-- or https://unpkg.com/@labyrinthe/otp-widget@1.2.1/dist/labyrinthe-otp.min.js -->
```

To self-host it, the file is exported as `@labyrinthe/otp-widget/cdn` (see
[`examples/express/server.ts`](examples/express/server.ts)).

## Quick start

**1. Server.** Two endpoints that keep the key and `otp_id` (Express here; other frameworks
[below](#server-handlers)):

```ts
import express from 'express';
import { Labyrinthe } from '@labyrinthe/connect';
import { createOtpHandlers } from '@labyrinthe/otp-widget/server';

const otp = createOtpHandlers({
  client: new Labyrinthe(),                        // LABYRINTHE_API_KEY=lsk_test_… (sends nothing, bills nothing)
  secret: process.env.LABYRINTHE_OTP_SECRET,       // ≥ 32 random characters: openssl rand -hex 32
  onVerified: async ({ phone, request }) => {
    // The ONLY place where the number is trusted: log the user in here.
    return { redirect: '/dashboard' };             // sent to the browser as `data`
  },
});

const app = express();
app.use(express.json());
app.post('/api/otp/send', otp.express.send);
app.post('/api/otp/verify', otp.express.verify);
app.listen(3000);
```

**2. Browser.**

```html
<script src="https://cdn.jsdelivr.net/npm/@labyrinthe/otp-widget@1.2.1/dist/labyrinthe-otp.min.js" defer></script>

<labyrinthe-otp send-url="/api/otp/send" verify-url="/api/otp/verify"></labyrinthe-otp>
<script>
  document.querySelector('labyrinthe-otp')
    .addEventListener('labyrinthe-otp:verified', (e) => location.assign(e.detail.data.redirect));
</script>
```

Complete examples: [`examples/express`](examples/express) (static page + Express server) and
[`examples/nextjs`](examples/nextjs) (route handlers + client component).

## Server handlers

`createOtpHandlers(options)` returns:

| Member | Type | For |
|---|---|---|
| `send`, `verify` | `(request: Request) => Promise<Response>` | Next.js App Router, Hono, Bun, Deno, edge runtimes |
| `express.send`, `express.verify` | `(req, res, next) => Promise<void>` | Express, Connect, plain `node:http` (with or without `express.json()`) |

Create the handlers **once** and share them between the two routes.

### Express

```ts
app.use(express.json({ limit: '4kb' }));          // optional: the handlers also read the raw stream
app.post('/api/otp/send', otp.express.send);
app.post('/api/otp/verify', otp.express.verify);
```

### Next.js (App Router)

```ts
// lib/otp.ts — server only
import { Labyrinthe } from '@labyrinthe/connect';
import { createOtpHandlers } from '@labyrinthe/otp-widget/server';

export const otp = createOtpHandlers({
  client: () => new Labyrinthe(),                  // created on first use, not at build time
  secret: process.env.LABYRINTHE_OTP_SECRET,
  cookie: { path: '/api/otp' },                    // the cookie only goes to the two OTP routes
  onVerified: async ({ phone, setCookie }) => {
    const token = await createAppSession(phone);   // your auth system
    setCookie(`app_session=${token}; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=2592000`);
    return { redirect: '/dashboard' };
  },
});
```

```ts
// app/api/otp/send/route.ts
import { otp } from '../../../../lib/otp';
export const POST = otp.send;

// app/api/otp/verify/route.ts
import { otp } from '../../../../lib/otp';
export const POST = otp.verify;
```

Keep `LABYRINTHE_API_KEY` and `LABYRINTHE_OTP_SECRET` in `.env.local`, never in a `NEXT_PUBLIC_…` variable. The
handlers run on the Node.js runtime and on the Edge runtime.

### Hono

```ts
import { Hono } from 'hono';

const app = new Hono();
app.post('/api/otp/send', (c) => otp.send(c.req.raw));
app.post('/api/otp/verify', (c) => otp.verify(c.req.raw));
```

### Bun and Deno

```ts
const handle = (request: Request): Promise<Response> | Response => {
  const { pathname } = new URL(request.url);
  if (pathname === '/api/otp/send') return otp.send(request);
  if (pathname === '/api/otp/verify') return otp.verify(request);
  return new Response('Not found', { status: 404 });
};

Bun.serve({ port: 3000, fetch: handle });          // Bun
// Deno.serve({ port: 3000 }, handle);             // Deno
```

### Another backend language

The widget only needs two JSON endpoints, so any backend can serve it (for example with the PHP, Python, .NET or Java
SDK). The protocol (`ErrorBody`, `SendSuccessBody`, `VerifySuccessBody`, `VerifyFailureBody` types are exported):

| Request (from the widget) | Success response (200) |
|---|---|
| `POST {send-url}` `{ "phone": "+243812345678", "locale": "fr", "send_id": "<32 hex>" }` | `{ "sent": true, "phone_masked": "+243 81•••••78", "expires_at": "…", "max_attempts": 5, "resend_after_seconds": 60, "code_length"?: 6, "code_type"?: "numeric" }` |
| `POST {verify-url}` `{ "code": "482913" }` | `{ "verified": true, "data"?: … }` or `{ "verified": false, "reason": "invalid_code", "attempts_left": 2 }` |

Errors: any status with `{ "error": { "code", "message", "retry_after_seconds"?, "admin_message"?, "until"? } }` and
the codes of [Errors](#errors). Requests are sent with `Content-Type: application/json`, the cookies of your origin
(`credentials`) and your `requestHeaders`. On your side: keep `otp_id` in the server session, derive the
`Idempotency-Key` of `otp/send` from `send_id` (the helper uses an HMAC with its secret, so the browser never chooses
the key), and trust the number only after `verified: true`.

## Server options

| Option | Default | Description |
|---|---|---|
| `client` | — | **Required.** A `Labyrinthe` instance, or a function returning one (called on first use). |
| `secret` | `LABYRINTHE_OTP_SECRET` | **Required**, ≥ 32 characters. Encrypts the cookie and derives idempotency keys. Not your API key. |
| `onVerified(ctx)` | — | **Required.** Called once the code is verified: `ctx.phone` (international, no `+`), `ctx.request` (Fetch `Request` or Express `req`), `ctx.setCookie(cookie)`, `ctx.responseHeaders`. Its return value is sent to the browser as `data` (JSON, non-secret). |
| `beforeSend(ctx)` | — | Your anti-abuse checks (`ctx.phone`, `ctx.request`): return `false` to refuse (403 `forbidden`). |
| `onError(error, { action })` | — | Upstream and unexpected errors (`action`: `'send'` or `'verify'`), for your logs. Never contains the key, the code or `otp_id`. |
| `store` | — (state in the cookie) | `{ get, set, delete }` (Redis, database…): the cookie then only carries a random session id. `memoryStore()` for development. |
| `cookie` | `{ name: 'labyrinthe_otp', path: '/', sameSite: 'Strict' }` | Also `domain` and `secure` (default: on HTTPS requests and when `NODE_ENV=production`). |
| `defaultCountryCode` | `'243'` | Added to local numbers. |
| `template` | account settings | SMS template, must contain `{code}`. |
| `locale` | widget's `locale`, then account settings | SMS language, `'fr'` or `'en'`. |
| `webOtpDomain` | — | Your site's host (`app.example.com`): the SMS ends with `@app.example.com #CODE` for the Web OTP API. Validated at start-up. |
| `stripAccents` | `false` | Remove accents from the SMS (GSM-7, often half the cost). |
| `resendAfterSeconds` | from the API, then 60 | Override of the countdown before *Resend* (the API still enforces the real delay). |
| `codeLength`, `codeType` | from the API | Overrides of the code shape. Only needed with an API older than 1.2.0. |
| `exposeTestCode` | `false` | Development only: sends the `test_code` of a `lsk_test_` key to the browser. Refused when `NODE_ENV=production`. |
| `allowedOrigins` | the request's own host | Origins allowed to call the endpoints (`Origin` / `Sec-Fetch-Site` checks). |

| Environment variable | Read by |
|---|---|
| `LABYRINTHE_API_KEY`, `LABYRINTHE_BASE_URL` | `new Labyrinthe()` (`@labyrinthe/connect`) |
| `LABYRINTHE_OTP_SECRET` | `createOtpHandlers()` when `secret` is not given |
| `NODE_ENV` | `production` refuses `exposeTestCode` and makes the cookie `Secure` |

`createOtpHandlers()` throws at start-up for a missing or short `secret`, a missing `onVerified`, a `template` without
`{code}`, an invalid `webOtpDomain` or `defaultCountryCode`, and `exposeTestCode` in production.

## The element

### Script tag or bundler

```html
<!-- Standalone script (CDN or self-hosted): defines <labyrinthe-otp> and the global LabyrintheOtp -->
<script src="https://cdn.jsdelivr.net/npm/@labyrinthe/otp-widget@1.2.1/dist/labyrinthe-otp.min.js" defer></script>
```

```ts
// With a bundler (Vite, webpack, Next.js…): importing the package defines the element.
import '@labyrinthe/otp-widget';
// Importing it on a server (SSR) does nothing.

import { defineLabyrintheOtp } from '@labyrinthe/otp-widget';
defineLabyrintheOtp('my-otp');   // optional: register another tag name (idempotent)
```

```html
<labyrinthe-otp
  send-url="/api/otp/send"
  verify-url="/api/otp/verify"
  locale="en"
  country-code="243"
  theme="dark"
></labyrinthe-otp>
```

### Attributes

| Attribute | Default | Description |
|---|---|---|
| `send-url`, `verify-url` | `/api/otp/send`, `/api/otp/verify` | Your endpoints. |
| `locale` | `fr` | `fr` or `en`: texts of the widget, and SMS language unless the server sets one. |
| `country-code` | `243` | Default country code, editable by the user. |
| `phone` | — | Pre-filled number. |
| `code-length`, `code-type` | `6`, `numeric` | Code shape until the server says otherwise (it always does with the API ≥ 1.2.0). `code-type`: `numeric` or `alphanumeric`. |
| `theme` | automatic | `light` or `dark`; otherwise follows `prefers-color-scheme`. |
| `heading` | the `title` text | Title above the form; `""` hides it. |
| `credentials` | `same-origin` | `include` when your endpoints are on another origin (then `cookie: { sameSite: 'None', secure: true }` and `allowedOrigins` on the server). |
| `no-auto-submit` | — | Do not submit as soon as every box is filled. |
| `no-web-otp` | — | Do not use the Web OTP API. |

### Properties and methods

| Member | Description |
|---|---|
| `step` | Read-only: `'phone'`, `'code'` or `'success'`. |
| `texts` | Text overrides (object, merged with the locale defaults); `null` resets them. |
| `requestHeaders` | Extra headers sent to your endpoints (e.g. a CSRF token). |
| `reset()` | Back to the phone step, clearing the code. |
| `focus()` | Focuses the first focusable control inside (the Shadow DOM delegates focus). |

```js
const widget = document.querySelector('labyrinthe-otp');
widget.requestHeaders = { 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content };
```

### Events

Every event bubbles and crosses the Shadow DOM (`bubbles`, `composed`); its `detail` is typed (`OtpSentDetail`,
`OtpVerifiedDetail`, `OtpErrorDetail`). Constants: `EVENT_SENT`, `EVENT_VERIFIED`, `EVENT_ERROR`.

| Event | `detail` |
|---|---|
| `labyrinthe-otp:sent` | `{ phone, phoneMasked, expiresAt: Date, maxAttempts, resendAfterSeconds, testCode? }` — first send and every resend |
| `labyrinthe-otp:verified` | `{ phone, data }` — `data` is what your `onVerified()` returned |
| `labyrinthe-otp:error` | `{ code, message, status, retryAfterSeconds?, attemptsLeft?, adminMessage?, until?: Date }` — `message` is the text shown to the user, `status` `null` for local and network errors |

```js
widget.addEventListener('labyrinthe-otp:error', (e) => analytics.track('otp_error', { code: e.detail.code }));
widget.addEventListener('labyrinthe-otp:verified', (e) => location.assign(e.detail.data.redirect));
```

The `verified` event is for the user experience only (redirect, message): the server already trusted the number in
`onVerified()`. Never trust the browser event.

### Web OTP API (Android Chrome)

For the browser to fill the code automatically, the last line of the SMS must be `@your-domain.com #CODE`, where the
domain is exactly the host of the page showing the widget (top-level page, HTTPS). Set the server option
`webOtpDomain: 'shop.example.com'`: the API adds that line itself (do not put it in your `template`). The widget then
calls `navigator.credentials.get({ otp: { transport: ['sms'] } })` while the code boxes are shown (disable with
`no-web-otp`); typing, pasting and `autocomplete="one-time-code"` keep working everywhere.

## React

```tsx
'use client';
import { useRouter } from 'next/navigation';
import { LabyrintheOtp } from '@labyrinthe/otp-widget/react';

export function VerifyPhone() {
  const router = useRouter();
  return (
    <LabyrintheOtp
      sendUrl="/api/otp/send"
      verifyUrl="/api/otp/verify"
      locale="en"
      texts={{ send: 'Text me a code' }}
      onError={(error) => console.warn(error.code)}
      onVerified={({ data }) => router.push((data as { redirect: string }).redirect)}
    />
  );
}
```

| Prop | Same as |
|---|---|
| `sendUrl`, `verifyUrl`, `locale`, `countryCode`, `phone`, `codeLength`, `codeType`, `theme`, `heading`, `credentials` | the attributes above |
| `autoSubmit` (default `true`), `webOtp` (default `true`) | `no-auto-submit`, `no-web-otp` when `false` |
| `texts`, `requestHeaders` | the properties above |
| `onSent`, `onVerified`, `onError` | the events above (receive the `detail`) |
| `className`, `style`, `id` | on the host element |

The component is a client component (`'use client'`), SSR-safe, works with React 18 and 19, and forwards a `ref` to
the `<labyrinthe-otp>` element (`ref.current.reset()`). Callbacks always use the latest props without re-subscribing.

## Styling and theming

The widget renders in a Shadow DOM: style it with CSS custom properties on the host and with `::part()`.

```css
labyrinthe-otp {
  --lab-otp-primary: #008374;          /* buttons, links, focus ring */
  --lab-otp-primary-contrast: #fff;    /* text on primary */
  --lab-otp-accent: #F85A40;           /* test-mode hint */
  --lab-otp-error: #c2381f;
  --lab-otp-success: #008374;
  --lab-otp-bg: #fff;
  --lab-otp-fg: #19201e;
  --lab-otp-muted: #5b6763;
  --lab-otp-border: #d5dcd9;
  --lab-otp-field: #f8faf9;            /* input background */
  --lab-otp-radius: 10px;
  --lab-otp-font: Inter, ui-sans-serif, system-ui, sans-serif;
  --lab-otp-cell-size: 44px;           /* code boxes (36px under 360px wide) */
  --lab-otp-gap: 8px;
}
labyrinthe-otp::part(root) { border: 0; box-shadow: 0 8px 30px rgb(0 0 0 / 0.08); }
labyrinthe-otp::part(cell) { font-size: 24px; }
```

The values above are the light defaults (teal `#008374`, coral `#F85A40`); `theme="dark"`, or the system dark mode
without `theme="light"`, switches to a dark palette. The host is `display: block` with `max-width: 420px`.

Parts: `root`, `title`, `phone-form`, `country-code`, `phone-input`, `button`, `send-button`, `code-form`, `cells`,
`cell`, `verify-button`, `link`, `change-button`, `resend-button`, `test-code`, `success`, `error`.

## Texts and languages

`locale="fr"` (default) or `locale="en"`. Override any text with the `texts` property (or React prop): top-level keys
and `errors` entries are merged with the locale defaults.

```js
widget.texts = {
  send: 'Text me a code',
  errors: { invalid_code: 'Nope, try again.' },
};
```

| Keys | Placeholders |
|---|---|
| `title`, `phoneLabel`, `countryCodeLabel`, `phonePlaceholder`, `phoneHint`, `send`, `sending`, `codeLabel`, `verify`, `verifying`, `changePhone`, `resend`, `resendAvailable`, `attemptsLeftOne`, `success` | — |
| `codeSentTo` | `{phone}` |
| `cellLabel` (accessible name of each box) | `{index}`, `{total}` |
| `resendIn`, `retryIn` | `{time}` (`1:15`, or `1:30:00` beyond an hour) |
| `attemptsLeftOther` | `{count}` |
| `testMode` | `{code}` |
| `errors.<code>` (see [Errors](#errors)) | `{time}` in `resend_too_soon`, `{count}` in `incomplete_code` |

`LOCALES` (the built-in French and English texts), `resolveTexts(locale, override)` and the `OtpWidgetTexts` /
`OtpWidgetTextsOverride` types are exported, for example to build a third language from the English texts.

## Accessibility

- Every field has a label; the code boxes are a `role="group"` with an accessible name per box (`cellLabel`).
- Errors are announced (`role="alert"`, re-announced when the same error repeats); progress (code sent, verified) goes
  to a polite live region.
- Full keyboard use: arrows, Home, End and Backspace move between boxes, paste fills every box,
  `enterkeyhint="done"`.
- Focus moves to the first empty box after a send, to *Resend* when a new code is needed, to the success message at
  the end.
- Visible focus ring, `aria-invalid` on a refused number, `aria-busy` while waiting, transitions disabled with
  `prefers-reduced-motion`.

## Errors

Error codes are stable, lower case, and shown with the localised text of `errors.<code>`. Upstream (Labyrinthe)
messages are never forwarded to the browser, except the maintenance message.

| `code` | HTTP from your server | When | Labyrinthe API error |
|---|---|---|---|
| `invalid_phone` | 422 | The number is invalid, blacklisted or opted out | `INVALID_PHONE`, `RECIPIENT_NOT_SENDABLE`, local validation |
| `resend_too_soon` | 429 | A code was sent too recently: countdown of `retry_after_seconds` | `OTP_RESEND_TOO_SOON` |
| `rate_limited` | 429 | Too many requests (number, account, key) or a send still in progress | `OTP_RATE_LIMITED`, `TOO_MANY_REQUESTS`, `IDEMPOTENCY_REQUEST_IN_PROGRESS` |
| `no_pending_verification` | 400 | No pending code for this browser (cookie missing, expired or tampered with) | `NOT_FOUND` on verify |
| `forbidden` | 403 | Refused by `beforeSend()` or by the origin check | — |
| `invalid_request` | 400, 405, 413, 415, 422 | Malformed request (not `POST`, not JSON, too large, bad `send_id` or code) | — |
| `service_unavailable` | 503 | Labyrinthe unreachable or misconfigured (key, credits, Sender ID), or [maintenance](#maintenance-503-service_unavailable) | `FEATURE_DISABLED`, `OTP_SERVICE_DISABLED`, others |
| `account_suspended` | 503 | Your Labyrinthe account is suspended | `ACCOUNT_SUSPENDED` |
| `send_failed` | 502 | The send failed unexpectedly on the Labyrinthe side, a code may have been sent | `IDEMPOTENCY_KEY_UNRESOLVED` |
| `server_error` | 500 | Unexpected error in your server (e.g. `onVerified()` threw) | — |

Refusals of a code are answered `200 { verified: false, reason }`: `invalid_code` (attempts left shown),
`expired`, `already_used`, `max_attempts_reached` (the last three ask for a new code). Widget-side codes, with
`status: null`: `network_error` (your server is unreachable) and `incomplete_code`.

`onError()` receives every upstream error except the ones caused by the user (`invalid_phone`, `resend_too_soon`,
`rate_limited`), plus unexpected ones: log the `requestId` of `@labyrinthe/connect` errors.

### Maintenance (503 `service_unavailable`)

When the Labyrinthe platform temporarily shuts down the API or the OTP functions (`503 FEATURE_DISABLED` /
`OTP_SERVICE_DISABLED`), nothing is sent and the helper answers:

```json
{ "error": { "code": "service_unavailable", "message": "The verification service is temporarily unavailable.",
  "admin_message": "Maintenance until 14:00.", "until": "2026-11-06T14:00:00+00:00", "retry_after_seconds": 5400 } }
```

- `admin_message`: the platform administrator's message, written for end users (trimmed, at most 500 characters),
  when there is one — the only upstream text forwarded;
- `until` (ISO 8601) and `retry_after_seconds` + a `Retry-After` header: only when the end is scheduled;
- `onError()` is called.

The widget shows the administrator's message (as plain text) or `errors.service_unavailable`, followed by `retryIn`
("Try again in 1:30:00.") when the time left is known. The `labyrinthe-otp:error` event carries `adminMessage` and
`until` (`Date`). Nothing is retried automatically; the user can try again later.

## Security

- **The API key stays on your server.** The browser never sees it, nor `otp_id`, nor the code.
- `otp_id` and the phone number travel in an **AES-256-GCM encrypted and authenticated** cookie (key derived from
  `secret` with HKDF-SHA-256), `HttpOnly`, `SameSite=Strict`, `Secure` on HTTPS. The browser can neither read nor
  modify it. With `store`, the cookie only carries a random session id (encrypted too).
- **Trust the number in `onVerified()`**, on the server, never in the browser event.
- Endpoints accept `POST` + `application/json` only (a cross-site form cannot send it without a CORS preflight), bodies
  up to 4 kB, and reject other origins (`Origin` / `Sec-Fetch-Site`; widen with `allowedOrigins`). Add a CSRF token
  with `requestHeaders` if your app uses one. Responses are `Cache-Control: no-store`.
- **Every send is a billed SMS**: use `beforeSend()` for rate limits per IP or account, a captcha or an allow-list. The
  API also limits sends per number and per account (`resend_too_soon`, `rate_limited`).
- **No double SMS.** Each send carries a random `send_id`, kept by the widget until the send succeeds: after a lost
  response, the next click reuses it. The helper turns it into the `Idempotency-Key` with an HMAC of `secret` (the
  browser never chooses the key sent to Labyrinthe), so the API replays the first send instead of sending a new SMS.
  The SDK also retries network errors and 5xx with the same key.
- **Outcome unknown (`send_failed`).** After `409 IDEMPOTENCY_KEY_UNRESOLVED`, nothing is retried automatically; the
  widget forgets its `send_id`, so a new code requested by the user is sent with a new key.
- The code is never returned to the browser, except `test_code` with a test key when you explicitly enable
  `exposeTestCode` in development (refused in production; the API never returns it with a live key).
- The administrator's maintenance message is inserted as text, never as HTML.

## Test mode

With a `lsk_test_…` key nothing is sent and nothing is billed. In development, `exposeTestCode: true` shows the code
inside the widget ("Test mode: the code is …") and in the `sent` event, so the whole flow can be tried without a phone:

```ts
createOtpHandlers({
  client: new Labyrinthe({ apiKey: 'lsk_test_…' }),
  secret: process.env.LABYRINTHE_OTP_SECRET,
  exposeTestCode: process.env.NODE_ENV === 'development',
  onVerified: () => ({ ok: true }),
});
```

## Size

| File | gzip | |
|---|---|---|
| `dist/labyrinthe-otp.min.js` | 8.1 kB minified (7.2 kB brotli) | standalone `<script>`; CI budget 10 kB min+gzip (`npm run size`) |
| `dist/index.js` | 9.6 kB unminified | ESM for bundlers (minified by your build) |
| `dist/react.js` | 0.7 kB | React wrapper, without the element |
| `dist/server.js` | 6.8 kB unminified | server only, never shipped to the browser |

No runtime dependency. `sideEffects` is declared so bundlers keep the element definition and tree-shake the rest.

## Upgrading

- **1.1.x → 1.2.0** (API 1.3.0): update the peer dependency `@labyrinthe/connect` to `^1.2.0`. No code change.
  Maintenance is answered as `service_unavailable` with `admin_message`, `until` and `Retry-After`; the error event
  gains `adminMessage` and `until`; new text `retryIn` (add it if you pass a complete `OtpWidgetTexts` object);
  durations of an hour or more are shown as `h:mm:ss`.
- **1.1.0 → 1.1.1**: requires `@labyrinthe/connect` ≥ 1.1.1 (short foreign numbers keep their country code). New error
  code `send_failed`.
- **1.0.x → 1.1.0** (API 1.2.0): code length, alphabet and resend delay come from the API: remove `codeLength`,
  `codeType` and `resendAfterSeconds` from the server options unless you need overrides. Real attempts left, new
  `webOtpDomain` and `stripAccents` options, new error code `account_suspended`, and the send request gains `send_id`
  (custom servers must accept it).

Details: [CHANGELOG](CHANGELOG.md).

## Contributing

Issues and pull requests go to the monorepo
[Labyrinthe-Team/labyrinthe-connect-sdks](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks)
(see [CONTRIBUTING.md](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/CONTRIBUTING.md)). In
`widget-otp/`:

```bash
npm ci
npm run check            # lint + typecheck + tests (happy-dom, React, server) + build + check:dist + size + publint + attw

# integration tests against a local backend, with a TEST key only
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 npm run test:integration
```

Security issues: email connect@labyrinthe-rdc.com rather than opening a public issue.

## License

MIT — see [LICENSE](LICENSE).

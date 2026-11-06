# @labyrinthe/otp-widget

Drop-in phone verification for [Labyrinthe Connect](https://labyrinthe-rdc.com): a `<labyrinthe-otp>` web component
(plus a React wrapper) and the two server endpoints it needs, for Express and Next.js.

*Version française : [README.fr.md](README.fr.md).*

- **No API key in the browser.** The widget only calls *your* server; your server calls Labyrinthe with
  [`@labyrinthe/connect`](../node). `otp_id` and the code never reach the browser.
- Phone step (DR Congo `+243` by default, same normalisation as the SDK), separate code boxes, paste,
  `autocomplete="one-time-code"`, Web OTP API on Android, resend countdown, attempts left, every error explained.
- Accessible (labels, `role="alert"`, live region, keyboard, focus management), responsive, light / dark,
  themable with CSS variables and `::part()`, French (default) and English, every text overridable.
- Zero dependencies, Shadow DOM, **8.1 kB min+gzip** (standalone script).

> **New in 1.2.0** (API 1.3.0, `@labyrinthe/connect` ≥ 1.2.0): Labyrinthe maintenance (`FEATURE_DISABLED`, or the
> OTP service off) is shown as `service_unavailable` with the administrator's message and, when the end is scheduled,
> the time left and a `Retry-After` header. See [Maintenance](#maintenance).
>
> **New in 1.1.0** (API 1.2.0, `@labyrinthe/connect` ≥ 1.1.1): code length / alphabet and resend delay come from the
> API (no more manual configuration), real attempts left, `webOtpDomain` for the Web OTP API, idempotent sends
> (a lost response never sends a second SMS), stable `account_suspended` error. See the [CHANGELOG](CHANGELOG.md).

## 5-minute integration

```bash
npm install @labyrinthe/otp-widget @labyrinthe/connect
```

**1. Server (keeps the key and `otp_id`)** — Express:

```ts
import express from 'express';
import { Labyrinthe } from '@labyrinthe/connect';
import { createOtpHandlers } from '@labyrinthe/otp-widget/server';

const otp = createOtpHandlers({
  client: new Labyrinthe(),                        // LABYRINTHE_API_KEY=lsk_test_… (sends nothing, bills nothing)
  secret: process.env.LABYRINTHE_OTP_SECRET!,      // ≥ 32 random chars: openssl rand -hex 32
  onVerified: async ({ phone, request }) => {
    // The ONLY place where the number is trusted: log the user in here.
    return { redirect: '/dashboard' };             // sent to the browser as `data`
  },
});

const app = express();
app.use(express.json());
app.post('/api/otp/send', otp.express.send);
app.post('/api/otp/verify', otp.express.verify);
```

Next.js App Router: `export const POST = otp.send;` in `app/api/otp/send/route.ts`, and `otp.verify` in
`app/api/otp/verify/route.ts` (the handlers take a Fetch `Request` and return a `Response`: Hono, Bun, Deno and edge
runtimes work the same way).

**2. Browser** — plain HTML:

```html
<script type="module">import '@labyrinthe/otp-widget';</script>
<!-- or, without a bundler: <script src="https://unpkg.com/@labyrinthe/otp-widget@1/dist/labyrinthe-otp.min.js"></script> -->

<labyrinthe-otp send-url="/api/otp/send" verify-url="/api/otp/verify"></labyrinthe-otp>
<script>
  document.querySelector('labyrinthe-otp')
    .addEventListener('labyrinthe-otp:verified', (e) => location.assign(e.detail.data.redirect));
</script>
```

React / Next.js (client component):

```tsx
'use client';
import { LabyrintheOtp } from '@labyrinthe/otp-widget/react';

<LabyrintheOtp sendUrl="/api/otp/send" verifyUrl="/api/otp/verify"
  onVerified={({ data }) => router.push((data as { redirect: string }).redirect)} />
```

Complete examples: [`examples/express`](examples/express) (static page + Express server) and
[`examples/nextjs`](examples/nextjs) (route handlers + client component).

## How it works

```
 Browser                          Your server                               Labyrinthe API
 <labyrinthe-otp>                 @labyrinthe/otp-widget/server             (lsk_live_… / lsk_test_…)
      │ POST /api/otp/send {phone}      │                                         │
      │────────────────────────────────▶│ beforeSend() (your anti-abuse checks)   │
      │                                 │ client.otp.send({ phone }) ────────────▶│ SMS
      │                                 │◀──────────── { otp_id, expires_at, … }  │
      │◀── { sent, phone_masked, … } ───│ Set-Cookie: encrypted { otp_id, phone } │
      │    (no otp_id, no code)         │   (HttpOnly, SameSite=Strict, Secure)   │
      │ POST /api/otp/verify {code}     │                                         │
      │────────────────────────────────▶│ decrypt cookie → otp_id                 │
      │                                 │ client.otp.verify({ otpId, code }) ────▶│
      │                                 │◀─────────────── { verified, reason }    │
      │                                 │ onVerified({ phone }) → your session    │
      │◀── { verified: true, data } ────│ cookie cleared                          │
```

## Security

- The API key stays on your server (the SDK even refuses to run in a browser).
- `otp_id` and the phone number travel in an **AES-256-GCM encrypted and authenticated** cookie (key derived from
  `secret` with HKDF-SHA-256), `HttpOnly`, `SameSite=Strict`, `Secure` on HTTPS. The browser can neither read nor
  modify it. Prefer a server-side store? Pass `store` (Redis, database…; `memoryStore()` for development): the
  cookie then only carries a random session id.
- The code is never returned to the browser. With a `lsk_test_` key the API returns a `test_code` (nothing is sent
  in test mode); the helper forwards it **only** with `exposeTestCode: true`, which is refused when
  `NODE_ENV=production`, and the API never returns it with a live key.
- Trust the number in `onVerified()` on the server, never in the browser event.
- Endpoints accept `POST` + `application/json` only (a cross-site form cannot send it without a CORS preflight)
  and reject other origins (`Origin` / `Sec-Fetch-Site`; widen with `allowedOrigins`). Add a CSRF token with the
  `requestHeaders` property if your app uses one.
- Every send is a billed SMS: use `beforeSend()` for rate limits per IP / account, a captcha or an allow-list.
  The API also limits sends per number and per account (`resend_too_soon`, `rate_limited`).
- Upstream messages are never forwarded: the browser gets stable codes (`invalid_phone`, `resend_too_soon`,
  `rate_limited`, `no_pending_verification`, `service_unavailable`, `account_suspended`…). Misconfiguration (key,
  credits, Sender ID) shows as `service_unavailable`, a suspended Labyrinthe account (`ACCOUNT_SUSPENDED`) as
  `account_suspended` (503); both go to your `onError()`. The one exception is the maintenance message below.
- <a id="maintenance"></a>**Maintenance (`service_unavailable`, 503).** When the Labyrinthe platform temporarily shuts
  down the API or the OTP functions (`503 FEATURE_DISABLED` / `OTP_SERVICE_DISABLED`), the helper answers
  `service_unavailable` with `admin_message` (the platform administrator's message, written for end users, when there
  is one), `until` (ISO 8601) and `retry_after_seconds` + a `Retry-After` header when the end is scheduled, and calls
  `onError()`. The widget shows the administrator's message (or `errors.service_unavailable`) followed by `retryIn`
  ("Try again in 1:30.") when the time left is known; the error event carries `adminMessage` and `until` (`Date`).
- **Outcome unknown: `send_failed` (502).** When the API answers `409 IDEMPOTENCY_KEY_UNRESOLVED` (the original send
  with this key failed unexpectedly on its side: a code may have been sent), the helper returns `send_failed` and
  calls `onError()` (log the error's `requestId`). Nothing is retried automatically. The widget tells the user it
  could not confirm the send and forgets its `send_id`: if the code does not arrive, the user asks for a new one,
  sent with a new idempotency key.
- **No double SMS.** Each send carries a random `send_id`, kept by the widget until the send succeeds: after a lost
  response, the next click reuses it. The helper turns it into the `Idempotency-Key` with an HMAC of `secret`
  (the browser never chooses the key sent to Labyrinthe), so the API replays the first send instead of sending a
  new SMS. The SDK also retries network errors and 5xx with the same key.

## Server options (`createOtpHandlers`)

| Option | Default | |
|---|---|---|
| `client` | — | A `Labyrinthe` instance, or a function returning one (created on first use). |
| `secret` | `LABYRINTHE_OTP_SECRET` | ≥ 32 characters. Encrypts the cookie. Not your API key. |
| `onVerified(ctx)` | — | **Required.** `ctx.phone`, `ctx.request` (Fetch `Request` or Express `req`), `ctx.setCookie()`, `ctx.responseHeaders`. Return value → `data`. |
| `beforeSend(ctx)` | — | Return `false` to refuse a send (403 `forbidden`). |
| `store` | — (cookie only) | `{ get, set, delete }`; `memoryStore()` for development. |
| `cookie` | `labyrinthe_otp`, `/`, `Strict` | `name`, `path`, `domain`, `sameSite`, `secure`. |
| `defaultCountryCode` | `243` | Added to local numbers. |
| `template`, `locale` | account settings | SMS template (must contain `{code}`) and language (otherwise the widget's locale). |
| `webOtpDomain` | — | Your site's host (`app.example.com`): the SMS ends with `@app.example.com #CODE` for the Web OTP API. |
| `stripAccents` | `false` | Remove accents from the SMS (GSM-7, often half the cost). |
| `resendAfterSeconds` | from the API | Override of the countdown before "Resend" (default: the API's `resend_after_seconds`, then 60). |
| `codeLength`, `codeType` | from the API | Overrides of the code shape (default: the API's `code_length` / `code_type`). Only needed with an API older than 1.2.0. |
| `exposeTestCode` | `false` | Development only, test keys only. |
| `allowedOrigins` | same host | Origins allowed to call the endpoints. |
| `onError(error, { action })` | — | Upstream and unexpected errors, for your logs. |

## Element reference

| Attribute | Default | |
|---|---|---|
| `send-url`, `verify-url` | `/api/otp/send`, `/api/otp/verify` | Your endpoints. |
| `locale` | `fr` | `fr` or `en` (also the SMS language unless the server sets one). |
| `country-code` | `243` | Default country code, editable by the user. |
| `phone` | — | Pre-filled number. |
| `code-length`, `code-type` | `6`, `numeric` | Until the server says otherwise (it always does with the API 1.2.0). |
| `theme` | automatic | `light` or `dark`; otherwise follows `prefers-color-scheme`. |
| `heading` | "Vérification du numéro" | `""` hides the title. |
| `credentials` | `same-origin` | `include` for endpoints on another origin (then `cookie.sameSite: 'None'`). |
| `no-auto-submit`, `no-web-otp` | — | Disable auto-submit when the boxes are full / the Web OTP API. |

Properties: `texts` (overrides), `requestHeaders`, `step` (`phone` · `code` · `success`), `reset()`, `focus()`.

| Event (`bubbles`, `composed`) | `detail` |
|---|---|
| `labyrinthe-otp:sent` | `{ phone, phoneMasked, expiresAt, maxAttempts, resendAfterSeconds, testCode? }` |
| `labyrinthe-otp:verified` | `{ phone, data }` |
| `labyrinthe-otp:error` | `{ code, message, status, retryAfterSeconds?, attemptsLeft?, adminMessage?, until? }` — `code`: `invalid_phone`, `invalid_code`, `expired`, `already_used`, `max_attempts_reached`, `resend_too_soon`, `rate_limited`, `no_pending_verification`, `network_error`, `incomplete_code`, `service_unavailable`, `account_suspended`, `send_failed`, `forbidden`, `invalid_request`, `server_error` |

## Customisation

```css
labyrinthe-otp {
  --lab-otp-primary: #008374;   /* buttons, links, focus */
  --lab-otp-accent: #F85A40;    /* test-mode hint */
  --lab-otp-error: #c2381f;
  --lab-otp-bg: #fff; --lab-otp-fg: #19201e; --lab-otp-muted: #5b6763;
  --lab-otp-border: #d5dcd9; --lab-otp-field: #f8faf9;
  --lab-otp-radius: 10px; --lab-otp-font: Inter, system-ui, sans-serif;
  --lab-otp-cell-size: 44px; --lab-otp-gap: 8px;
}
labyrinthe-otp::part(root) { box-shadow: none; border: 0; }
labyrinthe-otp::part(cell) { font-size: 24px; }
```

Parts: `root`, `title`, `phone-form`, `country-code`, `phone-input`, `button`, `send-button`, `code-form`, `cells`,
`cell`, `verify-button`, `link`, `change-button`, `resend-button`, `test-code`, `success`, `error`.

```js
widget.texts = { send: 'Text me a code', errors: { invalid_code: 'Nope, try again.' } };
```

**Web OTP API (Android Chrome):** to let the browser fill the code automatically, the last line of the SMS must be
`@your-domain.com #CODE`, where the domain is exactly the host of the page showing the widget (top-level page,
HTTPS). Set the server option `webOtpDomain: 'shop.example.com'`: the API adds that line itself (do not put it in
your `template`). The widget then calls `navigator.credentials.get({ otp: { transport: ['sms'] } })` while the
code boxes are shown (disable with `no-web-otp`); typing or pasting the code keeps working everywhere.


## Test mode

With a `lsk_test_…` key nothing is sent and nothing is billed. In development, `exposeTestCode: true` shows the
code inside the widget ("Test mode: the code is …") so the whole flow can be tried without a phone.

```bash
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 npm run test:integration
```

## Requirements

Browsers with Custom Elements and Shadow DOM (Chrome/Edge 84+, Firefox 90+, Safari 15+). Server helper: Node ≥ 18,
or any runtime with Fetch and Web Crypto (edge, Deno, Bun). React ≥ 18 for the wrapper.

## License

MIT

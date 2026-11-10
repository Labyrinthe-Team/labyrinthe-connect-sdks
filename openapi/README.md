# Labyrinthe Connect — OpenAPI specification

*[Version française](README.fr.md)*

[`openapi.yaml`](openapi.yaml) (OpenAPI 3.1) is **the source of truth** of the public developer API of
Labyrinthe Connect (`/api/public/v1`): sending SMS and verifying phone numbers with OTP. Every SDK, the Postman
collection, the HTML documentation and the contract tests are built on it. It describes the API **as it is**.

Version **1.3.1**. The changes of each version are described in the "Nouveautés 1.3.1", "Nouveautés 1.3.0" and "Nouveautés 1.2.0"
sections of `info.description`; `info.version` follows SemVer. The spec is written in French (descriptions, sample
error messages), like the messages returned by the API.

- Published HTML documentation: <https://labyrinthe-team.github.io/labyrinthe-connect-sdks/> (GitHub Pages, see
  [below](#publishing-on-github-pages)).
- Copy served by the backend, without authentication: `GET https://api.connect.labyrinthe-rdc.com/api/public/openapi.yaml`.

## What's inside

| Part | Content |
|---|---|
| `info.description` | authentication (`lsk_live_…` / `lsk_test_…`), phone numbers, credits and segments, idempotency, error format, maintenance, request id, versioning, dates, rate limits, changes per version |
| `servers` | production `https://api.connect.labyrinthe-rdc.com/api/public/v1`, local `http://localhost:8000/api/public/v1` |
| `paths` (8 operations) | `POST /sms` (`sendSms`), `GET /sms` (`listSms`), `POST /sms/estimate` (`estimateSms`), `GET /sms/{id}` (`getSms`), `GET /balance` (`getBalance`), `GET /sender-ids` (`listSenderIds`), `POST /otp/send` (`sendOtp`), `POST /otp/verify` (`verifyOtp`); tags SMS, Compte (account), OTP |
| `webhooks` (6) | `message.status_changed`, `campaign.completed`, `balance.low`, `otp.verified`, `otp.failed`, `test.ping`: envelope `{ id, event, data, timestamp }`, signature `X-Signature: sha256=…` (HMAC-SHA256 of the raw body), 5 attempts |
| `components.schemas` | `Message`, `Estimate`, `Balance`, `SenderId`, `OtpSendResult`, `OtpVerifyResult`, `Paginated`, enums (`MessageStatus`, `Encoding`, `SenderIdStatus`, `RejectionReason`), `Error` and every typed error (`ValidationError`, `InsufficientCreditsError`, `IdempotencyInProgressError`, `RecipientNotSendableError`, `TooManyRequestsError`, `OtpResendTooSoonError`, `OtpRateLimitedError`, `FeatureDisabledError`), `ErrorCode`, webhook payloads |
| `components.responses` | shared responses: `Unauthenticated` (401), `Forbidden` (403), `NotFound` (404), `ValidationError` (422), `TooManyRequests` (429), `ServiceUnavailable` (503) |
| `components.headers` | `X-Request-Id`, `X-Api-Version`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Idempotent-Replayed`, `Retry-After` (and its idempotency and maintenance variants) |
| `x-common-responses` | responses common to every route, outside any operation (OpenAPI has no global responses): `404 NOT_FOUND` "Route introuvable." and `405 HTTP_ERROR` (`details.allowed_methods`, `Allow` header) |

### Errors

Always `{ "error": { "code", "message", "details" } }`, with `details` **always an object** and messages in French.
The codes (`ErrorCode`) are an open list: a client must accept an unknown code. Main codes: `VALIDATION_ERROR` (422),
`UNAUTHENTICATED` (401), `FORBIDDEN` / `ACCOUNT_SUSPENDED` / `OTP_DISABLED_FOR_ACCOUNT` (403), `NOT_FOUND` (404),
`HTTP_ERROR` (405), `INSUFFICIENT_CREDITS`, `IDEMPOTENCY_KEY_REUSED`, `IDEMPOTENCY_REQUEST_IN_PROGRESS`,
`IDEMPOTENCY_KEY_UNRESOLVED` (409), `RECIPIENT_NOT_SENDABLE`, `SENDER_ID_NOT_APPROVED`, `INVALID_PHONE`,
`INVALID_OTP_TEMPLATE` (422), `TOO_MANY_REQUESTS`, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED` (429),
`FEATURE_DISABLED`, `OTP_SERVICE_DISABLED` (503).

### Maintenance (503 `FEATURE_DISABLED`)

New in 1.3.0: any operation can return `503 FEATURE_DISABLED` when an administrator switches off the whole API
(`api`, refused **before** authentication) or one function (`api.sms_send`, `api.otp_send`, `api.otp_verify`).
Nothing is done (no send, no charge). `details` = `{ feature, blocked_by, message, until }`, and the `Retry-After`
header is present when a re-enable is scheduled. `503 OTP_SERVICE_DISABLED` (`POST /otp/send`, whole OTP service
off) carries the same `details`. Full description: the "Maintenance" section of `info.description`, the
`FeatureDisabledError` schema and the `ServiceUnavailable` response. A client retries after `Retry-After` when it is
present, never in a loop.

## Commands

Node.js 22 (like the CI):

```bash
npm ci

npm run lint            # validates the spec (Redocly, redocly.yaml)
npm run check:routes    # spec ↔ Laravel routes: no missing route, no dead path
npm run test:contract   # real calls to a backend, responses validated against the spec
npm run sync            # copies the spec to ../../labyrinthe-connect-backend/docs/openapi.yaml
npm run docs            # static HTML documentation in dist/index.html
```

`check:routes` and `sync` look for the backend in `../../labyrinthe-connect-backend` (override with
`LABYRINTHE_BACKEND_DIR`); `check:routes` runs `php artisan route:list`.

### Contract test

```bash
LABYRINTHE_API_KEY=lsk_test_… npm run test:contract
# optional: LABYRINTHE_BASE_URL (default http://localhost:8000/api/public/v1), LABYRINTHE_TEST_PHONE
```

- The script **refuses a live key**: it sends SMS and OTPs. The key is only read from the environment.
- It checks the HTTP status, that this status is documented, that the body matches the schema, the common headers
  (`X-Request-Id`, `X-Api-Version: 1`, `details` always an object) and business rules (SMS and OTP idempotency,
  encoding, `strip_accents`, estimate de-duplication, foreign numbers, `Retry-After`, 405, messages in French).
- It calls `POST /otp/send` 8 times (limit: 10 per minute per key): wait a minute before running it again.

## Changing the API

The spec follows the backend, never the other way round; the SDKs follow the spec (see
[`../CONTRIBUTING.md`](../CONTRIBUTING.md)).

1. Change the backend, then `openapi.yaml`. Bump `info.version` (SemVer) and add a "Nouveautés x.y.z" section to
   `info.description`.
2. `npm run lint && npm run check:routes`, then the contract test against a local backend
   (`LABYRINTHE_API_KEY=lsk_test_… npm run test:contract`).
3. `npm run sync`. The backend test `OpenApiDocumentTest` fails when the copied spec no longer matches the routes.
   The backend serves this copy at `GET /api/public/openapi.yaml`.
4. Regenerate the Postman collection: `cd ../postman && npm run generate` (the `postman.yml` CI checks that it is up
   to date).
5. Update each SDK (types, enums, errors, docs) and its CHANGELOG; the SDK contract tests fail when enums, limits or
   operations drift from the spec.
6. An API limit that makes integration harder is reported to the backend team with a proposal; it is never worked
   around in an SDK.

The [`openapi.yml`](../.github/workflows/openapi.yml) CI runs the lint on every push and pull request touching
`openapi/**`, and `check:routes` when the repository variable `LABYRINTHE_BACKEND_REPOSITORY` and the secret
`LABYRINTHE_BACKEND_TOKEN` give access to the backend repository.

## Publishing on GitHub Pages

`npm run docs` builds a standalone HTML page (Redocly) in `dist/index.html`. The
[`.github/workflows/docs.yml`](../.github/workflows/docs.yml) workflow rebuilds it (`npm ci`, lint, docs) and
deploys it to GitHub Pages on every push to `main` touching `openapi/**`, or on demand (*Run workflow*). Address:
<https://labyrinthe-team.github.io/labyrinthe-connect-sdks/>.

One-time setup: *Settings > Pages > Build and deployment > Source*: **GitHub Actions**. The `github-pages`
environment is created automatically on the first deployment.

## Generating a client

The official SDKs (PHP, Laravel, Node.js, Python, .NET, Java) handle idempotency, safe retries and webhook
verification: prefer them. For another language, you can generate a client from the spec, for instance with
[OpenAPI Generator](https://openapi-generator.tech/):

```bash
# from the local file…
npx @openapitools/openapi-generator-cli generate -i openapi.yaml -g go -o ./labyrinthe-go
# …or from the copy served by the API
npx @openapitools/openapi-generator-cli generate \
  -i https://api.connect.labyrinthe-rdc.com/api/public/openapi.yaml -g ruby -o ./labyrinthe-ruby
```

What to watch in a generated client:

- send an `Idempotency-Key` (UUID) on `POST /sms` and `POST /otp/send`, and **reuse it** on every retry;
- only retry on 429 / 5xx / network errors (never `POST /otp/verify` after a network error or a 5xx), and a
  `503 FEATURE_DISABLED` only after `Retry-After`;
- accept unknown fields, error codes and events (compatible additions arrive without notice in v1);
- verify webhooks yourself (HMAC-SHA256 of the raw body, constant-time comparison, timestamp check): most generators
  ignore the `webhooks` section;
- some generators still support OpenAPI 3.1 only partially: review the generated code.

The spec also works with any tool that reads OpenAPI: import into Postman or Insomnia, mock servers (Prism), request
validation.

## Files

```
openapi.yaml              the specification
redocly.yaml              Redocly lint rules
scripts/lib.mjs           shared helpers (backend path, spec loading)
scripts/check-routes.mjs  comparison with php artisan route:list
scripts/contract.mjs      contract test against a backend
scripts/sync.mjs          copy to the backend
dist/                     generated HTML documentation (not committed)
```

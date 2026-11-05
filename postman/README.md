# Labyrinthe Connect — Postman collection

*[Version française](README.fr.md)*

A ready-to-run [Postman](https://www.postman.com/) collection of the Labyrinthe Connect API (SMS and OTP),
generated from [`../openapi/openapi.yaml`](../openapi/openapi.yaml), the source of truth of the API.

## Import

1. Postman > **Import** > `labyrinthe-connect.postman_collection.json`.
2. Import an environment: `local.postman_environment.json` (`http://localhost:8000/api/public/v1`) or
   `production.postman_environment.json` (`https://api.labyrinthe-rdc.com/api/public/v1`).
3. Set `apiKey` (type *secret*) in the environment, preferably a **test key** `lsk_test_…`. Never commit a key.

## Variables

| Variable | Set by | Used by |
|---|---|---|
| `baseUrl` | environment (collection default: local) | every request |
| `apiKey` | you (secret) | collection-level Bearer auth, inherited by every request |
| `messageId` | test script of `POST /sms` | `GET /sms/:id` |
| `otpPhone` | pre-request script of `POST /otp/send` (random DRC number) | `POST /otp/send` |
| `otpId`, `otpTestCode` | test script of `POST /otp/send` | `POST /otp/verify` |

`POST /sms` sends `Idempotency-Key: {{$guid}}` (a new key on each run).

## Test mode

With a `lsk_test_…` key nothing is sent and nothing is billed. `POST /otp/send` then returns `test_code`, saved
in `otpTestCode`, so running the whole collection in order (Collection Runner) plays the nominal flow
send → get, and OTP send → verify (`verified: true`). With a live key, `test_code` is never returned: type the
code received by SMS into the body of `POST /otp/verify`.

## Regenerate and check

```bash
npm ci
npm run generate                          # rewrites labyrinthe-connect.postman_collection.json (deterministic)
npm run check                             # fails when the committed collection is out of date (CI: postman.yml)
LABYRINTHE_API_KEY=lsk_test_… npm test    # Newman against a local backend (LABYRINTHE_BASE_URL to override)
```

`npm test` refuses a live key; the key is only read from the environment and is never printed.

Do not edit the collection by hand: change the spec (or `scripts/generate.mjs`) and regenerate.

# Labyrinthe Connect — Postman collection

*[Version française](README.fr.md)*

A ready-to-run [Postman](https://www.postman.com/) collection (v2.1) of the Labyrinthe Connect developer API (SMS
and OTP), generated from [`../openapi/openapi.yaml`](../openapi/openapi.yaml), the source of truth of the API.
Collection **1.3.1**, generated from the spec 1.3.1 (see the [CHANGELOG](CHANGELOG.md)).

| File | Content |
|---|---|
| `labyrinthe-connect.postman_collection.json` | the collection: one folder per tag (SMS, Compte, OTP), Bearer auth at collection level, light test scripts |
| `local.postman_environment.json` | `baseUrl` = `http://localhost:8000/api/public/v1`, empty `apiKey` |
| `production.postman_environment.json` | `baseUrl` = `https://api.connect.labyrinthe-rdc.com/api/public/v1`, empty `apiKey` |

Requests: `POST /sms`, `GET /sms`, `GET /sms/:id`, `POST /sms/estimate` (SMS); `GET /balance`, `GET /sender-ids`
(Compte); `POST /otp/send`, its replay with the same `Idempotency-Key`, `POST /otp/verify` (OTP). Every request
documents its responses, including `503 FEATURE_DISABLED` (feature switched off by the platform).

## 1. Import

1. Postman > **Import** > `labyrinthe-connect.postman_collection.json`.
2. Import an environment: `local.postman_environment.json` or `production.postman_environment.json`, then select it
   (top-right environment selector).

## 2. Set your API key

In the selected environment, set `apiKey` (type *secret*) to a **test key** `lsk_test_…`, created in the client area
(*Developers > API keys*). With a test key nothing is sent and nothing is billed. Keep the key in the environment's
*current value* only: never commit a key or share an exported environment that contains one.

The key is sent as `Authorization: Bearer {{apiKey}}` by the collection-level auth, inherited by every request.

## 3. Run the nominal flow

With a `lsk_test_…` key, run the whole collection in order (**Run collection**, Collection Runner). It plays:

1. `POST /sms` → saves `messageId`, then `GET /sms/:id` reads that message;
2. `POST /otp/send` (random DRC number) → saves `otpId` and `otpTestCode` (returned in test mode only);
3. the replay of `POST /otp/send` with the same `Idempotency-Key` → same `otp_id`, `Idempotent-Replayed: true`, no new
   SMS;
4. `POST /otp/verify` with `otpTestCode` → `verified: true`.

Every test also checks the `X-Request-Id` and `X-Api-Version: 1` response headers. With a live key, `test_code` is
never returned: type the code received by SMS into the body of `POST /otp/verify`.

### Variables

| Variable | Set by | Used by |
|---|---|---|
| `baseUrl` | environment (collection default: local) | every request |
| `apiKey` | you (secret) | collection-level Bearer auth, inherited by every request |
| `messageId` | test script of `POST /sms` | `GET /sms/:id` |
| `otpPhone` | pre-request script of `POST /otp/send` (random DRC number) | `POST /otp/send` |
| `otpIdempotencyKey` | pre-request script of `POST /otp/send` (UUID) | `POST /otp/send` and its replay |
| `otpId`, `otpTestCode` | test script of `POST /otp/send` | replay of `POST /otp/send`, `POST /otp/verify` |

`POST /sms` sends `Idempotency-Key: {{$guid}}` (a new key on each run). `POST /otp/send` sends
`Idempotency-Key: {{otpIdempotencyKey}}`, then the "Replay with the same Idempotency-Key" request replays it. Every
request has a disabled `X-Request-Id` header (`{{$guid}}`): enable it when tracing a request with support.

Rate limits apply as in production: 10 `POST /otp/send` per minute per key. Wait a minute between two full runs.

## 4. Newman (command line, CI)

```bash
npm ci
LABYRINTHE_API_KEY=lsk_test_… npm test    # Newman against a local backend (LABYRINTHE_BASE_URL to override)
```

`npm test` (`scripts/newman.mjs`) refuses a live key; the key is only read from the environment, injected in memory
and never printed. `LABYRINTHE_BASE_URL` accepts a bare host (`http://localhost:8000` is completed with
`/api/public/v1`).

With the Newman CLI directly (the key then appears in the process arguments and your shell history — prefer
`npm test`):

```bash
npx newman run labyrinthe-connect.postman_collection.json -e local.postman_environment.json \
  --env-var "apiKey=$LABYRINTHE_API_KEY"
```

## 5. Regenerate and check

```bash
npm ci
npm run generate    # rewrites labyrinthe-connect.postman_collection.json from ../openapi/openapi.yaml (deterministic)
npm run check       # fails when the committed collection is out of date (CI: postman.yml)
```

Do not edit the collection by hand: change the spec (or `scripts/generate.mjs`, which adds the scripts and
variables) and regenerate. After a spec change, run `npm run generate`, commit the collection, and bump the version
in `package.json` and the [CHANGELOG](CHANGELOG.md) to the spec version.

## License

MIT — see [LICENSE](LICENSE).

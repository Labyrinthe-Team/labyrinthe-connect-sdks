# Changelog

All notable changes to the Postman collection are documented here. The version follows the OpenAPI spec
(`openapi/openapi.yaml`, `info.version`).

## [Unreleased]

## [1.3.1] - 2026-11-09

### Fixed
- Production environment: `baseUrl` is now `https://api.connect.labyrinthe-rdc.com/api/public/v1`.

## [1.3.0] - 2026-11-06

### Added
- Collection regenerated from the OpenAPI spec 1.3.0: every request documents the new `503 FEATURE_DISABLED`
  response (feature shut down by the platform, `details` = `{ feature, blocked_by, message, until }`,
  `Retry-After` when a re-enable is scheduled), and `POST /otp/send` the enriched `503 OTP_SERVICE_DISABLED`.

## [1.2.0] - 2026-11-06

### Added
- Collection regenerated from the OpenAPI spec 1.2.0.
- `POST /otp/send` sends `Idempotency-Key: {{otpIdempotencyKey}}` (new UUID on each run, collection variable
  `otpIdempotencyKey`), followed by a new request "Replay with the same Idempotency-Key" that checks the same
  `otp_id` and the `Idempotent-Replayed: true` header.
- Optional `X-Request-Id` request header (`{{$guid}}`, disabled) on every request; every test checks the
  `X-Request-Id` and `X-Api-Version: 1` response headers.
- Tests on `code_length`, `code_type`, `resend_after_seconds` (`POST /otp/send`) and `attempts_remaining`
  (`POST /otp/verify`).

## [1.1.0] - 2026-11-05

### Added
- Postman v2.1 collection generated from the OpenAPI spec 1.1.0 (`openapi-to-postmanv2` 6.4.0), one folder per
  tag (SMS, Compte, OTP).
- Collection variables `baseUrl` and `apiKey` (secret, empty), Bearer auth at collection level.
- Light test scripts: expected status, `messageId` saved by `POST /sms` for `GET /sms/:id`, random DRC phone
  number for `POST /otp/send`, `otpId` / `otpTestCode` saved for `POST /otp/verify` (test mode).
- `local` and `production` environments (no key).
- `npm run generate` (deterministic output), `npm run check` (CI) and `npm test` (Newman against a local backend).

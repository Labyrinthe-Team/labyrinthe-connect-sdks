# Changelog

All notable changes to the Postman collection are documented here. The version follows the OpenAPI spec
(`openapi/openapi.yaml`, `info.version`).

## [Unreleased]

## [1.1.0] - 2026-11-05

### Added
- Postman v2.1 collection generated from the OpenAPI spec 1.1.0 (`openapi-to-postmanv2` 6.4.0), one folder per
  tag (SMS, Compte, OTP).
- Collection variables `baseUrl` and `apiKey` (secret, empty), Bearer auth at collection level.
- Light test scripts: expected status, `messageId` saved by `POST /sms` for `GET /sms/:id`, random DRC phone
  number for `POST /otp/send`, `otpId` / `otpTestCode` saved for `POST /otp/verify` (test mode).
- `local` and `production` environments (no key).
- `npm run generate` (deterministic output), `npm run check` (CI) and `npm test` (Newman against a local backend).

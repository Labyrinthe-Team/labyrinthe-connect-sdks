import { describe, expect, it } from 'vitest';
import {
  ApiError,
  AuthenticationError,
  IdempotencyKeyReusedError,
  InsufficientCreditsError,
  InvalidPhoneError,
  LabyrintheError,
  NotFoundError,
  OtpRateLimitedError,
  OtpResendTooSoonError,
  PermissionError,
  RateLimitError,
  RecipientNotSendableError,
  SenderIdNotApprovedError,
  ServiceUnavailableError,
  UnexpectedResponseError,
  ValidationError,
} from '../src/index.js';
import { apiError, harness, json, response, rejection } from './helpers.js';

async function errorFor(answer: Parameters<typeof harness>[0][number]): Promise<unknown> {
  const { client } = harness([answer], { maxRetries: 0 });
  return rejection(client.balance.get());
}

describe('error mapping', () => {
  it.each([
    [401, 'UNAUTHENTICATED', AuthenticationError],
    [403, 'FORBIDDEN', PermissionError],
    [403, 'OTP_DISABLED_FOR_ACCOUNT', PermissionError],
    [404, 'NOT_FOUND', NotFoundError],
    [422, 'VALIDATION_ERROR', ValidationError],
    [422, 'RECIPIENT_NOT_SENDABLE', RecipientNotSendableError],
    [422, 'SENDER_ID_NOT_APPROVED', SenderIdNotApprovedError],
    [422, 'INVALID_PHONE', InvalidPhoneError],
    [422, 'INVALID_OTP_TEMPLATE', ApiError],
    [409, 'INSUFFICIENT_CREDITS', InsufficientCreditsError],
    [409, 'IDEMPOTENCY_KEY_REUSED', IdempotencyKeyReusedError],
    [429, 'TOO_MANY_REQUESTS', RateLimitError],
    [429, 'OTP_RESEND_TOO_SOON', OtpResendTooSoonError],
    [429, 'OTP_RATE_LIMITED', OtpRateLimitedError],
    [503, 'OTP_SERVICE_DISABLED', ServiceUnavailableError],
    [500, 'HTTP_ERROR', ServiceUnavailableError],
    [418, 'SOMETHING_NEW', ApiError],
  ] as const)('%i %s → %s', async (status, code, ErrorClass) => {
    const error = await errorFor(apiError(status, code));

    expect(error).toBeInstanceOf(ErrorClass);
    expect(error).toBeInstanceOf(ApiError);
    expect(error).toBeInstanceOf(LabyrintheError);
    expect(error).toBeInstanceOf(Error);
    const e = error as ApiError;
    expect(e.code).toBe(code);
    expect(e.status).toBe(status);
    expect(e.message).toBe(`Erreur ${code}`);
    expect(e.details).toEqual({});
    expect(e.name).toBe(ErrorClass.name);
  });

  it('falls back on the HTTP status when the body is not an API error', async () => {
    const cases = [
      [response(401, ''), AuthenticationError, 'HTTP_ERROR'],
      [response(403, '<html>'), PermissionError, 'HTTP_ERROR'],
      [response(404, ''), NotFoundError, 'HTTP_ERROR'],
      [response(429, ''), RateLimitError, 'TOO_MANY_REQUESTS'],
      [response(502, 'Bad gateway'), ServiceUnavailableError, 'HTTP_ERROR'],
      [response(400, ''), ApiError, 'HTTP_ERROR'],
      [response(302, ''), ApiError, 'HTTP_ERROR'],
    ] as const;
    for (const [answer, ErrorClass, code] of cases) {
      const error = (await errorFor(answer)) as ApiError;
      expect(error).toBeInstanceOf(ErrorClass);
      expect(error.code).toBe(code);
      expect(error.message).toBe(`Labyrinthe Connect API returned HTTP ${String(answer.status)}.`);
    }
  });

  it('turns `details: []` into an empty object', async () => {
    const error = (await errorFor(apiError(404, 'NOT_FOUND', []))) as ApiError;
    expect(error.details).toEqual({});
  });

  it('exposes field errors', async () => {
    const error = (await errorFor(
      apiError(422, 'VALIDATION_ERROR', { body: ['Le champ body est obligatoire.'], sender: 'Trop long.' }),
    )) as ValidationError;

    expect(error.fieldErrors).toEqual({ body: ['Le champ body est obligatoire.'], sender: ['Trop long.'] });
  });

  it('exposes the rejected recipients', async () => {
    const error = (await errorFor(
      apiError(422, 'RECIPIENT_NOT_SENDABLE', {
        reason: 'blacklisted',
        rejected: [
          { recipient: '243812345678', reason: 'blacklisted' },
          { recipient: '243899999999', reason: 'opted_out' },
          { recipient: '12', reason: 'something_new' },
          'garbage',
        ],
      }),
    )) as RecipientNotSendableError;

    expect(error.reason).toBe('blacklisted');
    expect(error.rejected).toEqual([
      { recipient: '243812345678', reason: 'blacklisted' },
      { recipient: '243899999999', reason: 'opted_out' },
      { recipient: '12', reason: 'unknown' },
    ]);
  });

  it('has no rejected list for OTP sends', async () => {
    const error = (await errorFor(apiError(422, 'RECIPIENT_NOT_SENDABLE', { reason: 'invalid_phone' }))) as RecipientNotSendableError;
    expect(error.reason).toBe('invalid_phone');
    expect(error.rejected).toEqual([]);
  });

  it('exposes available and required credits', async () => {
    const error = (await errorFor(apiError(409, 'INSUFFICIENT_CREDITS', { available: 3, required: '12' }))) as InsufficientCreditsError;
    expect(error.available).toBe(3);
    expect(error.required).toBe(12);
  });

  it('exposes retryAfterMs from the header, then from the details', async () => {
    const fromHeader = (await errorFor(apiError(429, 'TOO_MANY_REQUESTS', {}, { 'Retry-After': '45' }))) as RateLimitError;
    const fromDetails = (await errorFor(apiError(429, 'OTP_RESEND_TOO_SOON', { retry_after_seconds: 30 }))) as RateLimitError;
    const none = (await errorFor(apiError(429, 'TOO_MANY_REQUESTS'))) as RateLimitError;

    expect(fromHeader.retryAfterMs).toBe(45_000);
    expect(fromDetails.retryAfterMs).toBe(30_000);
    expect(none.retryAfterMs).toBeNull();
  });

  it('exposes the OTP rate limit scope and limit', async () => {
    const error = (await errorFor(apiError(429, 'OTP_RATE_LIMITED', { scope: 'phone', limit: 5 }))) as OtpRateLimitedError;
    const unknown = (await errorFor(apiError(429, 'OTP_RATE_LIMITED', { scope: 'planet' }))) as OtpRateLimitedError;

    expect(error).toBeInstanceOf(RateLimitError);
    expect(error.scope).toBe('phone');
    expect(error.limit).toBe(5);
    expect(unknown.scope).toBe('unknown');
    expect(unknown.limit).toBeNull();
  });

  it('exposes the request id and headers', async () => {
    const error = (await errorFor(apiError(404, 'NOT_FOUND', {}, { 'X-Request-Id': 'req-42' }))) as ApiError;
    const without = (await errorFor(apiError(404, 'NOT_FOUND'))) as ApiError;

    expect(error.requestId).toBe('req-42');
    expect(error.headers['x-request-id']).toBe('req-42');
    expect(without.requestId).toBeNull();
  });

  it('refuses a successful response that is not a JSON object', async () => {
    const notJson = (await errorFor(response(200, '<html>'))) as UnexpectedResponseError;
    const notObject = (await errorFor(json(200, [1, 2]))) as UnexpectedResponseError;
    const missingField = (await errorFor(json(200, { data: { balance: 'lots' } }))) as UnexpectedResponseError;

    expect(notJson).toBeInstanceOf(UnexpectedResponseError);
    expect(notJson.code).toBe('UNEXPECTED_RESPONSE');
    expect(notJson.status).toBe(200);
    expect(notObject).toBeInstanceOf(UnexpectedResponseError);
    expect(missingField.message).toBe('Unexpected API payload: field "balance" should be an integer.');
  });
});

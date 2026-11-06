/**
 * Nouveautés de la spec 1.3.0 : 503 FEATURE_DISABLED (fonctionnalité coupée par
 * la plateforme) et OTP_SERVICE_DISABLED avec les mêmes `details`, relancés
 * uniquement après un Retry-After acceptable.
 */
import { describe, expect, it } from 'vitest';
import { FeatureDisabledError, ServiceUnavailableError, type Labyrinthe } from '../src/index.js';
import { apiError, harness, json, message, rejection } from './helpers.js';

const disabled = (
  details: Record<string, unknown> | unknown[] = {},
  headers: Record<string, string> = {},
  code = 'FEATURE_DISABLED',
) => apiError(503, code, details, headers);
const maintenance = (retryAfter?: number, code = 'FEATURE_DISABLED') =>
  disabled(
    { feature: 'api.sms_send', blocked_by: 'api', message: 'Maintenance jusqu’à 10 h.', until: '2026-11-06T10:00:00+00:00' },
    retryAfter === undefined ? {} : { 'Retry-After': String(retryAfter) },
    code,
  );
const balance = () => json(200, { data: { balance: 5, held: 0 } });
const smsSent = () => json(202, { data: [message()] });
const otpSent = () =>
  json(201, {
    data: {
      otp_id: 'otp-1',
      expires_at: '2026-11-05T10:05:00+00:00',
      max_attempts: 3,
      code_length: 6,
      code_type: 'numeric',
      resend_after_seconds: 60,
    },
  });
const verified = () => json(200, { data: { verified: true, reason: null, attempts_remaining: null } });

async function errorFor(answer: Parameters<typeof harness>[0][number]): Promise<FeatureDisabledError> {
  const { client } = harness([answer], { maxRetries: 0 });
  return (await rejection(client.balance.get())) as FeatureDisabledError;
}

const calls = [
  ['balance.get()', (c: Labyrinthe) => c.balance.get(), balance],
  ['sms.send()', (c: Labyrinthe) => c.sms.send({ to: '0812345678', body: 'Hello' }), smsSent],
  ['otp.send()', (c: Labyrinthe) => c.otp.send({ phone: '0812345678' }), otpSent],
  ['otp.verify()', (c: Labyrinthe) => c.otp.verify({ otpId: 'otp-1', code: '123456' }), verified],
] as const;

describe('FeatureDisabledError', () => {
  it.each(['FEATURE_DISABLED', 'OTP_SERVICE_DISABLED'])('maps 503 %s and exposes its details', async (code) => {
    const error = await errorFor(maintenance(120, code));

    expect(error).toBeInstanceOf(FeatureDisabledError);
    expect(error).toBeInstanceOf(ServiceUnavailableError);
    expect(error.name).toBe('FeatureDisabledError');
    expect(error.code).toBe(code);
    expect(error.status).toBe(503);
    expect(error.feature).toBe('api.sms_send');
    expect(error.blockedBy).toBe('api');
    expect(error.adminMessage).toBe('Maintenance jusqu’à 10 h.');
    expect(error.until).toEqual(new Date('2026-11-06T10:00:00Z'));
    expect(error.retryAfter).toBe(120);
    expect(error.retryAfterMs).toBe(120_000);
  });

  it('reads the whole OTP service shutdown (feature = blocked_by = otp, no message, no date)', async () => {
    const error = await errorFor(
      disabled({ feature: 'otp', blocked_by: 'otp', message: null, until: null }, {}, 'OTP_SERVICE_DISABLED'),
    );

    expect(error.feature).toBe('otp');
    expect(error.blockedBy).toBe('otp');
    expect(error.adminMessage).toBeNull();
    expect(error.until).toBeNull();
    expect(error.retryAfter).toBeNull();
    expect(error.retryAfterMs).toBeNull();
  });

  it.each([
    ['missing', {}],
    ['legacy []', []],
    ['wrongly typed', { feature: 1, blocked_by: ['api'], message: { text: 'x' }, until: 1794046500 }],
  ])('tolerates %s details', async (_label, details) => {
    const error = await errorFor(disabled(details));

    expect(error).toBeInstanceOf(FeatureDisabledError);
    expect(error.feature).toBeNull();
    expect(error.blockedBy).toBeNull();
    expect(error.adminMessage).toBeNull();
    expect(error.until).toBeNull();
    expect(error.retryAfter).toBeNull();
  });

  it('returns null for an invalid until and ignores a malformed Retry-After', async () => {
    const error = await errorFor(
      disabled({ feature: 'api', blocked_by: 'api', message: null, until: 'tomorrow-ish' }, { 'Retry-After': 'soon' }),
    );

    expect(error.until).toBeNull();
    expect(error.retryAfter).toBeNull();
  });

  it('reads the delay from the Retry-After header only', async () => {
    const error = await errorFor(disabled({ retry_after_seconds: 30 }));
    expect(error.retryAfter).toBeNull();
  });
});

describe('FeatureDisabledError retries', () => {
  it.each(calls)('%s: never retried without Retry-After (no blind backoff)', async (_label, call, ok) => {
    const { client, requests, sleeps } = harness([maintenance(), ok()]);

    await expect(call(client)).rejects.toBeInstanceOf(FeatureDisabledError);
    expect(requests).toHaveLength(1);
    expect(sleeps).toEqual([]);
  });

  it.each(calls)('%s: retried after exactly Retry-After when it fits in maxRetryWaitMs', async (_label, call, ok) => {
    const { client, requests, sleeps } = harness([maintenance(3), ok()], { maxRetryWaitMs: 10_000 });

    await call(client);
    expect(requests).toHaveLength(2);
    expect(sleeps).toEqual([3000]);
    expect(requests[1]!.headers['Idempotency-Key']).toBe(requests[0]!.headers['Idempotency-Key']);
  });

  it('retries OTP_SERVICE_DISABLED with Retry-After like FEATURE_DISABLED', async () => {
    const { client, requests, sleeps } = harness([maintenance(2, 'OTP_SERVICE_DISABLED'), otpSent()]);

    expect((await client.otp.send({ phone: '0812345678' })).otpId).toBe('otp-1');
    expect(requests).toHaveLength(2);
    expect(sleeps).toEqual([2000]);
  });

  it.each(calls)('%s: thrown immediately when Retry-After exceeds maxRetryWaitMs', async (_label, call, ok) => {
    const { client, requests, sleeps } = harness([maintenance(3600), ok()], { maxRetryWaitMs: 10_000 });

    const error = (await rejection(call(client))) as FeatureDisabledError;
    expect(error).toBeInstanceOf(FeatureDisabledError);
    expect(error.retryAfter).toBe(3600);
    expect(requests).toHaveLength(1);
    expect(sleeps).toEqual([]);
  });

  it('stops after maxRetries, and maxRetries: 0 disables the retry', async () => {
    const lasting = harness([maintenance(1), maintenance(1), maintenance(1)], { maxRetries: 2 });
    await expect(lasting.client.balance.get()).rejects.toBeInstanceOf(FeatureDisabledError);
    expect(lasting.requests).toHaveLength(3);
    expect(lasting.sleeps).toEqual([1000, 1000]);

    const none = harness([maintenance(1)], { maxRetries: 0 });
    await expect(none.client.otp.verify({ otpId: 'otp-1', code: '123456' })).rejects.toBeInstanceOf(
      FeatureDisabledError,
    );
    expect(none.requests).toHaveLength(1);
  });

  it('keeps the backoff for other 5xx on safe calls, and never retries otp.verify() on them', async () => {
    const safe = harness([apiError(503, 'HTTP_ERROR'), balance()]);
    expect((await safe.client.balance.get()).balance).toBe(5);
    expect(safe.sleeps).toHaveLength(1);

    const verify = harness([apiError(503, 'HTTP_ERROR', {}, { 'Retry-After': '1' }), verified()]);
    await expect(verify.client.otp.verify({ otpId: 'otp-1', code: '123456' })).rejects.toBeInstanceOf(
      ServiceUnavailableError,
    );
    expect(verify.requests).toHaveLength(1);
  });
});

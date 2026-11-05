import { describe, expect, it } from 'vitest';
import { InvalidArgumentError, NotFoundError, OtpSendResult } from '../src/index.js';
import { apiError, harness, json, rejection } from './helpers.js';

const sent = (extra: Record<string, unknown> = {}) =>
  json(201, { data: { otp_id: 'otp-1', expires_at: '2026-11-05T10:05:00+00:00', max_attempts: 3, ...extra } });

describe('otp.send', () => {
  it('normalises the phone and returns the otpId, expiry and attempts', async () => {
    const { client, requests } = harness([sent()]);

    const otp = await client.otp.send({ phone: '0812345678' });

    expect(requests[0]!.url).toBe('https://api.test/api/public/v1/otp/send');
    expect(requests[0]!.json).toEqual({ phone: '243812345678' });
    expect(requests[0]!.headers['Idempotency-Key']).toBeUndefined();
    expect(otp).toBeInstanceOf(OtpSendResult);
    expect(otp.otpId).toBe('otp-1');
    expect(otp.expiresAt).toEqual(new Date('2026-11-05T10:05:00Z'));
    expect(otp.maxAttempts).toBe(3);
    expect(otp.testCode).toBeNull();
  });

  it('returns the test code in test mode', async () => {
    const { client } = harness([sent({ test_code: '482913' })]);
    expect((await client.otp.send({ phone: '0812345678' })).testCode).toBe('482913');
  });

  it('sends the template and the locale', async () => {
    const { client, requests } = harness([sent()]);

    await client.otp.send({ phone: '+33 6 12 34 56 78', template: 'Your MyShop code: {code}', locale: 'en' });

    expect(requests[0]!.json).toEqual({ phone: '33612345678', template: 'Your MyShop code: {code}', locale: 'en' });
  });

  it.each([
    ['an invalid phone', { phone: 'abc' }, /Invalid phone number in "phone"/],
    ['a template without {code}', { phone: '0812345678', template: 'Hello' }, /\{code\}/],
    ['a template over 1600 characters', { phone: '0812345678', template: `{code}${'a'.repeat(1600)}` }, /at most 1600/],
    ['an unknown locale', { phone: '0812345678', locale: 'de' }, /locale/],
  ])('refuses %s before any call', async (_label, params, pattern) => {
    const { client, requests } = harness([]);

    const error = await rejection(client.otp.send(params as Parameters<typeof client.otp.send>[0]));

    expect(error).toBeInstanceOf(InvalidArgumentError);
    expect((error as Error).message).toMatch(pattern);
    expect(requests).toHaveLength(0);
  });
});

describe('otp.verify', () => {
  it('returns verified: true', async () => {
    const { client, requests } = harness([json(200, { data: { verified: true, reason: null } })]);

    const result = await client.otp.verify({ otpId: 'otp-1', code: '482913' });

    expect(requests[0]!.json).toEqual({ otp_id: 'otp-1', code: '482913' });
    expect(result).toEqual({ verified: true, reason: null });
  });

  it.each(['invalid_code', 'expired', 'already_used', 'max_attempts_reached'] as const)(
    'returns verified: false with reason %s (not an error)',
    async (reason) => {
      const { client } = harness([json(200, { data: { verified: false, reason } })]);
      expect(await client.otp.verify({ otpId: 'otp-1', code: '000000' })).toEqual({ verified: false, reason });
    },
  );

  it('maps an unknown reason to "unknown"', async () => {
    const { client } = harness([json(200, { data: { verified: false, reason: 'cosmic_ray' } })]);
    expect(await client.otp.verify({ otpId: 'otp-1', code: '000000' })).toEqual({ verified: false, reason: 'unknown' });
  });

  it('throws NotFoundError for an unknown otpId', async () => {
    const { client } = harness([apiError(404, 'NOT_FOUND')]);
    await expect(client.otp.verify({ otpId: 'nope', code: '1' })).rejects.toBeInstanceOf(NotFoundError);
  });

  it('validates otpId and code locally', async () => {
    const { client, requests } = harness([]);
    await expect(client.otp.verify({ otpId: '', code: '123456' })).rejects.toBeInstanceOf(InvalidArgumentError);
    await expect(client.otp.verify({ otpId: 'otp-1', code: ' ' })).rejects.toBeInstanceOf(InvalidArgumentError);
    await expect(client.otp.verify({ otpId: 'otp-1', code: '1'.repeat(21) })).rejects.toBeInstanceOf(InvalidArgumentError);
    expect(requests).toHaveLength(0);
  });
});

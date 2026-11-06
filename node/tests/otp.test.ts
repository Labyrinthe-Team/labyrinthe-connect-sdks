import { inspect } from 'node:util';
import { describe, expect, it } from 'vitest';
import { InvalidArgumentError, NotFoundError, OtpSendResult } from '../src/index.js';
import { apiError, harness, json, rejection } from './helpers.js';

/** Réponse 201 de la spec 1.2.0 (tous les champs obligatoires). */
const otpData = (extra: Record<string, unknown> = {}) => ({
  otp_id: 'otp-1',
  expires_at: '2026-11-05T10:05:00+00:00',
  max_attempts: 3,
  code_length: 6,
  code_type: 'numeric',
  resend_after_seconds: 60,
  ...extra,
});
const sent = (extra: Record<string, unknown> = {}, headers: Record<string, string> = {}) =>
  json(201, { data: otpData(extra) }, headers);

describe('otp.send', () => {
  it('normalises the phone and returns the otpId, expiry, attempts and the shape of the code', async () => {
    const { client, requests } = harness([sent({}, { 'X-Request-Id': 'req-42' })]);

    const otp = await client.otp.send({ phone: '0812345678' });

    expect(requests[0]!.url).toBe('https://api.test/api/public/v1/otp/send');
    expect(requests[0]!.json).toEqual({ phone: '+243812345678' });
    expect(otp).toBeInstanceOf(OtpSendResult);
    expect(otp.otpId).toBe('otp-1');
    expect(otp.expiresAt).toEqual(new Date('2026-11-05T10:05:00Z'));
    expect(otp.maxAttempts).toBe(3);
    expect(otp.codeLength).toBe(6);
    expect(otp.codeType).toBe('numeric');
    expect(otp.resendAfterSeconds).toBe(60);
    expect(otp.testCode).toBeNull();
    expect(otp.replayed).toBe(false);
    expect(otp.requestId).toBe('req-42');
  });

  it('always sends an Idempotency-Key (generated UUID v4), returned on the result', async () => {
    const { client, requests } = harness([sent(), sent()]);

    const first = await client.otp.send({ phone: '0812345678' });
    const second = await client.otp.send({ phone: '0812345678' });

    const key = requests[0]!.headers['Idempotency-Key'];
    expect(key).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(first.idempotencyKey).toBe(key);
    // Une clé par appel : deux envois voulus restent deux envois.
    expect(second.idempotencyKey).not.toBe(key);
  });

  it('uses your idempotencyKey and reports a replay', async () => {
    const { client, requests } = harness([sent({ test_code: '482913' }, { 'Idempotent-Replayed': 'true' })]);

    const otp = await client.otp.send({ phone: '0812345678', idempotencyKey: 'login-42-attempt-1' });

    expect(requests[0]!.headers['Idempotency-Key']).toBe('login-42-attempt-1');
    expect(otp.idempotencyKey).toBe('login-42-attempt-1');
    expect(otp.replayed).toBe(true);
    expect(otp.testCode).toBe('482913');
  });

  it('reads an alphanumeric code and maps an unknown alphabet to "unknown"', async () => {
    const { client } = harness([sent({ code_length: 8, code_type: 'alphanumeric' }), sent({ code_type: 'emoji' })]);

    expect(await client.otp.send({ phone: '0812345678' })).toMatchObject({ codeLength: 8, codeType: 'alphanumeric' });
    expect((await client.otp.send({ phone: '0812345678' })).codeType).toBe('unknown');
  });

  it('tolerates a response without the 1.2.0 fields (the SMS was sent: never fail after it)', async () => {
    const { client } = harness([
      json(201, { data: { otp_id: 'otp-1', expires_at: '2026-11-05T10:05:00Z', max_attempts: 3 } }),
    ]);

    const otp = await client.otp.send({ phone: '0812345678' });
    expect(otp).toMatchObject({ codeLength: null, codeType: null, resendAfterSeconds: null });
  });

  it('returns the test code in test mode, hidden from logs and JSON', async () => {
    const { client } = harness([sent({ test_code: '482913' })]);

    const otp = await client.otp.send({ phone: '0812345678' });

    expect(otp.testCode).toBe('482913');
    expect(JSON.stringify(otp)).not.toContain('482913');
    expect(inspect(otp)).not.toContain('482913');
  });

  it('sends the template, the locale, webOtpDomain (lower-cased) and stripAccents', async () => {
    const { client, requests } = harness([sent()]);

    await client.otp.send({
      phone: '+33 6 12 34 56 78',
      template: 'Your MyShop code: {code}',
      locale: 'en',
      webOtpDomain: 'App.Example.com',
      stripAccents: true,
    });

    expect(requests[0]!.json).toEqual({
      phone: '+33612345678',
      template: 'Your MyShop code: {code}',
      locale: 'en',
      web_otp_domain: 'app.example.com',
      strip_accents: true,
    });
  });

  it('keeps a foreign number given without "+" (11 digits or more)', async () => {
    const { client, requests } = harness([sent()]);
    await client.otp.send({ phone: '33612345678', stripAccents: false });
    expect(requests[0]!.json).toEqual({ phone: '+33612345678', strip_accents: false });
  });

  it.each([
    ['an invalid phone', { phone: 'abc' }, /Invalid phone number in "phone"/],
    ['a template without {code}', { phone: '0812345678', template: 'Hello' }, /\{code\}/],
    ['a template over 1600 characters', { phone: '0812345678', template: `{code}${'a'.repeat(1600)}` }, /at most 1600/],
    ['an unknown locale', { phone: '0812345678', locale: 'de' }, /locale/],
    ['a URL as webOtpDomain', { phone: '0812345678', webOtpDomain: 'https://app.example.com' }, /webOtpDomain/],
    ['a single-label webOtpDomain', { phone: '0812345678', webOtpDomain: 'localhost' }, /webOtpDomain/],
    ['a webOtpDomain with a port', { phone: '0812345678', webOtpDomain: 'example.com:443' }, /webOtpDomain/],
    ['a non-boolean stripAccents', { phone: '0812345678', stripAccents: 'yes' }, /stripAccents/],
    ['an empty idempotencyKey', { phone: '0812345678', idempotencyKey: '' }, /idempotencyKey/],
    ['an idempotencyKey over 255 characters', { phone: '0812345678', idempotencyKey: 'k'.repeat(256) }, /idempotencyKey/],
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
    const { client, requests } = harness([json(200, { data: { verified: true, reason: null, attempts_remaining: null } })]);

    const result = await client.otp.verify({ otpId: 'otp-1', code: '482913' });

    expect(requests[0]!.json).toEqual({ otp_id: 'otp-1', code: '482913' });
    expect(requests[0]!.headers['Idempotency-Key']).toBeUndefined();
    expect(result).toEqual({ verified: true, reason: null, attemptsRemaining: null });
  });

  it.each([
    ['invalid_code', 4],
    ['max_attempts_reached', 0],
    ['expired', null],
    ['already_used', null],
  ] as const)('returns verified: false with reason %s and attemptsRemaining %s (not an error)', async (reason, remaining) => {
    const { client } = harness([json(200, { data: { verified: false, reason, attempts_remaining: remaining } })]);
    expect(await client.otp.verify({ otpId: 'otp-1', code: '000000' })).toEqual({
      verified: false,
      reason,
      attemptsRemaining: remaining,
    });
  });

  it('attemptsRemaining is null with an API older than 1.2.0', async () => {
    const { client } = harness([json(200, { data: { verified: false, reason: 'invalid_code' } })]);
    expect(await client.otp.verify({ otpId: 'otp-1', code: '000000' })).toEqual({
      verified: false,
      reason: 'invalid_code',
      attemptsRemaining: null,
    });
  });

  it('maps an unknown reason to "unknown"', async () => {
    const { client } = harness([json(200, { data: { verified: false, reason: 'cosmic_ray', attempts_remaining: null } })]);
    expect(await client.otp.verify({ otpId: 'otp-1', code: '000000' })).toEqual({
      verified: false,
      reason: 'unknown',
      attemptsRemaining: null,
    });
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

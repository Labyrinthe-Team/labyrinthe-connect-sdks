/**
 * Nouveautés de la spec 1.2.0 côté SMS et transport : X-Request-Id, X-Api-Version,
 * onResponse, strip_accents, dates « +00:00 ».
 */
import { describe, expect, it } from 'vitest';
import { InvalidArgumentError, PhoneNumber, type ResponseInfo } from '../src/index.js';
import { apiError, harness, json, message, rejection } from './helpers.js';

const estimate = () =>
  json(200, { data: { encoding: 'gsm7', characters: 5, segments_per_message: 1, recipients_count: 1, total_credits: 1, balance_after: 9 } });
const balance = (headers: Record<string, string> = {}) => json(200, { data: { balance: 5, held: 0 } }, headers);

describe('X-Request-Id', () => {
  it('sends your requestId on every attempt of the call', async () => {
    const { client, requests } = harness([apiError(503, 'HTTP_ERROR'), balance()]);

    await client.balance.get({ requestId: 'order-1042.retry:1' });

    expect(requests).toHaveLength(2);
    expect(requests.map((r) => r.headers['X-Request-Id'])).toEqual(['order-1042.retry:1', 'order-1042.retry:1']);
  });

  it('sends no X-Request-Id by default (the API generates one)', async () => {
    const { client, requests } = harness([balance()]);
    await client.balance.get();
    expect(requests[0]!.headers['X-Request-Id']).toBeUndefined();
  });

  it.each([
    ['empty', ''],
    ['too long', 'a'.repeat(129)],
    ['with a space', 'order 1042'],
    ['with a slash', 'a/b'],
    ['not a string', 42],
  ])('refuses a requestId that the API would replace (%s), before any call', async (_label, requestId) => {
    const { client, requests } = harness([]);

    const error = await rejection(client.balance.get({ requestId: requestId as string }));

    expect(error).toBeInstanceOf(InvalidArgumentError);
    expect(requests).toHaveLength(0);
  });

  it('accepts 128 characters of the allowed alphabet', async () => {
    const { client, requests } = harness([balance()]);
    const id = 'Aa0._:-'.repeat(18).slice(0, 128);
    await client.balance.get({ requestId: id });
    expect(requests[0]!.headers['X-Request-Id']).toBe(id);
  });

  it('is on the errors (yours echoed by the API)', async () => {
    const { client } = harness([
      (request) => apiError(422, 'VALIDATION_ERROR', { body: ['x'] }, { 'X-Request-Id': request.headers['X-Request-Id'] ?? 'generated' }),
    ]);

    const error = (await rejection(client.sms.send({ to: '0812345678', body: 'Hi' }, { requestId: 'mine-1' }))) as {
      requestId: string;
    };
    expect(error.requestId).toBe('mine-1');
  });
});

describe('onResponse', () => {
  it('receives the request id, API version, rate limit and replay flag of the successful response', async () => {
    const seen: ResponseInfo[] = [];
    const { client } = harness([
      json(202, { data: [message()] }, {
        'X-Request-Id': 'req-9',
        'X-Api-Version': '1',
        'X-RateLimit-Limit': '120',
        'X-RateLimit-Remaining': '117',
        'Idempotent-Replayed': 'true',
      }),
    ]);

    const batch = await client.sms.sendMany({ recipients: ['0812345678'], body: 'Hi' }, { onResponse: (r) => seen.push(r) });

    expect(seen).toHaveLength(1);
    expect(seen[0]).toMatchObject({
      status: 202,
      requestId: 'req-9',
      apiVersion: '1',
      replayed: true,
      rateLimit: { limit: 120, remaining: 117 },
    });
    expect(seen[0]!.headers['x-request-id']).toBe('req-9');
    expect(batch.requestId).toBe('req-9');
    expect(batch.replayed).toBe(true);
  });

  it('gives nulls when the headers are absent', async () => {
    const seen: ResponseInfo[] = [];
    const { client } = harness([balance()]);
    await client.balance.get({ onResponse: (r) => seen.push(r) });
    expect(seen[0]).toMatchObject({ requestId: null, apiVersion: null, replayed: false, rateLimit: { limit: null, remaining: null } });
  });

  it('a throwing callback never hides the result (logged as a warning)', async () => {
    const { client, logs } = harness([balance({ 'X-Request-Id': 'r' })]);

    const result = await client.balance.get({
      onResponse: () => {
        throw new Error('boom');
      },
    });

    expect(result.balance).toBe(5);
    expect(logs.some((l) => l.level === 'warn' && l.message.includes('onResponse'))).toBe(true);
  });

  it('logs the request id of responses and errors', async () => {
    const { client, logs } = harness([balance({ 'X-Request-Id': 'req-ok' }), apiError(404, 'NOT_FOUND', {}, { 'X-Request-Id': 'req-ko' })]);

    await client.balance.get();
    await rejection(client.sms.get('nope'));

    const contexts = JSON.stringify(logs.map((l) => l.context));
    expect(contexts).toContain('req-ok');
    expect(contexts).toContain('req-ko');
  });
});

describe('stripAccents', () => {
  it('is sent with sms.send(), sms.sendMany() and sms.estimate() only when given', async () => {
    const { client, requests } = harness([
      json(202, { data: [message()] }),
      json(202, { data: [message()] }),
      estimate(),
      estimate(),
    ]);

    await client.sms.send({ to: '0812345678', body: 'Réunion à 9h', stripAccents: true });
    await client.sms.sendMany({ recipients: ['0812345678'], body: 'Réunion', stripAccents: false });
    await client.sms.estimate({ body: 'Réunion à 9h', recipients: ['0812345678'], stripAccents: true });
    await client.sms.estimate({ body: 'Hello', recipients: ['0812345678'] });

    expect(requests.map((r) => (r.json as Record<string, unknown>)['strip_accents'])).toEqual([true, false, true, undefined]);
  });

  it('refuses a non-boolean value before any call', async () => {
    const { client, requests } = harness([]);
    const params = { to: '0812345678', body: 'Hi', stripAccents: 1 } as unknown as Parameters<typeof client.sms.send>[0];
    await expect(client.sms.send(params)).rejects.toBeInstanceOf(InvalidArgumentError);
    expect(requests).toHaveLength(0);
  });
});

describe('phone numbers (spec 1.2.0)', () => {
  it('keeps a foreign number without "+" (11 digits or more), in sends, estimates and list filters', async () => {
    const { client, requests } = harness([
      json(202, { data: [message({ recipient: '33612345678' })] }),
      estimate(),
      json(200, { data: [], links: {}, meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    ]);

    await client.sms.send({ to: '33612345678', body: 'Hi' });
    await client.sms.estimate({ body: 'Hi', recipients: ['33 6 12 34 56 78'] });
    await client.sms.list({ recipient: '447911123456' });

    expect(requests[0]!.json).toMatchObject({ recipient: '+33612345678' });
    expect(requests[1]!.json).toMatchObject({ recipients: ['+33612345678'] });
    expect(new URL(requests[2]!.url).searchParams.get('recipient')).toBe('+447911123456');
  });

  // Sans « + », l'API lit « 2481234567 » (Seychelles, 10 chiffres) comme local RDC.
  it('sends E.164 numbers with "+" so that a short foreign number keeps its country code (1.1.1)', async () => {
    const { client, requests } = harness([
      json(202, { data: [message({ recipient: '2481234567' })] }),
      json(202, { data: [message({ recipient: '2481234567' }), message({ id: 'm-2', recipient: '243812345678' })] }),
      estimate(),
      json(200, { data: [], links: {}, meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
      json(201, { data: { otp_id: 'otp-1', expires_at: '2026-11-06T10:00:00Z', max_attempts: 5 } }),
    ]);

    const sent = await client.sms.send({ to: '+2481234567', body: 'Hi' });
    await client.sms.sendMany({ recipients: ['+2481234567', '0812345678'], body: 'Hi' });
    await client.sms.estimate({ body: 'Hi', recipients: ['+248 123 4567', '0812345678'] });
    await client.sms.list({ recipient: '+2481234567' });
    await client.otp.send({ phone: '0812345678' });

    expect(requests[0]!.json).toMatchObject({ recipient: '+2481234567' });
    expect(requests[1]!.json).toMatchObject({ recipients: ['+2481234567', '+243812345678'] });
    expect(requests[2]!.json).toMatchObject({ recipients: ['+2481234567', '+243812345678'] });
    expect(new URL(requests[3]!.url).searchParams.get('recipient')).toBe('+2481234567');
    expect(requests[4]!.json).toMatchObject({ phone: '+243812345678' });
    // Les réponses et PhoneNumber.normalize() restent sans « + ».
    expect(sent.recipient).toBe('2481234567');
    expect(PhoneNumber.normalize('+2481234567')).toBe('2481234567');
  });
});

describe('dates', () => {
  it('reads "+00:00" offsets (spec 1.2.0) and "Z" alike', async () => {
    const { client } = harness([
      json(200, { data: message({ created_at: '2026-11-06T09:39:11+00:00', delivered_at: '2026-11-06T09:40:00Z' }) }),
    ]);

    const m = await client.sms.get('9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10');

    expect(m.createdAt).toEqual(new Date('2026-11-06T09:39:11Z'));
    expect(m.deliveredAt).toEqual(new Date('2026-11-06T09:40:00Z'));
  });
});

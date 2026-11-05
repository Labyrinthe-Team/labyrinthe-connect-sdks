import { inspect } from 'node:util';
import { describe, expect, it } from 'vitest';
import { LabyrintheError } from '../src/index.js';
import { SECRET_PART, apiError, harness, json, message, networkError, page, rejection } from './helpers.js';

/** Everything a developer might print. */
function dumps(value: unknown): string {
  return [inspect(value, { depth: 10, showHidden: true }), (JSON.stringify(value) as string | undefined) ?? '', String(value)].join('\n');
}

describe('secrets never leak', () => {
  it('the key is hidden from inspect() and JSON.stringify() of the client and its resources', () => {
    const { client } = harness([]);

    for (const object of [client, client.sms, client.otp, client.balance, client.senderIds]) {
      expect(dumps(object)).not.toContain(SECRET_PART);
    }
    expect(JSON.stringify(client)).toBe(
      JSON.stringify({ apiKey: 'lsk_test_****', baseUrl: 'https://api.test/api/public/v1', testMode: true }),
    );
    expect(inspect(client)).toContain('lsk_test_****');
    // Pas de propriété énumérable qui porterait la clé.
    expect(Object.values(client).some((v) => typeof v === 'string' && v.includes(SECRET_PART))).toBe(false);
  });

  it('a page never shows the key', async () => {
    const { client } = harness([page([message()], { current_page: 1, last_page: 1, total: 1 })]);
    expect(dumps(await client.sms.list())).not.toContain(SECRET_PART);
  });

  it('the test code is hidden from inspect() and JSON.stringify()', async () => {
    const { client } = harness([
      json(201, { data: { otp_id: 'otp-1', expires_at: '2026-11-05T10:05:00Z', max_attempts: 3, test_code: '482913' } }),
    ]);

    const otp = await client.otp.send({ phone: '0812345678' });

    expect(otp.testCode).toBe('482913');
    expect(dumps(otp)).not.toContain('482913');
    expect(inspect(otp)).toContain('[redacted]');
    expect(JSON.parse(JSON.stringify(otp))).toEqual({
      otpId: 'otp-1',
      expiresAt: '2026-11-05T10:05:00.000Z',
      maxAttempts: 3,
    });
    expect(Object.keys(otp)).not.toContain('testCode');
  });

  it('errors never contain the key, the body or the code', async () => {
    const { client } = harness(
      [apiError(401, 'UNAUTHENTICATED'), networkError(), apiError(500, 'HTTP_ERROR')],
      { maxRetries: 0 },
    );

    const errors = [
      await rejection(client.balance.get()),
      await rejection(client.sms.send({ to: '0812345678', body: 'Votre code est 771122' })),
      await rejection(client.otp.verify({ otpId: 'otp-1', code: '482913' })),
    ];

    for (const error of errors) {
      expect(error).toBeInstanceOf(LabyrintheError);
      expect((error as Error).cause).toBeUndefined();
      const text = dumps(error) + String((error as Error).stack);
      expect(text).not.toContain(SECRET_PART);
      expect(text).not.toContain('771122');
      expect(text).not.toContain('482913');
    }
  });

  it('logs contain no key, no code, no body and only masked numbers', async () => {
    const { client, logs } = harness(
      [
        json(202, { data: [message()] }),
        page([], { current_page: 1, last_page: 1, total: 0 }),
        json(201, { data: { otp_id: 'otp-1', expires_at: '2026-11-05T10:05:00Z', max_attempts: 3, test_code: '482913' } }),
        apiError(503, 'HTTP_ERROR'),
        networkError(),
        json(200, { data: { balance: 1, held: 0 } }),
      ],
      { maxRetries: 0 },
    );

    await client.sms.send({ to: '0812345678', body: 'Votre code est 771122' });
    await client.sms.list({ recipient: '0812345678' });
    await client.otp.send({ phone: '0812345678' });
    await rejection(client.otp.verify({ otpId: 'otp-1', code: '482913' }));
    await rejection(client.balance.get());

    const text = JSON.stringify(logs);
    expect(logs.length).toBeGreaterThan(5);
    expect(logs.map((l) => l.level)).toEqual(expect.arrayContaining(['debug', 'info', 'error']));
    expect(text).not.toContain(SECRET_PART);
    expect(text).not.toContain('482913');
    expect(text).not.toContain('771122');
    expect(text).not.toContain('812345678');
    expect(text).toContain('recipient=24381*****78');
  });

  it('a failing logger never breaks a call', async () => {
    const { client } = harness([json(200, { data: { balance: 1, held: 0 } })], {
      logger: {
        debug: () => {
          throw new Error('logger down');
        },
      },
    });
    await expect(client.balance.get()).resolves.toEqual({ balance: 1, held: 0 });
  });
});

import { describe, expect, it } from 'vitest';
import {
  ConnectionError,
  IdempotencyKeyReusedError,
  IdempotencyKeyUnresolvedError,
  IdempotencyRequestInProgressError,
  Labyrinthe,
  OtpRateLimitedError,
  OtpResendTooSoonError,
  RateLimitError,
  RequestAbortedError,
  ServiceUnavailableError,
  TimeoutError,
  ValidationError,
} from '../src/index.js';
import type { FetchInit, FetchResponse } from '../src/internal/transport.js';
import { TEST_KEY, apiError, harness, json, message, networkError, rejection } from './helpers.js';

const balance = () => json(200, { data: { balance: 5, held: 0 } });
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
const inProgress = (seconds = 5) =>
  apiError(409, 'IDEMPOTENCY_REQUEST_IN_PROGRESS', { retry_after_seconds: seconds }, { 'Retry-After': String(seconds) });

/** A fetch that never answers until its signal aborts (like a real hanging request). */
function hangingFetch(calls: { count: number }) {
  return (_url: string, init: FetchInit): Promise<FetchResponse> =>
    new Promise((_resolve, reject) => {
      calls.count++;
      init.signal.addEventListener('abort', () => {
        reject(new DOMException('This operation was aborted', 'AbortError'));
      });
    });
}

describe('retries', () => {
  it('retries a GET on 5xx, then succeeds', async () => {
    const { client, requests, sleeps } = harness([apiError(503, 'HTTP_ERROR'), apiError(500, 'HTTP_ERROR'), balance()]);

    expect((await client.balance.get()).balance).toBe(5);
    expect(requests).toHaveLength(3);
    expect(sleeps).toHaveLength(2);
  });

  it('stops after maxRetries', async () => {
    const { client, requests } = harness(Array.from({ length: 5 }, () => apiError(503, 'HTTP_ERROR')), { maxRetries: 2 });

    await expect(client.balance.get()).rejects.toBeInstanceOf(ServiceUnavailableError);
    expect(requests).toHaveLength(3);
  });

  it('maxRetries: 0 disables retries', async () => {
    const { client, requests } = harness([apiError(503, 'HTTP_ERROR')], { maxRetries: 0 });

    await expect(client.balance.get()).rejects.toBeInstanceOf(ServiceUnavailableError);
    expect(requests).toHaveLength(1);
  });

  it('retries a GET on network errors', async () => {
    const { client, requests } = harness([networkError(), balance()]);

    expect((await client.balance.get()).balance).toBe(5);
    expect(requests).toHaveLength(2);
  });

  it('retries estimate()', async () => {
    const { client, requests } = harness([
      networkError(),
      apiError(502, 'HTTP_ERROR'),
      json(200, {
        data: { encoding: 'gsm7', characters: 5, segments_per_message: 1, recipients_count: 1, total_credits: 1, balance_after: 9 },
      }),
    ]);

    expect((await client.sms.estimate({ body: 'Hello', recipients: '0812345678' })).totalCredits).toBe(1);
    expect(requests).toHaveLength(3);
  });

  it('retries a send with the same Idempotency-Key and the same body', async () => {
    const { client, requests } = harness([
      networkError('ETIMEDOUT'),
      apiError(500, 'HTTP_ERROR'),
      json(202, { data: [message()] }, { 'Idempotent-Replayed': 'true' }),
    ]);

    await client.sms.send({ to: '0812345678', body: 'Hello' });

    expect(requests).toHaveLength(3);
    const keys = requests.map((r) => r.headers['Idempotency-Key']);
    expect(keys[0]).toBeTruthy();
    expect(keys).toEqual([keys[0], keys[0], keys[0]]);
    expect(requests[2]!.body).toBe(requests[0]!.body);
  });

  it('waits for Retry-After on 429 TOO_MANY_REQUESTS', async () => {
    const { client, sleeps } = harness([
      apiError(429, 'TOO_MANY_REQUESTS', { retry_after_seconds: 3 }, { 'Retry-After': '3' }),
      balance(),
    ]);

    await client.balance.get();
    expect(sleeps).toEqual([3000]);
  });

  it('uses details.retry_after_seconds without the header', async () => {
    const { client, sleeps } = harness([apiError(429, 'TOO_MANY_REQUESTS', { retry_after_seconds: 4 }), balance()]);

    await client.balance.get();
    expect(sleeps).toEqual([4000]);
  });

  it('accepts an HTTP-date Retry-After', async () => {
    const at = new Date(Date.now() + 2000).toUTCString();
    const { client, sleeps } = harness([apiError(429, 'TOO_MANY_REQUESTS', {}, { 'Retry-After': at }), balance()]);

    await client.balance.get();
    expect(sleeps[0]).toBeGreaterThanOrEqual(0);
    expect(sleeps[0]).toBeLessThanOrEqual(2000);
  });

  it('does not wait when Retry-After exceeds maxRetryWaitMs', async () => {
    const { client, requests, sleeps } = harness([apiError(429, 'TOO_MANY_REQUESTS', {}, { 'Retry-After': '45' })], {
      maxRetryWaitMs: 10_000,
    });

    const error = (await rejection(client.balance.get())) as RateLimitError;
    expect(error).toBeInstanceOf(RateLimitError);
    expect(error.retryAfterMs).toBe(45_000);
    expect(requests).toHaveLength(1);
    expect(sleeps).toEqual([]);
  });

  it('backs off exponentially with jitter, capped by maxRetryWaitMs', async () => {
    const { client, sleeps } = harness(Array.from({ length: 4 }, () => apiError(503, 'HTTP_ERROR')), { maxRetries: 3 });

    await rejection(client.balance.get());

    expect(sleeps).toHaveLength(3);
    [500, 1000, 2000].forEach((base, i) => {
      expect(sleeps[i]).toBeGreaterThanOrEqual(base * 0.5);
      expect(sleeps[i]).toBeLessThanOrEqual(base);
    });

    const capped = harness(Array.from({ length: 3 }, () => apiError(503, 'HTTP_ERROR')), { maxRetryWaitMs: 100 });
    await rejection(capped.client.balance.get());
    expect(capped.sleeps.every((ms) => ms <= 100)).toBe(true);
  });

  it('never retries client errors', async () => {
    const { client, requests, sleeps } = harness([
      apiError(422, 'VALIDATION_ERROR', { body: ['x'] }),
      apiError(409, 'IDEMPOTENCY_KEY_REUSED'),
    ]);

    await expect(client.sms.send({ to: '0812345678', body: 'Hello' })).rejects.toBeInstanceOf(ValidationError);
    await expect(client.sms.send({ to: '0812345678', body: 'Hello' })).rejects.toBeInstanceOf(IdempotencyKeyReusedError);
    expect(requests).toHaveLength(2);
    expect(sleeps).toEqual([]);
  });

  it('retries otp.send() on 5xx and network errors with the same Idempotency-Key and body', async () => {
    const { client, requests } = harness([apiError(500, 'HTTP_ERROR'), networkError(), otpSent()]);

    expect((await client.otp.send({ phone: '0812345678' })).otpId).toBe('otp-1');

    expect(requests).toHaveLength(3);
    const keys = requests.map((r) => r.headers['Idempotency-Key']);
    expect(keys[0]).toBeTruthy();
    expect(keys).toEqual([keys[0], keys[0], keys[0]]);
    expect(requests[2]!.body).toBe(requests[0]!.body);
  });

  it('after the last retry of otp.send(), says that calling again with the same key is safe', async () => {
    const { client, requests } = harness([networkError(), networkError(), networkError()]);

    const error = (await rejection(client.otp.send({ phone: '0812345678', idempotencyKey: 'login-42' }))) as ConnectionError;
    expect(error).toBeInstanceOf(ConnectionError);
    expect(error.code).toBe('CONNECTION_ERROR');
    expect(error.status).toBeNull();
    expect(error.message).toContain('ECONNREFUSED');
    expect(error.message).toContain('same idempotencyKey is safe');
    expect(requests).toHaveLength(3);
    expect(requests.every((r) => r.headers['Idempotency-Key'] === 'login-42')).toBe(true);
  });

  it.each([
    ['sms.send()', (c: Labyrinthe) => c.sms.send({ to: '0812345678', body: 'Hello' }), () => json(202, { data: [message()] })],
    ['otp.send()', (c: Labyrinthe) => c.otp.send({ phone: '0812345678' }), otpSent],
  ])('retries %s on 409 IDEMPOTENCY_REQUEST_IN_PROGRESS after Retry-After, with the same key', async (_label, call, ok) => {
    const { client, requests, sleeps } = harness([inProgress(5), ok()]);

    await call(client);

    expect(sleeps).toEqual([5000]);
    expect(requests).toHaveLength(2);
    expect(requests[1]!.headers['Idempotency-Key']).toBe(requests[0]!.headers['Idempotency-Key']);
  });

  it('throws IdempotencyRequestInProgressError when it lasts, or when Retry-After exceeds maxRetryWaitMs', async () => {
    const lasting = harness([inProgress(), inProgress(), inProgress()]);
    const error = (await rejection(lasting.client.otp.send({ phone: '0812345678' }))) as IdempotencyRequestInProgressError;
    expect(error).toBeInstanceOf(IdempotencyRequestInProgressError);
    expect(error.status).toBe(409);
    expect(error.retryAfterMs).toBe(5000);
    expect(lasting.requests).toHaveLength(3);

    const tooLong = harness([inProgress(30)], { maxRetryWaitMs: 10_000 });
    await expect(tooLong.client.sms.send({ to: '0812345678', body: 'Hello' })).rejects.toBeInstanceOf(
      IdempotencyRequestInProgressError,
    );
    expect(tooLong.requests).toHaveLength(1);
    expect(tooLong.sleeps).toEqual([]);
  });

  it.each([
    ['sms.send()', (c: Labyrinthe) => c.sms.send({ to: '0812345678', body: 'Hello', idempotencyKey: 'order-42' })],
    ['otp.send()', (c: Labyrinthe) => c.otp.send({ phone: '0812345678', idempotencyKey: 'order-42' })],
  ])('never retries %s on 409 IDEMPOTENCY_KEY_UNRESOLVED (an SMS may have been sent)', async (_label, call) => {
    const unresolved = () =>
      apiError(409, 'IDEMPOTENCY_KEY_UNRESOLVED', {}, { 'Retry-After': '1' });
    const { client, requests, sleeps } = harness([unresolved(), unresolved(), unresolved()]);

    const error = (await rejection(call(client))) as IdempotencyKeyUnresolvedError;
    expect(error).toBeInstanceOf(IdempotencyKeyUnresolvedError);
    expect(error.code).toBe('IDEMPOTENCY_KEY_UNRESOLVED');
    expect(error.status).toBe(409);
    expect(requests).toHaveLength(1);
    expect(sleeps).toEqual([]);
  });

  it('never retries otp.verify() on 409 IDEMPOTENCY_REQUEST_IN_PROGRESS', async () => {
    const { client, requests } = harness([inProgress(), verified()]);
    await expect(client.otp.verify({ otpId: 'otp-1', code: '123456' })).rejects.toBeInstanceOf(
      IdempotencyRequestInProgressError,
    );
    expect(requests).toHaveLength(1);
  });

  it('retries otp.send() on the generic 429 only', async () => {
    const { client, requests } = harness([apiError(429, 'TOO_MANY_REQUESTS', {}, { 'Retry-After': '1' }), otpSent()]);

    expect((await client.otp.send({ phone: '0812345678' })).otpId).toBe('otp-1');
    expect(requests).toHaveLength(2);
  });

  it('never retries OTP business limits', async () => {
    const { client, requests, sleeps } = harness([
      apiError(429, 'OTP_RESEND_TOO_SOON', { retry_after_seconds: 1 }),
      apiError(429, 'OTP_RATE_LIMITED', { scope: 'account', limit: 100 }),
      otpSent(),
    ]);

    await expect(client.otp.send({ phone: '0812345678' })).rejects.toBeInstanceOf(OtpResendTooSoonError);
    await expect(client.otp.send({ phone: '0812345678' })).rejects.toBeInstanceOf(OtpRateLimitedError);
    expect(requests).toHaveLength(2);
    expect(sleeps).toEqual([]);
  });

  it('never retries otp.verify() on 5xx or network errors', async () => {
    const { client, requests } = harness([apiError(503, 'HTTP_ERROR'), networkError(), verified()]);

    await expect(client.otp.verify({ otpId: 'otp-1', code: '123456' })).rejects.toBeInstanceOf(ServiceUnavailableError);
    await expect(client.otp.verify({ otpId: 'otp-1', code: '123456' })).rejects.toBeInstanceOf(ConnectionError);
    expect(requests).toHaveLength(2);
  });

  it('retries otp.verify() on the generic 429', async () => {
    const { client, requests } = harness([apiError(429, 'TOO_MANY_REQUESTS'), verified()]);

    expect((await client.otp.verify({ otpId: 'otp-1', code: '123456' })).verified).toBe(true);
    expect(requests).toHaveLength(2);
  });

  it('removes query strings from network error messages', async () => {
    const { client } = harness(
      [new TypeError('getaddrinfo ENOTFOUND https://api.test/api/public/v1/sms?recipient=243812345678')],
      { maxRetries: 0 },
    );

    const error = (await rejection(client.sms.list({ recipient: '0812345678' }))) as ConnectionError;
    expect(error).toBeInstanceOf(ConnectionError);
    expect(error.message).not.toContain('243812345678');
  });
});

describe('timeouts and cancellation', () => {
  it('times out each attempt, retries safe calls, then throws TimeoutError', async () => {
    const calls = { count: 0 };
    const sleeps: number[] = [];
    const client = new Labyrinthe({
      apiKey: TEST_KEY,
      baseUrl: 'https://api.test',
      fetch: hangingFetch(calls),
      timeoutMs: 20,
      maxRetries: 1,
      sleep: (ms) => {
        sleeps.push(ms);
        return Promise.resolve();
      },
    });

    const error = (await rejection(client.balance.get())) as TimeoutError;

    expect(error).toBeInstanceOf(TimeoutError);
    expect(error).toBeInstanceOf(ConnectionError);
    expect(error.code).toBe('TIMEOUT');
    expect(error.message).toContain('20 ms');
    expect(calls.count).toBe(2);
    expect(sleeps).toHaveLength(1);
  });

  it('retries otp.send() after a timeout (Idempotency-Key), but never otp.verify()', async () => {
    const calls = { count: 0 };
    const client = new Labyrinthe({
      apiKey: TEST_KEY,
      baseUrl: 'https://api.test',
      fetch: hangingFetch(calls),
      timeoutMs: 20,
      maxRetries: 1,
      sleep: () => Promise.resolve(),
    });

    const sendError = (await rejection(client.otp.send({ phone: '0812345678' }))) as TimeoutError;
    expect(sendError).toBeInstanceOf(TimeoutError);
    expect(sendError.message).toContain('same idempotencyKey is safe');
    expect(calls.count).toBe(2);

    const verifyError = (await rejection(client.otp.verify({ otpId: 'otp-1', code: '123456' }))) as TimeoutError;
    expect(verifyError).toBeInstanceOf(TimeoutError);
    expect(verifyError.message).toContain('may have been processed');
    expect(calls.count).toBe(3);
  });


  it('accepts a per-call timeout', async () => {
    const calls = { count: 0 };
    const client = new Labyrinthe({
      apiKey: TEST_KEY,
      baseUrl: 'https://api.test',
      fetch: hangingFetch(calls),
      timeoutMs: 60_000,
      maxRetries: 0,
    });

    await expect(client.balance.get({ timeoutMs: 10 })).rejects.toBeInstanceOf(TimeoutError);
  });

  it('aborts an in-flight request with the signal, without retrying', async () => {
    const calls = { count: 0 };
    const client = new Labyrinthe({ apiKey: TEST_KEY, baseUrl: 'https://api.test', fetch: hangingFetch(calls) });
    const controller = new AbortController();

    const pending = client.sms.send({ to: '0812345678', body: 'Hello' }, { signal: controller.signal });
    setTimeout(() => {
      controller.abort();
    }, 10);

    const error = (await rejection(pending)) as RequestAbortedError;
    expect(error).toBeInstanceOf(RequestAbortedError);
    expect(error.code).toBe('REQUEST_ABORTED');
    expect(calls.count).toBe(1);
  });

  it('does not send anything with an already aborted signal', async () => {
    const { client, requests } = harness([balance()]);
    const controller = new AbortController();
    controller.abort();

    await expect(client.balance.get({ signal: controller.signal })).rejects.toBeInstanceOf(RequestAbortedError);
    expect(requests).toHaveLength(0);
  });

  it('aborts while waiting between retries', async () => {
    const controller = new AbortController();
    const client = new Labyrinthe({
      apiKey: TEST_KEY,
      baseUrl: 'https://api.test',
      fetch: () => Promise.resolve(apiError(503, 'HTTP_ERROR')),
      maxRetryWaitMs: 60_000,
    });

    const pending = client.balance.get({ signal: controller.signal });
    setTimeout(() => {
      controller.abort();
    }, 20);

    await expect(pending).rejects.toBeInstanceOf(RequestAbortedError);
  });
});

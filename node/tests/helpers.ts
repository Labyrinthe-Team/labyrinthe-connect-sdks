import { Labyrinthe, type LabyrintheOptions } from '../src/index.js';
import type { FetchInit, FetchResponse } from '../src/internal/transport.js';

export const TEST_KEY = 'lsk_test_SECRETPART0123456789';
export const SECRET_PART = 'SECRETPART';

export interface RecordedRequest {
  url: string;
  method: string;
  headers: Record<string, string>;
  body: string | undefined;
  json: unknown;
  signal: AbortSignal;
}

/** A queued answer: a response, an error thrown by fetch, or a function of the request. */
export type MockAnswer = FetchResponse | Error | ((request: RecordedRequest) => Promise<FetchResponse> | FetchResponse);

export function json(status: number, body: unknown, headers: Record<string, string> = {}): FetchResponse {
  return response(status, JSON.stringify(body), { 'content-type': 'application/json', ...headers });
}

export function response(status: number, text: string, headers: Record<string, string> = {}): FetchResponse {
  return {
    status,
    headers: {
      forEach(callback) {
        for (const [key, value] of Object.entries(headers)) callback(value, key);
      },
    },
    text: () => Promise.resolve(text),
  };
}

export function apiError(
  status: number,
  code: string,
  details: Record<string, unknown> | unknown[] = {},
  headers: Record<string, string> = {},
): FetchResponse {
  return json(status, { error: { code, message: `Erreur ${code}`, details } }, headers);
}

/** An Undici-like network error ("fetch failed" with a cause code). */
export function networkError(code = 'ECONNREFUSED', message = 'fetch failed'): Error {
  const error = new TypeError(message);
  (error as { cause?: unknown }).cause = Object.assign(new Error(`connect ${code}`), { code });
  return error;
}

/** Message exactly as described by components.schemas.Message (every required field). */
export function message(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10',
    reference: 'order-1042',
    recipient: '243812345678',
    sender: 'MYSHOP',
    encoding: 'gsm7',
    status: 'queued',
    segments: 1,
    credits_charged: 1,
    campaign_id: null,
    provider_message_id: null,
    error_code: null,
    error_message: null,
    created_at: '2026-11-05T10:00:00+00:00',
    submitted_at: null,
    delivered_at: null,
    ...overrides,
  };
}

export function page(
  data: unknown[],
  meta: { current_page: number; last_page: number; per_page?: number; total: number },
): FetchResponse {
  return json(200, {
    data,
    links: { first: null, last: null, prev: null, next: null },
    meta: { from: 1, to: data.length, path: 'http://api.test/api/public/v1/sms', per_page: 15, ...meta },
  });
}

export interface Harness {
  client: Labyrinthe;
  requests: RecordedRequest[];
  sleeps: number[];
  logs: { level: string; message: string; context: unknown }[];
}

/** A client whose fetch answers from a queue; waits between retries are recorded, not slept. */
export function harness(answers: MockAnswer[], options: Partial<LabyrintheOptions> = {}): Harness {
  const queue = [...answers];
  const requests: RecordedRequest[] = [];
  const sleeps: number[] = [];
  const logs: Harness['logs'] = [];

  const fetch = async (url: string, init: FetchInit): Promise<FetchResponse> => {
    const request: RecordedRequest = {
      url,
      method: init.method,
      headers: init.headers,
      body: init.body,
      json: init.body === undefined ? undefined : JSON.parse(init.body),
      signal: init.signal,
    };
    requests.push(request);
    const answer = queue.shift();
    if (answer === undefined) {
      throw new Error(`Unexpected request #${String(requests.length)}: ${init.method} ${url}`);
    }
    if (answer instanceof Error) {
      throw answer;
    }
    return typeof answer === 'function' ? answer(request) : answer;
  };

  const logger = Object.fromEntries(
    (['debug', 'info', 'warn', 'error'] as const).map((level) => [
      level,
      (message: string, context?: unknown) => logs.push({ level, message, context }),
    ]),
  );

  const client = new Labyrinthe({
    apiKey: TEST_KEY,
    baseUrl: 'https://api.test/api/public/v1',
    fetch,
    logger,
    sleep: (ms) => {
      sleeps.push(ms);
      return Promise.resolve();
    },
    ...options,
  });

  return { client, requests, sleeps, logs };
}

/** Awaits a promise expected to reject and returns the error. */
export async function rejection(promise: PromiseLike<unknown>): Promise<unknown> {
  try {
    await promise;
  } catch (error) {
    return error;
  }
  throw new Error('The promise was expected to reject.');
}

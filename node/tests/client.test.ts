import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { DEFAULT_BASE_URL, InvalidArgumentError, Labyrinthe, VERSION } from '../src/index.js';
import { TEST_KEY, harness, json } from './helpers.js';

const balance = () => json(200, { data: { balance: 120, held: 5 } });

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe('Labyrinthe client', () => {
  it('requires an API key', () => {
    vi.stubEnv('LABYRINTHE_API_KEY', '');
    expect(() => new Labyrinthe()).toThrow(InvalidArgumentError);
    expect(() => new Labyrinthe({ apiKey: '   ' })).toThrow(/API key is required/);
  });

  it('reads LABYRINTHE_API_KEY and LABYRINTHE_BASE_URL from the environment', () => {
    vi.stubEnv('LABYRINTHE_API_KEY', TEST_KEY);
    vi.stubEnv('LABYRINTHE_BASE_URL', 'http://localhost:8000');
    const client = new Labyrinthe();
    expect(client.isTestMode).toBe(true);
    expect(client.baseUrl).toBe('http://localhost:8000/api/public/v1');
  });

  it('detects the mode from the key prefix', () => {
    expect(new Labyrinthe({ apiKey: 'lsk_test_x' }).isTestMode).toBe(true);
    expect(new Labyrinthe({ apiKey: 'lsk_test_x' }).isLiveMode).toBe(false);
    expect(new Labyrinthe({ apiKey: 'lsk_live_x' }).isLiveMode).toBe(true);
    expect(new Labyrinthe({ apiKey: 'lsk_live_x' }).isTestMode).toBe(false);
  });

  it('normalises the base URL', () => {
    vi.stubEnv('LABYRINTHE_BASE_URL', '');
    expect(new Labyrinthe({ apiKey: TEST_KEY }).baseUrl).toBe(DEFAULT_BASE_URL);
    expect(new Labyrinthe({ apiKey: TEST_KEY, baseUrl: 'http://localhost:8000/' }).baseUrl).toBe(
      'http://localhost:8000/api/public/v1',
    );
    expect(new Labyrinthe({ apiKey: TEST_KEY, baseUrl: 'https://x.test/custom/v1/' }).baseUrl).toBe(
      'https://x.test/custom/v1',
    );
    expect(() => new Labyrinthe({ apiKey: TEST_KEY, baseUrl: 'ftp://x.test' })).toThrow(InvalidArgumentError);
    expect(() => new Labyrinthe({ apiKey: TEST_KEY, baseUrl: 'not a url' })).toThrow(InvalidArgumentError);
  });

  it('validates its options', () => {
    expect(() => new Labyrinthe({ apiKey: TEST_KEY, timeoutMs: 0 })).toThrow(InvalidArgumentError);
    expect(() => new Labyrinthe({ apiKey: TEST_KEY, maxRetries: -1 })).toThrow(InvalidArgumentError);
    expect(() => new Labyrinthe({ apiKey: TEST_KEY, maxRetries: 11 })).toThrow(InvalidArgumentError);
    expect(() => new Labyrinthe({ apiKey: TEST_KEY, maxRetries: 1.5 })).toThrow(InvalidArgumentError);
    expect(() => new Labyrinthe({ apiKey: TEST_KEY, maxRetryWaitMs: -1 })).toThrow(InvalidArgumentError);
    expect(() => new Labyrinthe({ apiKey: TEST_KEY, defaultCountryCode: '+243' })).toThrow(InvalidArgumentError);
  });

  it('refuses to run in a browser unless explicitly allowed', () => {
    vi.stubGlobal('window', { document: {} });
    expect(() => new Labyrinthe({ apiKey: TEST_KEY })).toThrow(/server-side/);
    expect(new Labyrinthe({ apiKey: TEST_KEY, dangerouslyAllowBrowser: true }).isTestMode).toBe(true);
  });

  it('sends the key, Accept and the SDK User-Agent', async () => {
    const { client, requests } = harness([balance()]);

    await client.balance.get();

    const headers = requests[0]!.headers;
    expect(headers['Authorization']).toBe(`Bearer ${TEST_KEY}`);
    expect(headers['Accept']).toBe('application/json');
    expect(headers['User-Agent']).toMatch(new RegExp(`^labyrinthe-node/${VERSION.replace(/\./g, '\\.')} node/`));
    expect(headers['X-Labyrinthe-Client']).toBeUndefined();
    expect(requests[0]!.url).toBe('https://api.test/api/public/v1/balance');
  });

  it('identifies itself with X-Labyrinthe-Client where User-Agent is forbidden', async () => {
    vi.stubGlobal('window', { document: {} });
    const { client, requests } = harness([balance()], { dangerouslyAllowBrowser: true });

    await client.balance.get();

    expect(requests[0]!.headers['X-Labyrinthe-Client']).toMatch(/^labyrinthe-node\//);
    expect(requests[0]!.headers['User-Agent']).toBeUndefined();
  });

  it('uses the global fetch by default, called on globalThis', async () => {
    const fetchSpy = vi.fn(function (this: unknown) {
      expect(this === undefined || this === globalThis).toBe(true);
      return Promise.resolve(new Response(JSON.stringify({ data: { balance: 1, held: 0 } }), { status: 200 }));
    });
    vi.stubGlobal('fetch', fetchSpy);

    const client = new Labyrinthe({ apiKey: TEST_KEY, baseUrl: 'https://api.test' });
    expect(await client.balance.get()).toEqual({ balance: 1, held: 0 });
    expect(fetchSpy).toHaveBeenCalledOnce();
    const [url, init] = fetchSpy.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('https://api.test/api/public/v1/balance');
    expect(init.redirect).toBe('manual');
  });

  it('keeps VERSION in sync with package.json', () => {
    const pkg = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8')) as { version: string };
    expect(VERSION).toBe(pkg.version);
  });
});

describe('balance and sender IDs', () => {
  it('reads the balance', async () => {
    const { client } = harness([balance()]);
    expect(await client.balance.get()).toEqual({ balance: 120, held: 5 });
  });

  it('lists approved sender IDs in camelCase with dates', async () => {
    const { client } = harness([
      json(200, {
        data: [
          {
            id: 's-1',
            value: 'MYSHOP',
            status: 'approved',
            purpose: 'Orders',
            rejection_reason: null,
            is_default: true,
            created_at: '2026-10-01T08:00:00+00:00',
          },
          { id: 's-2', value: 'OTHER', status: 'brand_new_status', is_default: false },
        ],
      }),
    ]);

    const senderIds = await client.senderIds.list();

    expect(senderIds[0]).toEqual({
      id: 's-1',
      value: 'MYSHOP',
      status: 'approved',
      purpose: 'Orders',
      rejectionReason: null,
      isDefault: true,
      createdAt: new Date('2026-10-01T08:00:00Z'),
    });
    expect(senderIds[1]!.status).toBe('unknown');
    expect(senderIds[1]!.createdAt).toBeNull();
  });
});

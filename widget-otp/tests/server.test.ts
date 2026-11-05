import express from 'express';
import type { AddressInfo } from 'node:net';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Labyrinthe } from '@labyrinthe/connect';
import { createOtpHandlers, memoryStore, type OtpHandlers } from '../src/server/index.js';
import { SECRET, cookiePair, fakeClient, handlers, sdkErrors } from './helpers.js';

const ORIGIN = 'https://shop.example';

function post(path: string, body: unknown, init: { cookie?: string | undefined; headers?: Record<string, string> } = {}): Request {
  return new Request(`${ORIGIN}${path}`, {
    method: 'POST',
    headers: {
      'content-type': 'application/json',
      origin: ORIGIN,
      host: 'shop.example',
      ...(init.cookie === undefined ? {} : { cookie: init.cookie }),
      ...init.headers,
    },
    body: typeof body === 'string' ? body : JSON.stringify(body),
  });
}

/** Navigateur minimal : garde le cookie de session entre les appels. */
function session(otp: OtpHandlers) {
  // Seul le cookie du widget nous intéresse (onVerified peut en poser d'autres).
  let cookie: string | undefined;
  const call = async (kind: 'send' | 'verify', body: unknown, headers?: Record<string, string>) => {
    const response = await otp[kind](post(`/api/otp/${kind}`, body, { cookie, ...(headers ? { headers } : {}) }));
    for (const setCookie of response.headers.getSetCookie()) {
      const [name, value] = cookiePair(setCookie);
      if (name === 'labyrinthe_otp') cookie = value === null ? undefined : `${name}=${value}`;
    }
    const text = await response.text();
    return { response, text, json: JSON.parse(text) as Record<string, any> };
  };
  return { call, cookie: () => cookie, setCookie: (value: string | undefined) => (cookie = value) };
}

afterEach(() => {
  vi.unstubAllEnvs();
  vi.useRealTimers();
});

describe('configuration', () => {
  it('requires a secret of at least 32 characters and onVerified', () => {
    const client = fakeClient();
    expect(() => createOtpHandlers({ client, secret: 'short', onVerified: () => undefined })).toThrow(/32 characters/);
    expect(() => createOtpHandlers({ client, secret: SECRET } as never)).toThrow(/onVerified/);
    expect(() => handlers(client, { template: 'no token' })).toThrow(/\{code\}/);
    expect(() => handlers(client, { defaultCountryCode: '+243' })).toThrow(/defaultCountryCode/);
  });

  it('reads the secret from LABYRINTHE_OTP_SECRET', () => {
    vi.stubEnv('LABYRINTHE_OTP_SECRET', SECRET);
    expect(() => createOtpHandlers({ client: fakeClient(), onVerified: () => undefined })).not.toThrow();
  });

  it('refuses exposeTestCode in production', () => {
    vi.stubEnv('NODE_ENV', 'production');
    expect(() => handlers(fakeClient(), { exposeTestCode: true })).toThrow(/development only/);
  });
});

describe('send', () => {
  it('sends the code and never returns otp_id, the code or the full number', async () => {
    const client = fakeClient();
    const { call, cookie } = session(handlers(client, { codeLength: 6, resendAfterSeconds: 45 }));
    const { response, text, json } = await call('send', { phone: '0812345678', locale: 'en' });

    expect(response.status).toBe(200);
    expect(client.sent).toEqual([{ phone: '+243812345678', locale: 'en' }]);
    expect(json).toMatchObject({ sent: true, phone_masked: '+243 81•••••78', max_attempts: 3, resend_after_seconds: 45, code_length: 6 });
    expect(json['test_code']).toBeUndefined();
    const otpId = [...client.otps.keys()][0]!;
    for (const secret of [otpId, '482913', '243812345678', '0812345678']) {
      expect(text).not.toContain(secret);
      expect(response.headers.get('set-cookie')).not.toContain(secret);
    }
    expect(response.headers.get('cache-control')).toBe('no-store');
    expect(cookie()).toMatch(/^labyrinthe_otp=v1\./);
  });

  it('sets an httpOnly, SameSite=Strict, Secure cookie on HTTPS', async () => {
    const response = await handlers(fakeClient()).send(post('/api/otp/send', { phone: '0812345678' }));
    const setCookie = response.headers.get('set-cookie')!;
    expect(setCookie).toMatch(/HttpOnly/);
    expect(setCookie).toMatch(/SameSite=Strict/);
    expect(setCookie).toMatch(/Secure/);
    expect(setCookie).toMatch(/Path=\//);
    expect(setCookie).toMatch(/Max-Age=9\d\d/); // 600 s de validité + 300 s de marge
  });

  it('honours the cookie options, and no Secure on plain-HTTP development', async () => {
    const otp = handlers(fakeClient(), { cookie: { name: 'otp', path: '/api/otp', sameSite: 'Lax' } });
    const request = new Request('http://localhost:3000/api/otp/send', {
      method: 'POST',
      headers: { 'content-type': 'application/json', origin: 'http://localhost:3000', host: 'localhost:3000' },
      body: JSON.stringify({ phone: '0812345678' }),
    });
    const setCookie = (await otp.send(request)).headers.get('set-cookie')!;
    expect(setCookie).toMatch(/^otp=v1\./);
    expect(setCookie).toMatch(/Path=\/api\/otp/);
    expect(setCookie).toMatch(/SameSite=Lax/);
    expect(setCookie).not.toMatch(/Secure/);
  });

  it('returns test_code only when exposeTestCode is enabled', async () => {
    const { json } = await session(handlers(fakeClient(), { exposeTestCode: true })).call('send', { phone: '0812345678' });
    expect(json['test_code']).toBe('482913');
  });

  it('applies the server template and locale', async () => {
    const client = fakeClient();
    await session(handlers(client, { template: 'Code {code}', locale: 'fr' })).call('send', { phone: '+33612345678', locale: 'en' });
    expect(client.sent).toEqual([{ phone: '+33612345678', template: 'Code {code}', locale: 'fr' }]);
  });

  it('rejects an invalid number without calling the API', async () => {
    const client = fakeClient();
    const { response, json } = await session(handlers(client)).call('send', { phone: '12' });
    expect(response.status).toBe(422);
    expect(json).toEqual({ error: { code: 'invalid_phone', message: 'Invalid phone number.' } });
    expect(client.sent).toHaveLength(0);
  });

  it('lets beforeSend refuse a send (anti-abuse)', async () => {
    const client = fakeClient();
    const beforeSend = vi.fn().mockResolvedValue(false);
    const { response, json } = await session(handlers(client, { beforeSend })).call('send', { phone: '0812345678' });
    expect(response.status).toBe(403);
    expect(json['error'].code).toBe('forbidden');
    expect(beforeSend).toHaveBeenCalledWith(expect.objectContaining({ phone: '243812345678' }));
    expect(client.sent).toHaveLength(0);
  });

  it('maps OTP_RESEND_TOO_SOON with retry_after_seconds and Retry-After', async () => {
    const client = fakeClient();
    client.nextSendError = sdkErrors.resendTooSoon(42);
    const { response, json } = await session(handlers(client)).call('send', { phone: '0812345678' });
    expect(response.status).toBe(429);
    expect(response.headers.get('retry-after')).toBe('42');
    expect(json).toEqual({ error: { code: 'resend_too_soon', message: 'A code was sent recently.', retry_after_seconds: 42 } });
  });

  it('maps OTP_RATE_LIMITED, and hides upstream failures behind service_unavailable', async () => {
    const client = fakeClient();
    const onError = vi.fn();
    const { call } = session(handlers(client, { onError }));

    client.nextSendError = sdkErrors.rateLimited();
    expect((await call('send', { phone: '0812345678' })).json['error'].code).toBe('rate_limited');
    expect(onError).not.toHaveBeenCalled();

    client.nextSendError = sdkErrors.serviceDisabled();
    const { response, text } = await call('send', { phone: '0812345678' });
    expect(response.status).toBe(503);
    expect(JSON.parse(text).error.code).toBe('service_unavailable');
    expect(text).not.toContain('Service coupé'); // le message de l'API n'est jamais relayé
    expect(onError).toHaveBeenCalledWith(expect.objectContaining({ code: 'OTP_SERVICE_DISABLED' }), { action: 'send' });
  });

  it('answers 500 server_error on an unexpected exception', async () => {
    const onError = vi.fn();
    const otp = handlers(fakeClient(), { onError, beforeSend: () => Promise.reject(new Error('boom')) });
    const response = await otp.send(post('/api/otp/send', { phone: '0812345678' }));
    expect(response.status).toBe(500);
    expect(await response.json()).toEqual({ error: { code: 'server_error', message: 'Unexpected error.' } });
    expect(onError).toHaveBeenCalledOnce();
  });
});

describe('request checks', () => {
  const otp = handlers(fakeClient());

  it('requires POST and JSON', async () => {
    expect((await otp.send(new Request(`${ORIGIN}/api/otp/send`))).status).toBe(405);
    const form = post('/api/otp/send', 'phone=0812345678', { headers: { 'content-type': 'application/x-www-form-urlencoded' } });
    expect((await otp.send(form)).status).toBe(415);
    expect((await otp.send(post('/api/otp/send', '{not json'))).status).toBe(400);
    expect((await otp.send(post('/api/otp/send', '[1]'))).status).toBe(400);
    expect((await otp.send(post('/api/otp/send', JSON.stringify({ phone: 'x'.repeat(5000) })))).status).toBe(413);
  });

  it('refuses cross-origin calls by default, and accepts allowedOrigins', async () => {
    const evil = post('/api/otp/send', { phone: '0812345678' }, { headers: { origin: 'https://evil.example' } });
    expect((await otp.send(evil)).status).toBe(403);
    const crossSite = post('/api/otp/send', { phone: '0812345678' }, { headers: { 'sec-fetch-site': 'cross-site' } });
    expect((await otp.send(crossSite)).status).toBe(403);

    const allowed = handlers(fakeClient(), { allowedOrigins: ['https://app.example'] });
    const fromApp = post('/api/otp/send', { phone: '0812345678' }, { headers: { origin: 'https://app.example' } });
    expect((await allowed.send(fromApp)).status).toBe(200);
    expect((await allowed.send(post('/api/otp/send', { phone: '0812345678' }))).status).toBe(403);
  });
});

describe('verify', () => {
  it('verifies the code, calls onVerified with the phone and returns its data', async () => {
    const client = fakeClient();
    const onVerified = vi.fn(({ setCookie, responseHeaders }) => {
      setCookie('app_session=abc; Path=/; HttpOnly');
      responseHeaders.set('X-App', 'yes');
      return { redirect: '/dashboard' };
    });
    const { call, cookie } = session(handlers(client, { onVerified }));
    await call('send', { phone: '0812345678' });
    const { response, json } = await call('verify', { code: ' 482913 ' });

    expect(json).toEqual({ verified: true, data: { redirect: '/dashboard' } });
    expect(client.verified[0]).toEqual({ otpId: [...client.otps.keys()][0], code: '482913' });
    expect(onVerified).toHaveBeenCalledWith(expect.objectContaining({ phone: '243812345678' }));
    expect(response.headers.get('x-app')).toBe('yes');
    expect(response.headers.getSetCookie()).toContain('app_session=abc; Path=/; HttpOnly');
    expect(cookie()).toBeUndefined(); // session OTP effacée

    // Rejouer le même cookie ne sert à rien : plus de vérification en attente.
    const again = await call('verify', { code: '482913' });
    expect(again.json['error'].code).toBe('no_pending_verification');
  });

  it('tracks attempts left, then max_attempts_reached clears the session', async () => {
    const client = fakeClient({ maxAttempts: 3 });
    const { call, cookie } = session(handlers(client));
    await call('send', { phone: '0812345678' });

    expect((await call('verify', { code: '000000' })).json).toEqual({ verified: false, reason: 'invalid_code', attempts_left: 2 });
    expect((await call('verify', { code: '000001' })).json).toEqual({ verified: false, reason: 'invalid_code', attempts_left: 1 });
    expect((await call('verify', { code: '000002' })).json).toEqual({ verified: false, reason: 'max_attempts_reached', attempts_left: 0 });
    expect(cookie()).toBeUndefined();
  });

  it('answers no_pending_verification without a cookie, or with a tampered or foreign one', async () => {
    const client = fakeClient();
    const s = session(handlers(client));
    expect((await s.call('verify', { code: '482913' })).json['error'].code).toBe('no_pending_verification');

    await s.call('send', { phone: '0812345678' });
    const original = s.cookie()!;
    const tampered = original.slice(0, -4) + (original.endsWith('AAAA') ? 'BBBB' : 'AAAA');
    s.setCookie(tampered);
    expect((await s.call('verify', { code: '482913' })).response.status).toBe(400);

    // Cookie émis avec un autre secret.
    const other = session(createOtpHandlers({ client, secret: 'another-secret-another-secret-0123', onVerified: () => undefined }));
    other.setCookie(original);
    expect((await other.call('verify', { code: '482913' })).json['error'].code).toBe('no_pending_verification');
    expect(client.verified).toHaveLength(0);
  });

  it('the cookie does not contain otp_id or the number in clear', async () => {
    const client = fakeClient();
    const s = session(handlers(client));
    await s.call('send', { phone: '0812345678' });
    const value = decodeURIComponent(s.cookie()!.split('=')[1]!);
    const decoded = atob(value.slice(3).replace(/-/g, '+').replace(/_/g, '/'));
    expect(decoded).not.toContain([...client.otps.keys()][0]!);
    expect(decoded).not.toContain('243812345678');
  });

  it('answers expired locally once the code has expired', async () => {
    const client = fakeClient({ ttlMs: 1000 });
    const { call } = session(handlers(client));
    await call('send', { phone: '0812345678' });
    vi.useFakeTimers({ toFake: ['Date'], now: Date.now() + 5000 });
    expect((await call('verify', { code: '482913' })).json).toEqual({ verified: false, reason: 'expired', attempts_left: null });
    expect(client.verified).toHaveLength(0);
  });

  it('a new send invalidates the previous code', async () => {
    const client = fakeClient();
    const otp = handlers(client);
    const first = session(otp);
    await first.call('send', { phone: '0812345678' });
    const oldCookie = first.cookie();
    await first.call('send', { phone: '0812345678' });
    first.setCookie(oldCookie);
    expect((await first.call('verify', { code: '482913' })).json).toEqual({ verified: false, reason: 'expired', attempts_left: null });
  });

  it('maps NOT_FOUND to no_pending_verification and validates the code', async () => {
    const client = fakeClient();
    const { call } = session(handlers(client));
    await call('send', { phone: '0812345678' });
    client.otps.clear();
    expect((await call('verify', { code: '482913' })).json['error'].code).toBe('no_pending_verification');
    expect((await call('verify', { code: '' })).response.status).toBe(422);
    expect((await call('verify', { code: 'x'.repeat(21) })).response.status).toBe(422);
  });
});

describe('store mode', () => {
  it('keeps the state in the store; the cookie only carries an encrypted id', async () => {
    const store = memoryStore();
    const client = fakeClient();
    const { call, cookie } = session(handlers(client, { store }));
    await call('send', { phone: '0812345678' });
    expect(store.size).toBe(1);
    expect(cookie()!.length).toBeLessThan(150); // identifiant seul, pas l'état

    await call('send', { phone: '0812345678' }); // renvoi : l'ancienne entrée est supprimée
    expect(store.size).toBe(1);

    expect((await call('verify', { code: '000000' })).json['attempts_left']).toBe(2);
    expect((await call('verify', { code: '482913' })).json['verified']).toBe(true);
    expect(store.size).toBe(0);
  });
});

describe('with the real @labyrinthe/connect client', () => {
  it('works with a Labyrinthe instance and maps its typed errors', async () => {
    const requests: { url: string; body: unknown }[] = [];
    let mode: 'ok' | 'too-soon' = 'ok';
    const fetchMock = (url: string | URL, init?: RequestInit) => {
      requests.push({ url: String(url), body: JSON.parse(init?.body as string) });
      if (String(url).endsWith('/otp/send')) {
        if (mode === 'too-soon') {
          return Promise.resolve(
            Response.json({ error: { code: 'OTP_RESEND_TOO_SOON', message: 'Patientez', details: { retry_after_seconds: 37 } } }, { status: 429 }),
          );
        }
        return Promise.resolve(
          Response.json(
            { data: { otp_id: '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10', expires_at: '2099-01-01T00:00:00+00:00', max_attempts: 5, test_code: '1234' } },
            { status: 201 },
          ),
        );
      }
      return Promise.resolve(Response.json({ data: { verified: true, reason: null } }));
    };
    const client = new Labyrinthe({ apiKey: 'lsk_test_unit', baseUrl: 'http://api.test', fetch: fetchMock, maxRetries: 0 });
    const { call } = session(handlers(client, { exposeTestCode: true }));

    const sent = await call('send', { phone: '0812345678' });
    expect(sent.json).toMatchObject({ sent: true, max_attempts: 5, test_code: '1234' });
    expect(sent.text).not.toContain('9d5c0a3e');
    expect((await call('verify', { code: '1234' })).json).toEqual({ verified: true });
    expect(requests[1]).toEqual({ url: 'http://api.test/api/public/v1/otp/verify', body: { otp_id: '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10', code: '1234' } });

    mode = 'too-soon';
    const tooSoon = await call('send', { phone: '0812345678' });
    expect(tooSoon.json['error']).toMatchObject({ code: 'resend_too_soon', retry_after_seconds: 37 });
  });
});

describe('express adapter', () => {
  async function withServer(otp: OtpHandlers, run: (base: string) => Promise<void>, json = true) {
    const app = express();
    if (json) app.use(express.json());
    app.post('/api/otp/send', otp.express.send);
    app.post('/api/otp/verify', otp.express.verify);
    const server = app.listen(0);
    await new Promise((resolve) => server.once('listening', resolve));
    try {
      await run(`http://127.0.0.1:${String((server.address() as AddressInfo).port)}`);
    } finally {
      server.close();
    }
  }

  for (const json of [true, false]) {
    it(`runs the whole flow ${json ? 'after express.json()' : 'on the raw stream'}`, async () => {
      const client = fakeClient();
      await withServer(
        handlers(client),
        async (base) => {
          const headers = { 'content-type': 'application/json', origin: base };
          const sent = await fetch(`${base}/api/otp/send`, { method: 'POST', headers, body: JSON.stringify({ phone: '0812345678' }) });
          expect(sent.status).toBe(200);
          const cookies = sent.headers.getSetCookie();
          expect(cookies).toHaveLength(1);
          expect(cookies[0]).toMatch(/HttpOnly/);
          const cookie = cookies[0]!.split(';')[0]!;

          const verified = await fetch(`${base}/api/otp/verify`, {
            method: 'POST',
            headers: { ...headers, cookie },
            body: JSON.stringify({ code: '482913' }),
          });
          expect(await verified.json()).toEqual({ verified: true });
          expect(verified.headers.getSetCookie()[0]).toMatch(/Max-Age=0/);
        },
        json,
      );
    });
  }
});

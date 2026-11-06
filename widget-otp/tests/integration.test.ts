/**
 * Bout en bout contre un vrai backend, avec une clé de TEST (rien n'est envoyé, rien n'est facturé) :
 * helper serveur + SDK Node `@labyrinthe/connect` + API locale.
 *
 *     LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 npm run test:integration
 *
 * Ignoré sans LABYRINTHE_API_KEY, ou si ce n'est pas une clé de test.
 */
import { randomBytes, randomInt } from 'node:crypto';

import { describe, expect, it } from 'vitest';
import { Labyrinthe } from '@labyrinthe/connect';
import { createOtpHandlers, type OtpHandlers } from '../src/server/index.js';
import { SECRET, cookiePair } from './helpers.js';

const key = process.env['LABYRINTHE_API_KEY'] ?? '';
const enabled = key.startsWith('lsk_test_');
const baseUrl = process.env['LABYRINTHE_BASE_URL'] ?? 'http://localhost:8000';

/** Numéro RDC aléatoire : les limites par numéro ne se croisent pas entre deux exécutions. */
const randomPhone = () => `0899${String(randomInt(0, 1_000_000)).padStart(6, '0')}`;

function browser(otp: OtpHandlers) {
  let cookie: string | undefined;
  return async (kind: 'send' | 'verify', body: unknown) => {
    const response = await otp[kind](
      new Request(`http://localhost:3000/api/otp/${kind}`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', origin: 'http://localhost:3000', ...(cookie ? { cookie } : {}) },
        body: JSON.stringify(body),
      }),
    );
    for (const setCookie of response.headers.getSetCookie()) {
      const [name, value] = cookiePair(setCookie);
      if (name === 'labyrinthe_otp') cookie = value === null ? undefined : `${name}=${value}`;
    }
    const text = await response.text();
    return { status: response.status, text, json: JSON.parse(text) as Record<string, any> };
  };
}

describe.skipIf(!enabled)('integration (local backend, test key)', () => {
  const verifiedPhones: string[] = [];
  const otp = createOtpHandlers({
    client: () => new Labyrinthe({ apiKey: key, baseUrl }), // créé au premier appel (suite ignorée sans clé)
    secret: SECRET,
    exposeTestCode: true,
    onVerified: ({ phone }) => {
      verifiedPhones.push(phone);
      return { welcome: true };
    },
  });

  it('send (real code_length / code_type / resend_after_seconds) → wrong code (real attempts) → right code; otp_id never reaches the browser', async () => {
    const call = browser(otp);
    const phone = randomPhone();

    const sent = await call('send', { phone, locale: 'fr' });
    expect(sent.status).toBe(200);
    expect(sent.json['sent']).toBe(true);
    expect(sent.json['max_attempts']).toBeGreaterThanOrEqual(1);
    expect(typeof sent.json['test_code']).toBe('string');
    expect(sent.text).not.toMatch(/otp_id|[0-9a-f]{8}-[0-9a-f]{4}-/i);

    const code = sent.json['test_code'] as string;
    // Valeurs de l'API (paramètres OTP du compte), plus de configuration manuelle.
    expect(sent.json['code_length']).toBe(code.length);
    expect(['numeric', 'alphanumeric']).toContain(sent.json['code_type']);
    expect(Number.isInteger(sent.json['resend_after_seconds'])).toBe(true);

    const first = code.charAt(0);
    const wrong = (/\d/.test(first) ? String((Number(first) + 1) % 10) : first === 'A' ? 'B' : 'A') + code.slice(1);
    if (sent.json['max_attempts'] > 1) {
      const refused = await call('verify', { code: wrong });
      expect(refused.json).toEqual({ verified: false, reason: 'invalid_code', attempts_left: sent.json['max_attempts'] - 1 });
    }

    const verified = await call('verify', { code });
    expect(verified.json).toEqual({ verified: true, data: { welcome: true } });
    expect(verifiedPhones).toEqual([`243${phone.slice(1)}`]);

    const replay = await call('verify', { code });
    expect(replay.json['error'].code).toBe('no_pending_verification');
  });

  it('the same send_id replays the send (same code, no second SMS); a foreign number without "+" is kept', async () => {
    const call = browser(otp);
    const sendId = randomBytes(16).toString('hex');
    const phone = randomPhone();

    const first = await call('send', { phone, send_id: sendId });
    const again = await call('send', { phone, send_id: sendId });
    expect(first.status).toBe(200);
    expect(again.status).toBe(200);
    expect(again.json['test_code']).toBe(first.json['test_code']);

    expect((await call('verify', { code: first.json['test_code'] })).json['verified']).toBe(true);
    expect(verifiedPhones).toContain(`243${phone.slice(1)}`);

    const foreign = `336${String(randomInt(0, 100_000_000)).padStart(8, '0')}`;
    const call2 = browser(otp);
    const sentAbroad = await call2('send', { phone: foreign });
    expect(sentAbroad.status).toBe(200);
    expect((await call2('verify', { code: sentAbroad.json['test_code'] })).json['verified']).toBe(true);
    expect(verifiedPhones).toContain(foreign);
  });

  it('a short foreign number reaches the API with its "+" (BACKEND-TODO n° 32)', async () => {
    // Corps réellement envoyé à l'API, capturé sous le SDK Node.
    const phones: unknown[] = [];
    const seychelles = createOtpHandlers({
      client: () =>
        new Labyrinthe({
          apiKey: key,
          baseUrl,
          fetch: (url, init) => {
            if (url.endsWith('/otp/send') && typeof init.body === 'string') {
              phones.push((JSON.parse(init.body) as Record<string, unknown>)['phone']);
            }
            return globalThis.fetch(url, init);
          },
        }),
      secret: SECRET,
      exposeTestCode: true,
      onVerified: ({ phone }) => ({ phone }),
    });
    const call = browser(seychelles);
    const phone = `+248${String(randomInt(1_000_000, 10_000_000))}`;
    const sent = await call('send', { phone });
    expect(sent.status).toBe(200);
    expect(phones).toEqual([phone]);
    expect(sent.json['phone_masked']).toMatch(/^\+248 /);
    expect((await call('verify', { code: sent.json['test_code'] })).json).toEqual({ verified: true, data: { phone: phone.slice(1) } });
  });

  it('a second send to the same number is refused with resend_too_soon and a delay', async () => {
    const call = browser(otp);
    const phone = randomPhone();
    expect((await call('send', { phone })).status).toBe(200);
    const again = await call('send', { phone });
    expect(again.status).toBe(429);
    expect(again.json['error'].code).toBe('resend_too_soon');
    expect(again.json['error'].retry_after_seconds).toBeGreaterThan(0);
  });

  it('an invalid number is refused by the server helper before reaching the API', async () => {
    const call = browser(otp);
    expect((await call('send', { phone: '12' })).json['error'].code).toBe('invalid_phone');
  });
});

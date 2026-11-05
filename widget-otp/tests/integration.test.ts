/**
 * Bout en bout contre un vrai backend, avec une clé de TEST (rien n'est envoyé, rien n'est facturé) :
 * helper serveur + SDK Node `@labyrinthe/connect` + API locale.
 *
 *     LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 npm run test:integration
 *
 * Ignoré sans LABYRINTHE_API_KEY, ou si ce n'est pas une clé de test.
 */
import { randomInt } from 'node:crypto';
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

  it('send → wrong code → right code → verified; otp_id never reaches the browser', async () => {
    const call = browser(otp);
    const phone = randomPhone();

    const sent = await call('send', { phone, locale: 'fr' });
    expect(sent.status).toBe(200);
    expect(sent.json).toMatchObject({ sent: true, resend_after_seconds: 60 });
    expect(sent.json['max_attempts']).toBeGreaterThanOrEqual(1);
    expect(typeof sent.json['test_code']).toBe('string');
    expect(sent.text).not.toMatch(/otp_id|[0-9a-f]{8}-[0-9a-f]{4}-/i);

    const code = sent.json['test_code'] as string;
    const wrong = code === '0'.repeat(code.length) ? '1'.repeat(code.length) : '0'.repeat(code.length);
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

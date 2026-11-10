/**
 * Runs against a real backend with a TEST key (nothing is sent, nothing is billed):
 *
 *     LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 npm run test:integration
 *
 * Skipped when LABYRINTHE_API_KEY is not set, or is not a test key.
 *
 * Limits: 10 OTP sends per minute per key, 5 per number per hour. This file
 * sends 4 OTP requests, each to a random number.
 */
import { randomBytes, randomInt } from 'node:crypto';
import { beforeAll, describe, expect, it } from 'vitest';
import {
  AuthenticationError,
  IdempotencyKeyReusedError,
  Labyrinthe,
  NotFoundError,
  SenderIdNotApprovedError,
  ValidationError,
  type ResponseInfo,
} from '../src/index.js';

const key = process.env['LABYRINTHE_API_KEY'] ?? '';
const enabled = key.startsWith('lsk_test_');
const baseUrl = process.env['LABYRINTHE_BASE_URL'] ?? 'http://localhost:8000';

/** Un numéro RDC aléatoire : les limites OTP par numéro ne se croisent jamais entre deux exécutions. */
const randomPhone = () => `0899${String(randomInt(0, 1_000_000)).padStart(6, '0')}`;
const reference = () => `sdk-node-it-${randomBytes(6).toString('hex')}`;

/** Un code faux de même forme : premier caractère changé (chiffre → chiffre suivant, lettre → autre lettre). */
const wrongCodeFor = (code: string) => {
  const first = code.charAt(0);
  const other = /\d/.test(first) ? String((Number(first) + 1) % 10) : first.toUpperCase() === 'A' ? 'B' : 'A';
  return other + code.slice(1);
};

describe.skipIf(!enabled)('integration (local backend, test key)', () => {
  let client: Labyrinthe;
  beforeAll(() => {
    client = new Labyrinthe({ apiKey: key, baseUrl });
  });

  it('reads the balance', async () => {
    const balance = await client.balance.get();
    expect(client.isTestMode).toBe(true);
    expect(balance.held).toBeGreaterThanOrEqual(0);
    expect(Number.isInteger(balance.balance)).toBe(true);
  });

  it('estimates a send (GSM-7 and UCS-2, invalid numbers ignored)', async () => {
    const estimate = await client.sms.estimate({ body: 'Hello from the Node SDK', recipients: ['0812345678', 'not-a-number'] });

    expect(estimate.encoding).toBe('gsm7');
    expect(estimate.segmentsPerMessage).toBe(1);
    expect(estimate.recipientsCount).toBe(1);
    expect(estimate.totalCredits).toBe(1);

    expect((await client.sms.estimate({ body: 'Réunion à 9h', recipients: '0812345678' })).encoding).toBe('ucs2');
  });

  it('estimate with stripAccents switches to GSM-7, and duplicates are merged like a send', async () => {
    const stripped = await client.sms.estimate({
      body: 'Réunion à 9h, café offert',
      recipients: ['0812345678', '243812345678', '+243812345678'],
      stripAccents: true,
    });

    expect(stripped.encoding).toBe('gsm7');
    expect(stripped.recipientsCount).toBe(1);
    expect(stripped.totalCredits).toBe(1);
  });

  it('keeps a foreign number given without "+" (spec 1.2.0)', async () => {
    const message = await client.sms.send({ to: '33612345678', body: 'Integration test (foreign number)', reference: reference() });
    expect(message.recipient).toBe('33612345678');
  });

  // Depuis la 1.1.1 : envoyé sans « + », « 2481234567 » serait lu comme local RDC (2432481234567).
  it('keeps the country code of a short foreign number (+248, 10 digits) in estimates and list filters', async () => {
    // Sans « + », les deux numéros seraient le même (2432481234567) et comptés une seule fois.
    const estimate = await client.sms.estimate({ body: 'Hello', recipients: ['+2481234567', '2432481234567'] });
    expect(estimate.recipientsCount).toBe(2);
    expect((await client.sms.estimate({ body: 'Hello', recipients: ['+2481234567', '0812345678'] })).recipientsCount).toBe(2);

    const page = await client.sms.list({ recipient: '+2481234567', perPage: 5 });
    expect(page.data.every((m) => m.recipient === '2481234567')).toBe(true);
  });

  // Corrigé côté API : POST /sms garde l'indicatif d'un numéro étranger court envoyé avec « + ».
  it('sends to a short foreign number (+248, 10 digits) without adding 243', async () => {
    const message = await client.sms.send({ to: '+2481234567', body: 'Integration test (short foreign number)', reference: reference() });
    expect(message.recipient).toBe('2481234567');
  });

  it('exposes the request id: yours echoed on an error, the API one on a response', async () => {
    const requestId = `sdk-node-it.${randomBytes(6).toString('hex')}`;
    const error = (await client.sms
      .get('00000000-0000-4000-8000-000000000000', { requestId })
      .catch((e: unknown) => e)) as NotFoundError;

    expect(error).toBeInstanceOf(NotFoundError);
    expect(error.requestId).toBe(requestId);
    expect(error.apiVersion).toBe('1');

    const seen: ResponseInfo[] = [];
    await client.balance.get({ onResponse: (r) => seen.push(r) });
    expect(seen[0]!.requestId).toMatch(/^[0-9a-f-]{36}$/);
    expect(seen[0]!.apiVersion).toBe('1');
    expect(seen[0]!.rateLimit.limit).toBeGreaterThan(0);

    const validation = (await client.sms.list({ recipient: 'x'.repeat(40) }).catch((e: unknown) => e)) as ValidationError;
    expect(validation).toBeInstanceOf(ValidationError);
    expect(validation.requestId).not.toBeNull();
    expect(Object.keys(validation.fieldErrors)).toContain('recipient');
  });

  it('sends with a reference and an idempotency key: replay, then 409; lists and gets it', async () => {
    const ref = reference();
    const idempotencyKey = `sdk-node-it-${randomBytes(8).toString('hex')}`;
    const phone = randomPhone();

    const message = await client.sms.send({ to: phone, body: 'Integration test', reference: ref, idempotencyKey });
    expect(message.reference).toBe(ref);
    expect(message.recipient).toBe(`243${phone.slice(1)}`);
    expect(message.segments).toBe(1);
    expect(message.encoding).toBe('gsm7');
    expect(message.status).not.toBe('unknown');
    expect(message.createdAt).toBeInstanceOf(Date);

    // Même clé, même contenu (numéro sous une autre forme : empreinte normalisée) : rejoué, rien n'est renvoyé.
    const replay = await client.sms.sendMany({ recipients: [`+243${phone.slice(1)}`], body: 'Integration test', reference: ref, idempotencyKey });
    expect(replay.replayed).toBe(true);
    expect(replay.messages[0]!.id).toBe(message.id);

    // Même clé, autre contenu : refusé.
    const error = await client.sms
      .send({ to: phone, body: 'Another body', reference: ref, idempotencyKey })
      .catch((e: unknown) => e);
    expect(error).toBeInstanceOf(IdempotencyKeyReusedError);
    expect((error as IdempotencyKeyReusedError).status).toBe(409);

    const page = await client.sms.list({ reference: ref });
    expect(page.total).toBe(1);
    expect(page.data[0]!.id).toBe(message.id);

    const byRecipient = await client.sms.list({ recipient: phone, reference: ref, perPage: 5 });
    expect(byRecipient.total).toBe(1);

    const seen: string[] = [];
    for await (const m of client.sms.list({ reference: ref, perPage: 1 })) seen.push(m.id);
    expect(seen).toEqual([message.id]);

    expect((await client.sms.get(message.id)).id).toBe(message.id);
  });

  it('a refused multi-send sends nothing', async () => {
    const ref = reference();

    const error = await client.sms
      .sendMany({ recipients: [randomPhone(), randomPhone()], body: 'Never sent', sender: 'NOTAPPROVED', reference: ref })
      .catch((e: unknown) => e);

    expect(error).toBeInstanceOf(SenderIdNotApprovedError);
    expect((error as SenderIdNotApprovedError).code).toBe('SENDER_ID_NOT_APPROVED');
    expect((await client.sms.list({ reference: ref })).total).toBe(0);
  });

  it('OTP: send (testCode, codeLength, codeType) → wrong code (attemptsRemaining) → verify → already_used', async () => {
    const otp = await client.otp.send({ phone: randomPhone(), locale: 'en' });

    expect(otp.otpId).not.toBe('');
    expect(otp.expiresAt.getTime()).toBeGreaterThan(Date.now());
    expect(otp.replayed).toBe(false);
    expect(otp.requestId).not.toBeNull();
    const code = otp.testCode;
    expect(code, 'a test key returns test_code').toBeTruthy();
    expect(otp.codeLength).toBeGreaterThanOrEqual(4);
    expect(otp.codeLength).toBeLessThanOrEqual(8);
    expect(code!.length).toBe(otp.codeLength);
    expect(['numeric', 'alphanumeric']).toContain(otp.codeType);
    expect(otp.resendAfterSeconds).toBeGreaterThanOrEqual(0);

    const wrong = await client.otp.verify({ otpId: otp.otpId, code: wrongCodeFor(code!) });
    expect(wrong).toEqual({ verified: false, reason: 'invalid_code', attemptsRemaining: otp.maxAttempts - 1 });

    expect(await client.otp.verify({ otpId: otp.otpId, code: code! })).toEqual({ verified: true, reason: null, attemptsRemaining: null });
    expect(await client.otp.verify({ otpId: otp.otpId, code: code! })).toEqual({
      verified: false,
      reason: 'already_used',
      attemptsRemaining: null,
    });
  });

  it('OTP: a replay with the same idempotencyKey returns the same otpId and code without a new SMS; another content → 409', async () => {
    const phone = randomPhone();
    const idempotencyKey = `sdk-node-it-otp-${randomBytes(8).toString('hex')}`;

    const first = await client.otp.send({ phone, idempotencyKey, webOtpDomain: 'app.exemple.com', stripAccents: true });
    const replay = await client.otp.send({
      phone: `243${phone.slice(1)}`,
      idempotencyKey,
      webOtpDomain: 'app.exemple.com',
      stripAccents: true,
    });

    expect(first.replayed).toBe(false);
    expect(replay.replayed).toBe(true);
    expect(replay.otpId).toBe(first.otpId);
    expect(replay.testCode).toBe(first.testCode);
    expect(replay.idempotencyKey).toBe(idempotencyKey);

    const reused = await client.otp.send({ phone, idempotencyKey, locale: 'fr' }).catch((e: unknown) => e);
    expect(reused).toBeInstanceOf(IdempotencyKeyReusedError);
  });

  it('unknown message and otpId give NotFoundError', async () => {
    await expect(client.sms.get('00000000-0000-4000-8000-000000000000')).rejects.toBeInstanceOf(NotFoundError);
    await expect(client.otp.verify({ otpId: '00000000-0000-4000-8000-000000000000', code: '123456' })).rejects.toBeInstanceOf(
      NotFoundError,
    );
  });

  it('an invalid key is rejected', async () => {
    const invalid = new Labyrinthe({ apiKey: 'lsk_test_invalid', baseUrl });
    const error = (await invalid.balance.get().catch((e: unknown) => e)) as AuthenticationError;
    expect(error).toBeInstanceOf(AuthenticationError);
    expect(error.requestId).not.toBeNull();
  });

  it('lists approved sender IDs', async () => {
    const senderIds = await client.senderIds.list();
    expect(Array.isArray(senderIds)).toBe(true);
    for (const s of senderIds) expect(s.status).toBe('approved');
  });
});

/**
 * Runs against a real backend with a TEST key (nothing is sent, nothing is billed):
 *
 *     LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 npm run test:integration
 *
 * Skipped when LABYRINTHE_API_KEY is not set, or is not a test key.
 */
import { randomBytes, randomInt } from 'node:crypto';
import { beforeAll, describe, expect, it } from 'vitest';
import {
  AuthenticationError,
  IdempotencyKeyReusedError,
  Labyrinthe,
  NotFoundError,
  SenderIdNotApprovedError,
} from '../src/index.js';

const key = process.env['LABYRINTHE_API_KEY'] ?? '';
const enabled = key.startsWith('lsk_test_');
const baseUrl = process.env['LABYRINTHE_BASE_URL'] ?? 'http://localhost:8000';

/** Un numéro RDC aléatoire : les limites OTP par numéro ne se croisent jamais entre deux exécutions. */
const randomPhone = () => `0899${String(randomInt(0, 1_000_000)).padStart(6, '0')}`;
const reference = () => `sdk-node-it-${randomBytes(6).toString('hex')}`;

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

  it('sends with a reference and an idempotency key: replay, then 409; lists and gets it', async () => {
    const ref = reference();
    const idempotencyKey = `sdk-node-it-${randomBytes(8).toString('hex')}`;
    const phone = randomPhone();

    const message = await client.sms.send({ to: phone, body: 'Integration test', reference: ref, idempotencyKey });
    expect(message.reference).toBe(ref);
    expect(message.recipient).toBe(`243${phone.slice(1)}`);
    expect(message.segments).toBe(1);
    expect(message.status).not.toBe('unknown');
    expect(message.createdAt).toBeInstanceOf(Date);

    // Même clé, même contenu : rejoué, rien n'est renvoyé.
    const replay = await client.sms.sendMany({ recipients: [phone], body: 'Integration test', reference: ref, idempotencyKey });
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

  it('OTP: send (testCode) → wrong code → verify → already_used', async () => {
    const otp = await client.otp.send({ phone: randomPhone(), locale: 'en' });

    expect(otp.otpId).not.toBe('');
    expect(otp.expiresAt.getTime()).toBeGreaterThan(Date.now());
    const code = otp.testCode;
    expect(code, 'a test key returns test_code').toBeTruthy();

    const wrong = await client.otp.verify({ otpId: otp.otpId, code: code === '000000' ? '111111' : '000000' });
    expect(wrong).toEqual({ verified: false, reason: 'invalid_code' });

    expect(await client.otp.verify({ otpId: otp.otpId, code: code! })).toEqual({ verified: true, reason: null });
    expect(await client.otp.verify({ otpId: otp.otpId, code: code! })).toEqual({ verified: false, reason: 'already_used' });
  });

  it('unknown message and otpId give NotFoundError', async () => {
    await expect(client.sms.get('00000000-0000-4000-8000-000000000000')).rejects.toBeInstanceOf(NotFoundError);
    await expect(client.otp.verify({ otpId: '00000000-0000-4000-8000-000000000000', code: '123456' })).rejects.toBeInstanceOf(
      NotFoundError,
    );
  });

  it('an invalid key is rejected', async () => {
    const invalid = new Labyrinthe({ apiKey: 'lsk_test_invalid', baseUrl });
    await expect(invalid.balance.get()).rejects.toBeInstanceOf(AuthenticationError);
  });

  it('lists approved sender IDs', async () => {
    const senderIds = await client.senderIds.list();
    expect(Array.isArray(senderIds)).toBe(true);
    for (const s of senderIds) expect(s.status).toBe('approved');
  });
});

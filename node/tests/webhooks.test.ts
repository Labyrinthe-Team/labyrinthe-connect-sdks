import { describe, expect, expectTypeOf, it } from 'vitest';
import {
  InvalidArgumentError,
  InvalidWebhookPayloadError,
  SignatureVerificationError,
  Webhooks,
  type WebhookEvent,
} from '../src/index.js';
import { rejection } from './helpers.js';

const SECRET = 'whsec_test_secret';
const NOW = 1_793_952_000;

function envelope(event: string, data: unknown, extra: Record<string, unknown> = {}): string {
  return JSON.stringify({ id: '1f0c7e52-8d3a-4b6e-9c2f-5a7d1e3b9f40', event, data, timestamp: NOW, ...extra });
}

async function construct(body: string, options: { header?: string; secret?: string; now?: number; toleranceSeconds?: number } = {}) {
  const header = options.header ?? (await Webhooks.sign(body, SECRET));
  return Webhooks.constructEvent(body, header, options.secret ?? SECRET, {
    now: (options.now ?? NOW) * 1000,
    ...(options.toleranceSeconds === undefined ? {} : { toleranceSeconds: options.toleranceSeconds }),
  });
}

describe('Webhooks.sign', () => {
  it('matches a known HMAC-SHA256 (same as the backend and the PHP SDK)', async () => {
    // printf '%s' 'hello' | openssl dgst -sha256 -hmac 'secret'
    expect(await Webhooks.sign('hello', 'secret')).toBe(
      'sha256=88aab3ede8d3adf94d26ab90d3bafd4a2083070c3bcce9c014ee04a443847c0b',
    );
  });
});

describe('Webhooks.constructEvent', () => {
  it('parses message.status_changed', async () => {
    const event = await construct(
      envelope('message.status_changed', {
        message_id: '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10',
        recipient: '243812345678',
        status: 'delivered',
        provider_message_id: 'DD-88231',
        campaign_id: null,
        reference: 'CMD-1042',
        error_code: null,
        error_message: null,
      }),
    );

    expect(event.event).toBe('message.status_changed');
    expect(event.type).toBe('message.status_changed');
    expect(event.id).toBe('1f0c7e52-8d3a-4b6e-9c2f-5a7d1e3b9f40');
    expect(event.timestamp).toEqual(new Date(NOW * 1000));
    expect(event.data).toEqual({
      messageId: '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10',
      recipient: '243812345678',
      status: 'delivered',
      providerMessageId: 'DD-88231',
      campaignId: null,
      reference: 'CMD-1042',
      errorCode: null,
      errorMessage: null,
    });
    expect(event.raw['event']).toBe('message.status_changed');
  });

  it('reads the failure of message.status_changed, and tolerates a payload without the 1.2.0 fields', async () => {
    const failed = await construct(
      envelope('message.status_changed', {
        message_id: 'm-1',
        recipient: '243812345678',
        status: 'failed',
        provider_message_id: null,
        campaign_id: null,
        reference: null,
        error_code: 'IP_NOT_WHITELISTED',
        error_message: 'IP not Whitelisted',
      }),
    );
    expect(failed.data).toMatchObject({ status: 'failed', errorCode: 'IP_NOT_WHITELISTED', errorMessage: 'IP not Whitelisted' });

    const old = await construct(
      envelope('message.status_changed', { message_id: 'm-1', recipient: '243812345678', status: 'sent', provider_message_id: null, campaign_id: null }),
    );
    expect(old.data).toMatchObject({ reference: null, errorCode: null, errorMessage: null });
  });

  it('parses otp.verified', async () => {
    const event = await construct(
      envelope('otp.verified', {
        otp_id: '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10',
        phone: '243812345678',
        status: 'verified',
        reason: null,
        verified_at: '2026-11-06T09:41:02+00:00',
      }),
    );

    expect(event.event).toBe('otp.verified');
    expect(event.data).toEqual({
      otpId: '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10',
      phone: '243812345678',
      status: 'verified',
      reason: null,
      verifiedAt: new Date('2026-11-06T09:41:02Z'),
    });
  });

  it.each([
    ['failed', 'max_attempts_reached'],
    ['expired', 'expired'],
  ] as const)('parses otp.failed (status %s, reason %s)', async (status, reason) => {
    const event = await construct(
      envelope('otp.failed', { otp_id: 'otp-1', phone: '243812345678', status, reason, verified_at: null }),
    );

    expect(event.event).toBe('otp.failed');
    expect(event.data).toEqual({ otpId: 'otp-1', phone: '243812345678', status, reason, verifiedAt: null });
  });

  it('maps an unknown OTP status or reason to "unknown"', async () => {
    const event = await construct(
      envelope('otp.failed', { otp_id: 'otp-1', phone: '243812345678', status: 'revoked', reason: 'admin', verified_at: null }),
    );
    expect(event.data).toMatchObject({ status: 'unknown', reason: 'unknown' });
  });

  it('parses campaign.completed', async () => {
    const event = await construct(
      envelope('campaign.completed', {
        campaign_id: '3c9a1f7e-2b4d-4e6a-8f0c-9d1b5e7a3c20',
        name: 'Promo de novembre',
        recipients_count: 1200,
        messages_by_status: { delivered: 1150, failed: 30, undeliverable: 20 },
        credits_charged: 1200,
        completed_at: '2026-11-06T10:15:00+00:00',
      }),
    );

    expect(event.event).toBe('campaign.completed');
    if (event.event !== 'campaign.completed') throw new Error('narrowing');
    expect(event.data).toEqual({
      campaignId: '3c9a1f7e-2b4d-4e6a-8f0c-9d1b5e7a3c20',
      name: 'Promo de novembre',
      recipientsCount: 1200,
      messagesByStatus: { delivered: 1150, failed: 30, undeliverable: 20 },
      creditsCharged: 1200,
      completedAt: new Date('2026-11-06T10:15:00Z'),
    });
  });

  it('parses balance.low and test.ping', async () => {
    const low = await construct(envelope('balance.low', { balance: 950, threshold: 1000 }));
    const ping = await construct(envelope('test.ping', {}));

    expect(low).toMatchObject({ event: 'balance.low', data: { balance: 950, threshold: 1000 } });
    expect(ping).toMatchObject({ event: 'test.ping', data: {} });
  });

  it('returns an UnknownEvent for a type added later (never rejected)', async () => {
    const event = await construct(envelope('sms.received', { message_id: 'm-1' }));

    expect(event.event).toBe('unknown');
    expect(event.type).toBe('sms.received');
    expect(event.data).toEqual({ message_id: 'm-1' });
  });

  it('narrows data on the event discriminant', () => {
    const handle = (event: WebhookEvent): string => {
      switch (event.event) {
        case 'message.status_changed':
          expectTypeOf(event.data.messageId).toEqualTypeOf<string>();
          return event.data.status;
        case 'campaign.completed':
          expectTypeOf(event.data.completedAt).toEqualTypeOf<Date | null>();
          return event.data.name;
        case 'balance.low':
          expectTypeOf(event.data.threshold).toEqualTypeOf<number>();
          return String(event.data.balance);
        case 'otp.verified':
          expectTypeOf(event.data.verifiedAt).toEqualTypeOf<Date | null>();
          return event.data.phone;
        case 'otp.failed':
          expectTypeOf(event.data.reason).toEqualTypeOf<'max_attempts_reached' | 'expired' | 'unknown' | null>();
          return event.data.status;
        case 'test.ping':

          return 'ping';
        case 'unknown':
          expectTypeOf(event.data).toEqualTypeOf<Record<string, unknown>>();
          return event.type;
      }
    };
    expect(handle).toBeTypeOf('function');
  });

  it('accepts the raw body as a Buffer, a Uint8Array or an ArrayBuffer', async () => {
    const body = envelope('test.ping', {});
    const header = await Webhooks.sign(body, SECRET);
    const now = NOW * 1000;
    const bytes = new TextEncoder().encode(body);

    await expect(Webhooks.constructEvent(Buffer.from(body), header, SECRET, { now })).resolves.toMatchObject({ event: 'test.ping' });
    await expect(Webhooks.constructEvent(bytes, header, SECRET, { now })).resolves.toMatchObject({ event: 'test.ping' });
    await expect(Webhooks.constructEvent(bytes.buffer, header, SECRET, { now })).resolves.toMatchObject({ event: 'test.ping' });
  });

  it('accepts an upper-case signature and surrounding spaces', async () => {
    const body = envelope('test.ping', {});
    const header = (await Webhooks.sign(body, SECRET)).toUpperCase().replace('SHA256=', '  sha256=');
    await expect(construct(body, { header: `${header} ` })).resolves.toMatchObject({ event: 'test.ping' });
  });

  it.each([
    ['a missing header', ''],
    ['no sha256= prefix', '88aab3ede8d3adf94d26ab90d3bafd4a2083070c3bcce9c014ee04a443847c0b'],
    ['a wrong signature', `sha256=${'0'.repeat(64)}`],
    ['a truncated signature', 'sha256=88aab3'],
    ['a non-hex signature', `sha256=${'z'.repeat(64)}`],
  ])('rejects %s', async (_label, header) => {
    const error = await rejection(construct(envelope('test.ping', {}), { header }));
    expect(error).toBeInstanceOf(SignatureVerificationError);
    expect((error as SignatureVerificationError).code).toBe('SIGNATURE_VERIFICATION_FAILED');
  });

  it('rejects a body signed with another secret, or modified after signing', async () => {
    const body = envelope('balance.low', { balance: 950, threshold: 1000 });
    const header = await Webhooks.sign(body, 'another-secret');
    await expect(construct(body, { header })).rejects.toBeInstanceOf(SignatureVerificationError);

    const genuine = await Webhooks.sign(body, SECRET);
    await expect(construct(body.replace('950', '9500'), { header: genuine })).rejects.toBeInstanceOf(SignatureVerificationError);
  });

  it('rejects a timestamp outside the tolerance (replay), in both directions', async () => {
    const body = envelope('test.ping', {});
    await expect(construct(body, { now: NOW + 301 })).rejects.toThrow(/outside the 300 s tolerance/);
    await expect(construct(body, { now: NOW - 301 })).rejects.toBeInstanceOf(SignatureVerificationError);
    await expect(construct(body, { now: NOW + 300 })).resolves.toBeDefined();
    await expect(construct(body, { now: NOW + 59, toleranceSeconds: 60 })).resolves.toBeDefined();
    await expect(construct(body, { now: NOW + 61, toleranceSeconds: 60 })).rejects.toBeInstanceOf(SignatureVerificationError);
    await expect(construct(body, { now: NOW + 86_400, toleranceSeconds: 0 })).resolves.toBeDefined();
  });

  it.each([
    ['not JSON', 'not json'],
    ['not an object', '[1,2]'],
    ['without timestamp', JSON.stringify({ id: 'x', event: 'test.ping', data: {} })],
    ['without id', JSON.stringify({ event: 'test.ping', data: {}, timestamp: NOW })],
    ['with a broken known payload', envelope('balance.low', { balance: 'lots' })],
  ])('rejects a signed body %s with InvalidWebhookPayloadError', async (_label, body) => {
    const error = await rejection(construct(body));
    expect(error).toBeInstanceOf(InvalidWebhookPayloadError);
    expect((error as Error).message).not.toContain('Unexpected API payload');
  });

  it('refuses an empty secret and a parsed body', async () => {
    await expect(construct(envelope('test.ping', {}), { secret: '' })).rejects.toBeInstanceOf(InvalidArgumentError);
    await expect(
      Webhooks.constructEvent({ event: 'test.ping' } as unknown as string, 'sha256=x', SECRET),
    ).rejects.toThrow(/RAW request body/);
  });

  it('verifySignature() returns a boolean', async () => {
    const header = await Webhooks.sign('{}', SECRET);
    expect(await Webhooks.verifySignature('{}', header, SECRET)).toBe(true);
    expect(await Webhooks.verifySignature('{ }', header, SECRET)).toBe(false);
    expect(await Webhooks.verifySignature('{}', null, SECRET)).toBe(false);
  });
});

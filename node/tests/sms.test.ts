import { describe, expect, it } from 'vitest';
import { InvalidArgumentError, UnexpectedResponseError } from '../src/index.js';
import { harness, json, message, rejection } from './helpers.js';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

describe('sms.send', () => {
  it('normalises the number, sends the body and returns a camelCase message', async () => {
    const { client, requests } = harness([
      json(202, { data: [message({ submitted_at: '2026-11-05T10:00:02Z', provider_message_id: 'DD-1' })] }),
    ]);

    const sent = await client.sms.send({ to: '+243 81 234 5678', body: 'Your order 1042 is ready.', reference: 'order-1042' });

    expect(requests[0]!.method).toBe('POST');
    expect(requests[0]!.url).toBe('https://api.test/api/public/v1/sms');
    expect(requests[0]!.headers['Content-Type']).toBe('application/json');
    expect(requests[0]!.json).toEqual({
      recipient: '+243812345678',
      body: 'Your order 1042 is ready.',
      reference: 'order-1042',
    });
    expect(sent).toEqual({
      id: '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10',
      reference: 'order-1042',
      recipient: '243812345678',
      sender: 'MYSHOP',
      encoding: 'gsm7',
      status: 'queued',
      segments: 1,
      creditsCharged: 1,
      campaignId: null,
      providerMessageId: 'DD-1',
      errorCode: null,
      errorMessage: null,
      createdAt: new Date('2026-11-05T10:00:00Z'),
      submittedAt: new Date('2026-11-05T10:00:02Z'),
      deliveredAt: null,
    });
  });

  it('always sends an Idempotency-Key (a fresh UUID v4 per call)', async () => {
    const { client, requests } = harness([json(202, { data: [message()] }), json(202, { data: [message()] })]);

    await client.sms.send({ to: '0812345678', body: 'A' });
    await client.sms.send({ to: '0812345678', body: 'A' });

    const [first, second] = requests.map((r) => r.headers['Idempotency-Key']);
    expect(first).toMatch(UUID);
    expect(second).toMatch(UUID);
    expect(first).not.toBe(second);
  });

  it('uses the given Idempotency-Key, sender and the default sender', async () => {
    const { client, requests } = harness(
      [json(202, { data: [message()] }), json(202, { data: [message()] })],
      { defaultSender: 'DEFAULT' },
    );

    await client.sms.send({ to: '0812345678', body: 'A', idempotencyKey: 'order-1042-ready', sender: 'MYSHOP' });
    await client.sms.send({ to: '0812345678', body: 'A' });

    expect(requests[0]!.headers['Idempotency-Key']).toBe('order-1042-ready');
    expect((requests[0]!.json as Record<string, unknown>)['sender']).toBe('MYSHOP');
    expect((requests[1]!.json as Record<string, unknown>)['sender']).toBe('DEFAULT');
  });

  it('tolerates a null encoding (backend) and unknown statuses', async () => {
    const { client } = harness([json(202, { data: [message({ encoding: null, status: 'teleported' })] })]);

    const sent = await client.sms.send({ to: '0812345678', body: 'A' });

    expect(sent.encoding).toBeNull();
    expect(sent.status).toBe('unknown');
  });

  it.each([
    ['an invalid number', { to: 'nope', body: 'A' }, /Invalid phone number in "to"/],
    ['an empty body', { to: '0812345678', body: '' }, /body cannot be empty/],
    ['a body over 1600 characters', { to: '0812345678', body: 'a'.repeat(1601) }, /at most 1600/],
    ['a sender over 11 characters', { to: '0812345678', body: 'A', sender: 'TWELVECHARSX' }, /sender/],
    ['an empty sender', { to: '0812345678', body: 'A', sender: '' }, /sender/],
    ['a reference over 64 characters', { to: '0812345678', body: 'A', reference: 'r'.repeat(65) }, /reference/],
    ['an empty idempotency key', { to: '0812345678', body: 'A', idempotencyKey: '' }, /idempotencyKey/],
    ['an idempotency key over 255', { to: '0812345678', body: 'A', idempotencyKey: 'k'.repeat(256) }, /idempotencyKey/],
  ])('refuses %s before any call', async (_label, params, pattern) => {
    const { client, requests } = harness([]);

    const error = await rejection(client.sms.send(params));

    expect(error).toBeInstanceOf(InvalidArgumentError);
    expect((error as Error).message).toMatch(pattern);
    expect(requests).toHaveLength(0);
  });

  it('counts the body length in characters, not UTF-16 units', async () => {
    const { client } = harness([json(202, { data: [message()] })]);
    await expect(client.sms.send({ to: '0812345678', body: '😀'.repeat(1600) })).resolves.toBeDefined();
  });

  it('fails clearly when the API returns no message', async () => {
    const { client } = harness([json(202, { data: [] })]);
    await expect(client.sms.send({ to: '0812345678', body: 'A' })).rejects.toBeInstanceOf(UnexpectedResponseError);
  });
});

describe('sms.sendMany', () => {
  it('sends normalised recipients and reports a replay', async () => {
    const { client, requests } = harness([
      json(202, { data: [message(), message({ id: 'm-2', recipient: '243899999999' })] }, { 'Idempotent-Replayed': 'true' }),
    ]);

    const batch = await client.sms.sendMany({
      recipients: ['0812345678', '+243 89 999 9999'],
      body: 'Meeting tomorrow at 9am.',
      idempotencyKey: 'meeting-2026-11-06',
    });

    expect(requests[0]!.json).toEqual({ recipients: ['+243812345678', '+243899999999'], body: 'Meeting tomorrow at 9am.' });
    expect(batch.messages).toHaveLength(2);
    expect(batch.messages[1]!.recipient).toBe('243899999999');
    expect(batch.replayed).toBe(true);
    expect(batch.idempotencyKey).toBe('meeting-2026-11-06');
  });

  it('reports a first send as not replayed, with the generated key', async () => {
    const { client, requests } = harness([json(202, { data: [message()] })]);

    const batch = await client.sms.sendMany({ recipients: ['0812345678'], body: 'A' });

    expect(batch.replayed).toBe(false);
    expect(batch.idempotencyKey).toBe(requests[0]!.headers['Idempotency-Key']);
  });

  it('refuses invalid numbers locally, by index, without revealing them', async () => {
    const { client, requests } = harness([]);

    const error = await rejection(client.sms.sendMany({ recipients: ['0812345678', 'nope', '12'], body: 'A' }));

    expect(error).toBeInstanceOf(InvalidArgumentError);
    expect((error as Error).message).toBe('Invalid phone number(s) at recipients index 1, 2. Nothing was sent.');
    expect(requests).toHaveLength(0);
  });

  it('refuses an empty list and more than 1000 recipients', async () => {
    const { client } = harness([]);
    await expect(client.sms.sendMany({ recipients: [], body: 'A' })).rejects.toThrow(/At least one recipient/);
    await expect(
      client.sms.sendMany({ recipients: Array.from({ length: 1001 }, () => '0812345678'), body: 'A' }),
    ).rejects.toThrow(/At most 1000/);
  });
});

describe('sms.estimate', () => {
  it('normalises valid numbers and passes the others through', async () => {
    const { client, requests } = harness([
      json(200, {
        data: {
          encoding: 'ucs2',
          characters: 19,
          segments_per_message: 1,
          recipients_count: 1,
          total_credits: 1,
          balance_after: 41,
        },
      }),
    ]);

    const estimate = await client.sms.estimate({ body: 'Réunion demain à 9h', recipients: ['0812345678', 'x'] });

    expect(requests[0]!.url).toBe('https://api.test/api/public/v1/sms/estimate');
    expect(requests[0]!.json).toEqual({ body: 'Réunion demain à 9h', recipients: ['+243812345678', 'x'] });
    expect(estimate).toEqual({
      encoding: 'ucs2',
      characters: 19,
      segmentsPerMessage: 1,
      recipientsCount: 1,
      totalCredits: 1,
      balanceAfter: 41,
      isAffordable: true,
    });
  });

  it('accepts one recipient as a string; balanceAfter can be null or negative', async () => {
    const estimate = (balanceAfter: number | null) =>
      json(200, {
        data: { encoding: 'gsm7', characters: 5, segments_per_message: 1, recipients_count: 1, total_credits: 1, balance_after: balanceAfter },
      });
    const { client, requests } = harness([estimate(null), estimate(-3)]);

    expect((await client.sms.estimate({ body: 'Hello', recipients: '0812345678' })).isAffordable).toBe(false);
    expect((await client.sms.estimate({ body: 'Hello', recipients: '0812345678' })).balanceAfter).toBe(-3);
    expect((requests[0]!.json as { recipients: string[] }).recipients).toEqual(['+243812345678']);
  });
});

describe('sms.get', () => {
  it('encodes the id in the path', async () => {
    const { client, requests } = harness([json(200, { data: message() })]);

    const found = await client.sms.get('a/b c');

    expect(requests[0]!.method).toBe('GET');
    expect(requests[0]!.url).toBe('https://api.test/api/public/v1/sms/a%2Fb%20c');
    expect(found.id).toBe('9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10');
  });

  it('refuses an empty id', async () => {
    const { client, requests } = harness([]);
    await expect(client.sms.get(' ')).rejects.toBeInstanceOf(InvalidArgumentError);
    expect(requests).toHaveLength(0);
  });
});

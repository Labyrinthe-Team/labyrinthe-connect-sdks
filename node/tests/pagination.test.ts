import { describe, expect, it } from 'vitest';
import { InvalidArgumentError, Page, type Message } from '../src/index.js';
import { harness, message, page } from './helpers.js';

const ids = (messages: Message[]) => messages.map((m) => m.id);

describe('sms.list', () => {
  it('sends the filters as query parameters', async () => {
    const { client, requests } = harness([page([message()], { current_page: 2, last_page: 3, per_page: 50, total: 101 })]);

    const result = await client.sms.list({
      status: 'failed',
      recipient: '0812345678',
      reference: 'order-1042',
      from: '2026-11-01',
      to: new Date('2026-11-30T23:59:59.999Z'),
      page: 2,
      perPage: 50,
    });

    const url = new URL(requests[0]!.url);
    expect(url.pathname).toBe('/api/public/v1/sms');
    expect(Object.fromEntries(url.searchParams)).toEqual({
      status: 'failed',
      recipient: '243812345678',
      reference: 'order-1042',
      from: '2026-11-01',
      to: '2026-11-30T23:59:59Z',
      per_page: '50',
      page: '2',
    });
    expect(result).toBeInstanceOf(Page);
    expect(result.currentPage).toBe(2);
    expect(result.lastPage).toBe(3);
    expect(result.perPage).toBe(50);
    expect(result.total).toBe(101);
    expect(result.hasMore).toBe(true);
    expect(result.data[0]!.creditsCharged).toBe(1);
  });

  it('defaults to page 1, 15 per page, no filter', async () => {
    const { client, requests } = harness([page([], { current_page: 1, last_page: 1, total: 0 })]);

    const result = await client.sms.list();

    expect(new URL(requests[0]!.url).search).toBe('?per_page=15&page=1');
    expect(result.hasMore).toBe(false);
    expect(await result.nextPage()).toBeNull();
  });

  it('is lazy: nothing is sent until awaited or iterated, and only once', async () => {
    const { client, requests } = harness([page([], { current_page: 1, last_page: 1, total: 0 })]);

    const pending = client.sms.list();
    expect(requests).toHaveLength(0);
    const [a, b] = await Promise.all([pending, pending]);
    expect(a).toBe(b);
    expect(requests).toHaveLength(1);
  });

  it('iterates every item of every page with for await', async () => {
    const { client, requests } = harness([
      page([message({ id: 'm1' }), message({ id: 'm2' })], { current_page: 1, last_page: 3, per_page: 2, total: 5 }),
      page([message({ id: 'm3' }), message({ id: 'm4' })], { current_page: 2, last_page: 3, per_page: 2, total: 5 }),
      page([message({ id: 'm5' })], { current_page: 3, last_page: 3, per_page: 2, total: 5 }),
    ]);

    const seen: Message[] = [];
    for await (const m of client.sms.list({ status: 'delivered', perPage: 2 })) {
      seen.push(m);
    }

    expect(ids(seen)).toEqual(['m1', 'm2', 'm3', 'm4', 'm5']);
    expect(requests.map((r) => new URL(r.url).searchParams.get('page'))).toEqual(['1', '2', '3']);
    // Les filtres sont conservés d'une page à l'autre.
    expect(requests.every((r) => new URL(r.url).searchParams.get('status') === 'delivered')).toBe(true);
  });

  it('stops fetching when the loop breaks', async () => {
    const { client, requests } = harness([
      page([message({ id: 'm1' }), message({ id: 'm2' })], { current_page: 1, last_page: 9, per_page: 2, total: 18 }),
    ]);

    for await (const m of client.sms.list()) {
      if (m.id === 'm1') break;
    }

    expect(requests).toHaveLength(1);
  });

  it('walks pages manually with nextPage(), and a Page is iterable from itself', async () => {
    const { client } = harness([
      page([message({ id: 'm1' })], { current_page: 1, last_page: 2, per_page: 1, total: 2 }),
      page([message({ id: 'm2' })], { current_page: 2, last_page: 2, per_page: 1, total: 2 }),
    ]);

    const first = await client.sms.list({ perPage: 1 });
    const all: Message[] = [];
    for await (const m of first) all.push(m);

    expect(ids(all)).toEqual(['m1', 'm2']);
    expect(JSON.parse(JSON.stringify(first))).toMatchObject({ currentPage: 1, lastPage: 2, total: 2 });
  });

  it('validates page and perPage synchronously', () => {
    const { client } = harness([]);
    expect(() => client.sms.list({ page: 0 })).toThrow(InvalidArgumentError);
    expect(() => client.sms.list({ perPage: 101 })).toThrow(InvalidArgumentError);
    expect(() => client.sms.list({ perPage: 0 })).toThrow(InvalidArgumentError);
    expect(() => client.sms.list({ from: new Date('nope') })).toThrow(InvalidArgumentError);
  });

  it('propagates errors through await and iteration', async () => {
    const { client } = harness([new Error('boom'), new Error('boom')], { maxRetries: 0 });
    await expect(client.sms.list()).rejects.toThrow(/Could not reach/);
    await expect(
      (async () => {
        for await (const _ of client.sms.list()) {
          // jamais atteint
        }
      })(),
    ).rejects.toThrow(/Could not reach/);
  });
});

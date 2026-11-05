import { afterEach, describe, expect, it, vi } from 'vitest';
import { Webhooks, type WebhookEvent } from '../src/index.js';
import { labyrintheWebhookHandler } from '../src/next.js';

const SECRET = 'whsec_next';

function payload(event = 'message.status_changed'): string {
  return JSON.stringify({
    id: 'evt-1',
    event,
    data: { message_id: 'm-1', recipient: '243812345678', status: 'delivered', provider_message_id: null, campaign_id: null },
    timestamp: Math.floor(Date.now() / 1000),
  });
}

async function request(body: string, signature?: string): Promise<Request> {
  return new Request('https://shop.test/api/webhooks/labyrinthe', {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-signature': signature ?? (await Webhooks.sign(body, SECRET)) },
    body,
  });
}

afterEach(() => {
  vi.unstubAllEnvs();
  vi.restoreAllMocks();
});

describe('@labyrinthe/connect/next', () => {
  it('verifies the request and answers 200 after onEvent', async () => {
    const events: WebhookEvent[] = [];
    const POST = labyrintheWebhookHandler({ secret: SECRET, onEvent: (event) => void events.push(event) });

    const res = await POST(await request(payload()));

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ received: true });
    expect(events[0]).toMatchObject({ event: 'message.status_changed', data: { messageId: 'm-1', status: 'delivered' } });
  });

  it('reads the secret from LABYRINTHE_WEBHOOK_SECRET and lets onEvent return its own Response', async () => {
    vi.stubEnv('LABYRINTHE_WEBHOOK_SECRET', SECRET);
    const POST = labyrintheWebhookHandler({ onEvent: () => new Response(null, { status: 204 }) });

    expect((await POST(await request(payload()))).status).toBe(204);
  });

  it('answers 400 to an invalid signature or payload', async () => {
    const onEvent = vi.fn();
    const POST = labyrintheWebhookHandler({ secret: SECRET, onEvent });

    const bad = await POST(await request(payload(), `sha256=${'f'.repeat(64)}`));
    const unsigned = await POST(await request(payload(), ''));
    const notJson = await POST(await request('nope'));

    expect(bad.status).toBe(400);
    expect(await bad.json()).toMatchObject({ error: { code: 'SIGNATURE_VERIFICATION_FAILED' } });
    expect(unsigned.status).toBe(400);
    expect(notJson.status).toBe(400);
    expect(await notJson.json()).toMatchObject({ error: { code: 'INVALID_WEBHOOK_PAYLOAD' } });
    expect(onEvent).not.toHaveBeenCalled();
  });

  it('answers 500 when onEvent throws (the delivery is retried)', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const POST = labyrintheWebhookHandler({
      secret: SECRET,
      onEvent: () => {
        throw new Error('database down');
      },
    });

    const res = await POST(await request(payload()));

    expect(res.status).toBe(500);
    expect(await res.json()).toMatchObject({ error: { code: 'WEBHOOK_HANDLER_FAILED' } });
  });

  it('answers 500 without a secret', async () => {
    vi.stubEnv('LABYRINTHE_WEBHOOK_SECRET', '');
    const POST = labyrintheWebhookHandler({ onEvent: () => undefined });
    expect((await POST(await request(payload()))).status).toBe(500);
  });
});

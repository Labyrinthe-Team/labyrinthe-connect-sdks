import type { AddressInfo } from 'node:net';
import express from 'express';
import { afterEach, describe, expect, it } from 'vitest';
import { labyrintheWebhook, type WebhookRequest } from '../src/express.js';
import { Webhooks, type WebhookEvent } from '../src/index.js';

const SECRET = 'whsec_express';
const servers: { close(): void }[] = [];

afterEach(() => {
  for (const server of servers.splice(0)) server.close();
});

async function start(app: express.Express): Promise<string> {
  return new Promise((resolve) => {
    const server = app.listen(0, '127.0.0.1', () => {
      resolve(`http://127.0.0.1:${String((server.address() as AddressInfo).port)}`);
    });
    servers.push(server);
  });
}

function body(): string {
  return JSON.stringify({
    id: 'evt-1',
    event: 'balance.low',
    data: { balance: 950, threshold: 1000 },
    timestamp: Math.floor(Date.now() / 1000),
  });
}

async function post(url: string, payload: string, signature?: string): Promise<{ status: number; json: unknown }> {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-signature': signature ?? (await Webhooks.sign(payload, SECRET)) },
    body: payload,
  });
  return { status: res.status, json: await res.json().catch(() => null) };
}

describe('@labyrinthe/connect/express', () => {
  it('verifies the raw body, calls onEvent and answers 200', async () => {
    const events: WebhookEvent[] = [];
    const app = express();
    app.post('/webhooks', labyrintheWebhook({ secret: SECRET, onEvent: (event) => void events.push(event) }));
    app.use(express.json());
    const url = await start(app);

    const res = await post(`${url}/webhooks`, body());

    expect(res).toEqual({ status: 200, json: { received: true } });
    expect(events).toHaveLength(1);
    expect(events[0]).toMatchObject({ event: 'balance.low', data: { balance: 950, threshold: 1000 } });
  });

  it('answers 400 to a bad signature, without calling onEvent', async () => {
    let called = false;
    const app = express();
    app.post('/webhooks', labyrintheWebhook({ secret: SECRET, onEvent: () => (called = true) }));
    const url = await start(app);

    const res = await post(`${url}/webhooks`, body(), `sha256=${'0'.repeat(64)}`);

    expect(res.status).toBe(400);
    expect(res.json).toMatchObject({ error: { code: 'SIGNATURE_VERIFICATION_FAILED' } });
    expect(called).toBe(false);
  });

  it('works after express.raw(), and sets req.labyrintheEvent without onEvent', async () => {
    const app = express();
    app.post('/webhooks', express.raw({ type: 'application/json' }), labyrintheWebhook({ secret: SECRET }), (req, res) => {
      res.json({ type: (req as WebhookRequest).labyrintheEvent?.event });
    });
    const url = await start(app);

    expect(await post(`${url}/webhooks`, body())).toEqual({ status: 200, json: { type: 'balance.low' } });
  });

  it('fails loudly when the body was already parsed by express.json()', async () => {
    const errors: unknown[] = [];
    const app = express();
    app.use(express.json());
    app.post('/webhooks', labyrintheWebhook({ secret: SECRET, onEvent: () => undefined }));
    app.use((error: unknown, _req: express.Request, res: express.Response, _next: express.NextFunction) => {
      errors.push(error);
      res.status(500).end();
    });
    const url = await start(app);

    expect((await post(`${url}/webhooks`, body())).status).toBe(500);
    expect(String(errors[0])).toMatch(/already parsed/);
  });

  it('passes onEvent errors to next() (500: the delivery will be retried)', async () => {
    const app = express();
    app.post(
      '/webhooks',
      labyrintheWebhook({
        secret: SECRET,
        onEvent: () => {
          throw new Error('database down');
        },
      }),
    );
    app.use((_error: unknown, _req: express.Request, res: express.Response, _next: express.NextFunction) => {
      res.status(500).json({ failed: true });
    });
    const url = await start(app);

    expect(await post(`${url}/webhooks`, body())).toEqual({ status: 500, json: { failed: true } });
  });
});

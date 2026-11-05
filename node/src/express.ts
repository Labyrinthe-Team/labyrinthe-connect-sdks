/**
 * Express (and Connect / plain `node:http`) webhook middleware.
 *
 *     import express from 'express';
 *     import { labyrintheWebhook } from '@labyrinthe/connect/express';
 *
 *     app.post('/webhooks/labyrinthe', labyrintheWebhook({
 *       secret: process.env.LABYRINTHE_WEBHOOK_SECRET!,
 *       onEvent: async (event) => { … },
 *     }));
 *
 * Mount it BEFORE `express.json()` for this route (or use `express.raw({ type: 'application/json' })`):
 * the signature is computed on the raw body.
 */
import {
  InvalidWebhookPayloadError,
  SignatureVerificationError,
  Webhooks,
  type WebhookEvent,
} from '@labyrinthe/connect';

/** The parts of an Express / Node request the middleware reads. */
export interface WebhookRequest extends AsyncIterable<unknown> {
  headers: Record<string, string | string[] | undefined>;
  body?: unknown;
  /** Set by the middleware when there is no `onEvent`. */
  labyrintheEvent?: WebhookEvent;
}

/** The parts of an Express / Node response the middleware uses. */
export interface WebhookResponse {
  statusCode: number;
  headersSent?: boolean;
  setHeader(name: string, value: string): unknown;
  end(body?: string): unknown;
}

export type NextFunction = (error?: unknown) => void;

export interface LabyrintheWebhookOptions {
  /** Endpoint secret (client area > Developers > Webhooks). Defaults to `process.env.LABYRINTHE_WEBHOOK_SECRET`. */
  secret?: string;
  /** Max age of a delivery, in seconds (default 300; 0 disables the check). */
  toleranceSeconds?: number;
  /**
   * Handles a verified event; the middleware then answers 200. If it throws,
   * the error goes to `next(error)` (Express answers 500 and the delivery is
   * retried). Without `onEvent`, the event is set on `req.labyrintheEvent`
   * and `next()` is called.
   */
  onEvent?: (event: WebhookEvent, req: WebhookRequest, res: WebhookResponse) => unknown;
}

export function labyrintheWebhook(
  options: LabyrintheWebhookOptions = {},
): (req: WebhookRequest, res: WebhookResponse, next: NextFunction) => Promise<void> {
  return async (req, res, next) => {
    let event: WebhookEvent;
    try {
      const secret = options.secret ?? readEnv('LABYRINTHE_WEBHOOK_SECRET');
      if (secret === undefined || secret === '') {
        throw new Error('No webhook secret: pass `secret` or set LABYRINTHE_WEBHOOK_SECRET.');
      }
      const rawBody = await readRawBody(req);
      const signature = header(req, 'x-signature');
      event = await Webhooks.constructEvent(
        rawBody,
        signature,
        secret,
        options.toleranceSeconds === undefined ? {} : { toleranceSeconds: options.toleranceSeconds },
      );
    } catch (error) {
      if (error instanceof SignatureVerificationError || error instanceof InvalidWebhookPayloadError) {
        send(res, 400, { error: { code: error.code, message: error.message } });
        return;
      }
      next(error);
      return;
    }

    if (options.onEvent === undefined) {
      req.labyrintheEvent = event;
      next();
      return;
    }
    try {
      await options.onEvent(event, req, res);
      if (res.headersSent !== true) {
        send(res, 200, { received: true });
      }
    } catch (error) {
      next(error);
    }
  };
}

async function readRawBody(req: WebhookRequest): Promise<string | Uint8Array> {
  const body = req.body;
  if (typeof body === 'string' || body instanceof Uint8Array) {
    return body; // express.raw() / express.text()
  }
  if (body !== undefined && body !== null && !(typeof body === 'object' && Object.keys(body).length === 0)) {
    throw new Error(
      'The webhook body was already parsed (express.json()?): mount labyrintheWebhook() before it, ' +
        "or use express.raw({ type: 'application/json' }) on this route.",
    );
  }
  // Flux non lu : on lit les octets bruts.
  const chunks: Uint8Array[] = [];
  let length = 0;
  for await (const chunk of req) {
    const bytes = typeof chunk === 'string' ? new TextEncoder().encode(chunk) : (chunk as Uint8Array);
    chunks.push(bytes);
    length += bytes.length;
  }
  const bytes = new Uint8Array(length);
  let offset = 0;
  for (const chunk of chunks) {
    bytes.set(chunk, offset);
    offset += chunk.length;
  }
  return bytes;
}

function header(req: WebhookRequest, name: string): string | undefined {
  const value = req.headers[name];
  return Array.isArray(value) ? value[0] : value;
}

function send(res: WebhookResponse, status: number, body: unknown): void {
  res.statusCode = status;
  res.setHeader('Content-Type', 'application/json');
  res.end(JSON.stringify(body));
}

function readEnv(name: string): string | undefined {
  try {
    return (globalThis as { process?: { env?: Record<string, string | undefined> } }).process?.env?.[name];
  } catch {
    return undefined;
  }
}

export type { WebhookEvent } from '@labyrinthe/connect';

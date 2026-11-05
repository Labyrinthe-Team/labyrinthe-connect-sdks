/**
 * Webhook handler for Next.js App Router Route Handlers — and any runtime
 * built on the Fetch API `Request` / `Response` (Vercel edge, Deno, Bun, Hono…).
 *
 *     // app/api/webhooks/labyrinthe/route.ts
 *     import { labyrintheWebhookHandler } from '@labyrinthe/connect/next';
 *
 *     export const POST = labyrintheWebhookHandler({
 *       secret: process.env.LABYRINTHE_WEBHOOK_SECRET!,
 *       onEvent: async (event) => { … },
 *     });
 */
import {
  InvalidWebhookPayloadError,
  SignatureVerificationError,
  Webhooks,
  type WebhookEvent,
} from '@labyrinthe/connect';

export interface LabyrintheWebhookHandlerOptions {
  /** Endpoint secret (client area > Developers > Webhooks). Defaults to `process.env.LABYRINTHE_WEBHOOK_SECRET`. */
  secret?: string;
  /** Max age of a delivery, in seconds (default 300; 0 disables the check). */
  toleranceSeconds?: number;
  /**
   * Handles a verified event. Return nothing to answer 200, or your own
   * `Response`. If it throws, the handler answers 500 and the delivery is
   * retried later. Answer within 10 seconds: queue slow work.
   */
  onEvent: (event: WebhookEvent, request: Request) => unknown;
}

export function labyrintheWebhookHandler(
  options: LabyrintheWebhookHandlerOptions,
): (request: Request) => Promise<Response> {
  return async (request) => {
    const secret = options.secret ?? readEnv('LABYRINTHE_WEBHOOK_SECRET');
    if (secret === undefined || secret === '') {
      return json(500, { error: { code: 'WEBHOOK_SECRET_MISSING', message: 'No webhook secret configured.' } });
    }

    let event: WebhookEvent;
    try {
      // Octets exacts du corps : la signature porte sur le corps brut.
      const rawBody = new Uint8Array(await request.arrayBuffer());
      event = await Webhooks.constructEvent(
        rawBody,
        request.headers.get('x-signature'),
        secret,
        options.toleranceSeconds === undefined ? {} : { toleranceSeconds: options.toleranceSeconds },
      );
    } catch (error) {
      if (error instanceof SignatureVerificationError || error instanceof InvalidWebhookPayloadError) {
        return json(400, { error: { code: error.code, message: error.message } });
      }
      throw error;
    }

    try {
      const result = await options.onEvent(event, request);
      return result instanceof Response ? result : json(200, { received: true });
    } catch (error) {
      const logger = (globalThis as { console?: { error?: (...args: unknown[]) => void } }).console;
      logger?.error?.('Labyrinthe webhook handler failed', { event: event.event, id: event.id }, error);
      return json(500, { error: { code: 'WEBHOOK_HANDLER_FAILED', message: 'The webhook handler failed.' } });
    }
  };
}

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function readEnv(name: string): string | undefined {
  try {
    return (globalThis as { process?: { env?: Record<string, string | undefined> } }).process?.env?.[name];
  } catch {
    return undefined;
  }
}

export type { WebhookEvent } from '@labyrinthe/connect';

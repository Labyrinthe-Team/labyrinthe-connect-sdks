/**
 * Next.js App Router: app/api/webhooks/labyrinthe/route.ts
 * Works on the Node.js and Edge runtimes. Set LABYRINTHE_WEBHOOK_SECRET in your environment.
 */
import { labyrintheWebhookHandler } from '@labyrinthe/connect/next';

export const POST = labyrintheWebhookHandler({
  onEvent: async (event) => {
    if (event.event === 'message.status_changed') {
      // e.g. await db.message.update({ where: { providerId: event.data.messageId }, data: { status: event.data.status } });
      console.log(event.data.messageId, event.data.status);
    }
    // Answer within 10 s: queue slow work. Throwing answers 500 and the delivery is retried.
    await Promise.resolve();
  },
});

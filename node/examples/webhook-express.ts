/**
 * Receive webhooks with Express.
 *
 *     LABYRINTHE_WEBHOOK_SECRET=… npx tsx examples/webhook-express.ts
 */
import express from 'express';
import { labyrintheWebhook } from '@labyrinthe/connect/express';

const app = express();
const processed = new Set<string>(); // use your database in production

// Mount the webhook route BEFORE express.json(): the signature is computed on the raw body.
app.post(
  '/webhooks/labyrinthe',
  labyrintheWebhook({
    secret: process.env['LABYRINTHE_WEBHOOK_SECRET'] ?? '',
    onEvent: (event) => {
      if (processed.has(event.id)) return; // deliveries can repeat: deduplicate on id
      processed.add(event.id);

      switch (event.event) {
        case 'message.status_changed':
          console.log(`Message ${event.data.messageId} is now ${event.data.status}`);
          break;
        case 'campaign.completed':
          console.log(`Campaign "${event.data.name}" done:`, event.data.messagesByStatus);
          break;
        case 'balance.low':
          console.log(`Balance ${event.data.balance} below ${event.data.threshold}: buy credits`);
          break;
        case 'test.ping':
          console.log('Test ping received');
          break;
        case 'unknown':
          console.log(`New event type ${event.type}`, event.data);
          break;
      }
    },
  }),
);

app.use(express.json());
app.listen(3000, () => {
  console.log('Listening on http://localhost:3000/webhooks/labyrinthe');
});

/**
 * Send an SMS and follow its status.
 *
 *     LABYRINTHE_API_KEY=lsk_test_… npx tsx examples/send-sms.ts 0812345678
 */
import { InsufficientCreditsError, Labyrinthe, RecipientNotSendableError, isFinalStatus } from '@labyrinthe/connect';

const client = new Labyrinthe(); // reads LABYRINTHE_API_KEY (use a lsk_test_ key: nothing is sent, nothing is billed)
const to = process.argv[2] ?? '0812345678';

const estimate = await client.sms.estimate({ body: 'Your order 1042 is ready.', recipients: to });
console.log(`Cost: ${estimate.totalCredits} credit(s), ${estimate.encoding}, affordable: ${String(estimate.isAffordable)}`);

try {
  const message = await client.sms.send({
    to,
    body: 'Your order 1042 is ready.',
    reference: 'order-1042',
    idempotencyKey: 'order-1042-ready', // replaying this script within 24 h sends nothing again
  });
  console.log(`Queued ${message.id} → ${message.recipient} (${message.status})`);

  const latest = await client.sms.get(message.id);
  console.log(isFinalStatus(latest.status) ? `Final status: ${latest.status}` : `Still ${latest.status}: use webhooks`);
} catch (error) {
  if (error instanceof RecipientNotSendableError) {
    console.error('Nothing was sent:', error.rejected);
  } else if (error instanceof InsufficientCreditsError) {
    console.error(`Not enough credits: ${String(error.available)} available, ${String(error.required)} required`);
  } else {
    throw error;
  }
}

for await (const message of client.sms.list({ reference: 'order-1042' })) {
  console.log(message.createdAt?.toISOString(), message.status);
}

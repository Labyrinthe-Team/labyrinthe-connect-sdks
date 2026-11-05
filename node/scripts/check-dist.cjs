// Vérifie le build CommonJS : require() par le nom du package, classes partagées entre sous-chemins.
const assert = require('node:assert/strict');

const core = require('@labyrinthe/connect');
const { labyrintheWebhook } = require('@labyrinthe/connect/express');
const { labyrintheWebhookHandler } = require('@labyrinthe/connect/next');

assert.equal(typeof core.Labyrinthe, 'function');
assert.equal(typeof labyrintheWebhook, 'function');
assert.equal(typeof labyrintheWebhookHandler, 'function');
assert.ok(new core.RateLimitError('x', 'TOO_MANY_REQUESTS', 429) instanceof core.LabyrintheError);
assert.equal(new core.Labyrinthe({ apiKey: 'lsk_live_x' }).isLiveMode, true);

(async () => {
  const body = '{"id":"e","event":"balance.low","data":{"balance":1,"threshold":2},"timestamp":1}';
  const event = await core.Webhooks.constructEvent(body, await core.Webhooks.sign(body, 's'), 's', { toleranceSeconds: 0 });
  assert.equal(event.event, 'balance.low');
  console.log(`CJS OK (${core.VERSION}) on ${process.version}`);
})().catch((error) => {
  console.error(error);
  process.exit(1);
});

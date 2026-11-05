// Vérifie le build CommonJS : require() par le nom du package, sans DOM (SSR).
const assert = require('node:assert/strict');

const widget = require('@labyrinthe/otp-widget');
const { LabyrintheOtp } = require('@labyrinthe/otp-widget/react');
const { createOtpHandlers, memoryStore } = require('@labyrinthe/otp-widget/server');

assert.equal(widget.defineLabyrintheOtp(), undefined);
assert.equal(widget.normalizePhone('+33 6 12 34 56 78'), '33612345678');
assert.equal(typeof LabyrintheOtp, 'object');
assert.equal(typeof createOtpHandlers, 'function');
assert.equal(typeof memoryStore().get, 'function');
assert.throws(() => createOtpHandlers({ client: {}, secret: 'short', onVerified: () => undefined }), /32 characters/);

console.log(`CJS OK (${widget.VERSION}) on ${process.version}`);

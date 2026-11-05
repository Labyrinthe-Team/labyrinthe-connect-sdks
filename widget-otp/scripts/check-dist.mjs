// Vérifie le build ESM : imports par le nom du package (exports), import sans DOM sans effet (SSR),
// aucun module Node dans les bundles (navigateur, edge, Deno, Bun), aucune clé ni otp_id dans le widget.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const widget = await import('@labyrinthe/otp-widget');
const react = await import('@labyrinthe/otp-widget/react');
const server = await import('@labyrinthe/otp-widget/server');

// Sans DOM : l'import ne définit rien et ne lève rien.
assert.equal(typeof widget.defineLabyrintheOtp, 'function');
assert.equal(widget.defineLabyrintheOtp(), undefined);
assert.equal(widget.normalizePhone('0812345678'), '243812345678');
assert.equal(widget.TAG_NAME, 'labyrinthe-otp');
assert.equal(typeof react.LabyrintheOtp, 'object'); // forwardRef
assert.equal(typeof server.createOtpHandlers, 'function');
assert.equal(typeof server.memoryStore, 'function');

// Le helper serveur fonctionne depuis le build (Web Crypto, Fetch).
const otp = server.createOtpHandlers({
  client: {
    otp: {
      send: async () => ({ otpId: 'otp-secret-id', expiresAt: new Date(Date.now() + 60_000), maxAttempts: 3, testCode: null }),
      verify: async () => ({ verified: true, reason: null }),
    },
  },
  secret: 'x'.repeat(32),
  onVerified: () => ({ ok: true }),
});
const headers = { 'content-type': 'application/json', host: 'localhost' };
const sent = await otp.send(new Request('http://localhost/api/otp/send', { method: 'POST', headers, body: '{"phone":"0812345678"}' }));
const sentText = await sent.text();
assert.equal(sent.status, 200, sentText);
assert.doesNotMatch(sentText + sent.headers.get('set-cookie'), /otp-secret-id/);
const cookie = sent.headers.get('set-cookie').split(';')[0];
const verified = await otp.verify(new Request('http://localhost/api/otp/verify', { method: 'POST', headers: { ...headers, cookie }, body: '{"code":"1234"}' }));
assert.deepEqual(await verified.json(), { verified: true, data: { ok: true } });

for (const file of ['index.js', 'index.cjs', 'react.js', 'react.cjs', 'server.js', 'server.cjs', 'labyrinthe-otp.min.js']) {
  const code = readFileSync(new URL(`../dist/${file}`, import.meta.url), 'utf8');
  assert.doesNotMatch(code, /(from\s*["']node:|require\(\s*["']node:|import\(\s*["']node:)/, `${file} imports a node: module`);
  assert.doesNotMatch(code, /\bBuffer\./, `${file} uses Buffer`);
}
for (const file of ['react.js', 'react.cjs']) {
  const code = readFileSync(new URL(`../dist/${file}`, import.meta.url), 'utf8');
  assert.match(code, /^['"]use client['"];/, `${file} must start with "use client"`);
  assert.doesNotMatch(code, /customElements\.define\(/, `${file} must not bundle its own copy of the element`);
}
const widgetCode = readFileSync(new URL('../dist/labyrinthe-otp.min.js', import.meta.url), 'utf8');
assert.doesNotMatch(widgetCode, /otp_id|lsk_/, 'the browser bundle must not know about otp_id or API keys');

console.log(`ESM OK (${widget.VERSION}) on ${process.version}`);

// Vérifie le build ESM : imports par le nom du package (exports), API attendue,
// aucun import statique de module Node dans le cœur (runtimes edge, Deno, Bun).
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const core = await import('@labyrinthe/connect');
const express = await import('@labyrinthe/connect/express');
const next = await import('@labyrinthe/connect/next');

for (const name of ['Labyrinthe', 'Webhooks', 'PhoneNumber', 'LabyrintheError', 'ApiError', 'RateLimitError', 'SignatureVerificationError', 'MessageStatus', 'VERSION']) {
  assert.ok(name in core, `ESM export ${name} is missing`);
}
assert.equal(typeof express.labyrintheWebhook, 'function');
assert.equal(typeof next.labyrintheWebhookHandler, 'function');

const client = new core.Labyrinthe({ apiKey: 'lsk_test_check' });
assert.equal(client.isTestMode, true);
assert.equal(JSON.stringify(client).includes('check'), false);

// Web Crypto (global, ou repli sur Node 18).
const body = '{"id":"e","event":"test.ping","data":{},"timestamp":1}';
const signature = await core.Webhooks.sign(body, 'secret');
const event = await core.Webhooks.constructEvent(body, signature, 'secret', { toleranceSeconds: 0 });
assert.equal(event.event, 'test.ping');

// Le cœur et les helpers n'importent aucun module Node de façon statique.
for (const file of ['index.js', 'index.cjs', 'express.js', 'express.cjs', 'next.js', 'next.cjs']) {
  const code = readFileSync(new URL(`../dist/${file}`, import.meta.url), 'utf8');
  assert.doesNotMatch(code, /(from\s*["']node:|require\(\s*["']node:|import\(\s*["']node:)/, `${file} imports a node: module`);
  assert.doesNotMatch(code, /\bBuffer\./, `${file} uses Buffer`);
}

console.log(`ESM OK (${core.VERSION}) on ${process.version}`);

/**
 * Démo Express : page statique + les deux endpoints du widget.
 *
 *     npm install express @labyrinthe/connect @labyrinthe/otp-widget
 *     LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_OTP_SECRET=<openssl rand -hex 32> SHOW_TEST_CODE=1 npx tsx server.ts
 *     → http://localhost:3000
 *
 * The API key stays here, on the server. The browser only talks to /api/otp/*.
 */
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import express, { type Request } from 'express';
import { Labyrinthe } from '@labyrinthe/connect';
import { createOtpHandlers } from '@labyrinthe/otp-widget/server';

const require = createRequire(import.meta.url);
const app = express();

// Anti-abus minimal : 5 envois par IP et par 10 minutes (en production : Redis, captcha…).
const sendsByIp = new Map<string, number[]>();
function underIpLimit(ip: string): boolean {
  const now = Date.now();
  const recent = (sendsByIp.get(ip) ?? []).filter((at) => now - at < 10 * 60_000);
  sendsByIp.set(ip, [...recent, now]);
  return recent.length < 5;
}

const otp = createOtpHandlers({
  client: new Labyrinthe(), // LABYRINTHE_API_KEY (lsk_test_…: nothing is sent, nothing is billed)
  secret: process.env['LABYRINTHE_OTP_SECRET'] ?? '',
  // Code length / alphabet and resend delay come from the API (account's OTP settings).
  // Web OTP API (Chrome on Android): the host serving this page, e.g. 'shop.example.com'.
  ...(process.env['WEB_OTP_DOMAIN'] ? { webOtpDomain: process.env['WEB_OTP_DOMAIN'] } : {}),
  // Development only: shows the test code in the widget (refused when NODE_ENV=production).
  exposeTestCode: process.env['SHOW_TEST_CODE'] === '1',
  beforeSend: ({ request }) => underIpLimit((request as Request).ip ?? 'unknown'),
  onVerified: ({ phone }) => {
    // The ONLY place where the number is trusted: create your user session here
    // (e.g. `(request as Request).session.phone = phone` with express-session).
    console.log(`Verified ${phone.slice(0, 5)}…`);
    return { redirect: '/welcome.html' };
  },
  onError: (error) => {
    console.error('OTP error', (error as { code?: string }).code ?? error);
  },
});

app.use(express.json({ limit: '4kb' }));
app.post('/api/otp/send', otp.express.send);
app.post('/api/otp/verify', otp.express.verify);

// Bundle autonome du widget (aussi servi par les CDN : unpkg, jsDelivr).
app.get('/labyrinthe-otp.min.js', (_req, res) => {
  res.sendFile(require.resolve('@labyrinthe/otp-widget/cdn'));
});
app.use(express.static(fileURLToPath(new URL('./public', import.meta.url))));

app.listen(3000, () => {
  console.log('OTP demo on http://localhost:3000');
});

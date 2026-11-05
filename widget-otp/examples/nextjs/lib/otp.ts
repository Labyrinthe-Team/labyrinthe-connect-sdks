/**
 * Next.js App Router: one shared instance of the handlers (server only).
 *
 *     LABYRINTHE_API_KEY=lsk_test_…            (.env.local, never NEXT_PUBLIC_…)
 *     LABYRINTHE_OTP_SECRET=<64 hex characters>
 */
import { Labyrinthe } from '@labyrinthe/connect';
import { createOtpHandlers } from '@labyrinthe/otp-widget/server';

export const otp = createOtpHandlers({
  client: () => new Labyrinthe(), // créé au premier appel, pas au build
  secret: process.env['LABYRINTHE_OTP_SECRET'] ?? '',
  cookie: { path: '/api/otp' }, // le cookie n'est envoyé qu'aux deux routes OTP
  resendAfterSeconds: 60,
  exposeTestCode: process.env['NODE_ENV'] === 'development' && process.env['SHOW_TEST_CODE'] === '1',
  beforeSend: ({ request }) => {
    // Your anti-abuse checks: rate limit per IP (e.g. Upstash), captcha token…
    const ip = request instanceof Request ? request.headers.get('x-forwarded-for') : null;
    return ip !== 'blocked';
  },
  onVerified: async ({ phone, setCookie }) => {
    // Trust the number here: create your session (your auth library, database…).
    const token = await createAppSession(phone);
    setCookie(`app_session=${token}; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=2592000`);
    return { redirect: '/dashboard' };
  },
});

/** Placeholder: replace with your own session / auth system. */
function createAppSession(_phone: string): Promise<string> {
  return Promise.resolve(crypto.randomUUID());
}

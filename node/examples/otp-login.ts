/**
 * Phone verification with a one-time code, as a server would do it.
 * In a real app, `otpId` lives in the user's server-side session, never in the browser.
 *
 *     LABYRINTHE_API_KEY=lsk_test_… npx tsx examples/otp-login.ts 0812345678
 */
import { Labyrinthe, OtpResendTooSoonError } from '@labyrinthe/connect';

const client = new Labyrinthe();
const session: { otpId?: string } = {}; // stand-in for your session store

// 1. The user enters their phone number: send the code.
try {
  const otp = await client.otp.send({ phone: process.argv[2] ?? '0812345678', locale: 'fr' });
  session.otpId = otp.otpId;
  console.log(`Code sent, valid until ${otp.expiresAt.toISOString()} (${otp.maxAttempts} attempts)`);

  // 2. The user types the code. In test mode the API returns it, so the flow can be tested end to end.
  const typed = client.isTestMode ? (otp.testCode ?? '') : '123456';

  // 3. Verify it. A wrong code is not an exception.
  const result = await client.otp.verify({ otpId: session.otpId, code: typed });
  if (result.verified) {
    delete session.otpId;
    console.log('Phone verified: log the user in.');
  } else if (result.reason === 'invalid_code') {
    console.log('Wrong code, try again.');
  } else {
    console.log(`Please request a new code (${result.reason}).`);
  }
} catch (error) {
  if (error instanceof OtpResendTooSoonError) {
    console.log(`Wait ${String((error.retryAfterMs ?? 0) / 1000)} s before asking for a new code.`);
  } else {
    throw error;
  }
}

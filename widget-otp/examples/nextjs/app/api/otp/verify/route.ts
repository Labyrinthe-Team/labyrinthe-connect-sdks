// app/api/otp/verify/route.ts — otp_id reste dans le cookie chiffré, jamais dans le navigateur.
import { otp } from '../../../../lib/otp.js';

export const runtime = 'nodejs';
export const POST = otp.verify;

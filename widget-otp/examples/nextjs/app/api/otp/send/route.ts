// app/api/otp/send/route.ts — appelle Labyrinthe avec la clé API (serveur uniquement).
import { otp } from '../../../../lib/otp.js';

export const runtime = 'nodejs';
export const POST = otp.send;

/**
 * Outils de test : faux client Labyrinthe (même sémantique que le backend) et
 * routeur `fetch` qui relie le widget au vrai helper serveur, avec un pot à cookies.
 */
import {
  AccountSuspendedError,
  IdempotencyKeyUnresolvedError,
  IdempotencyRequestInProgressError,
  NotFoundError,
  OtpRateLimitedError,
  OtpResendTooSoonError,
  ServiceUnavailableError,
} from '@labyrinthe/connect';
import { createOtpHandlers, type OtpClient, type OtpHandlers, type OtpHandlersOptions } from '../src/server/index.js';

export const SECRET = 'test-secret-0123456789-abcdefghijklmnop';

interface FakeOtp {
  code: string;
  attempts: number;
  maxAttempts: number;
  status: 'pending' | 'verified' | 'failed' | 'expired';
  expiresAt: number;
}

type SendParams = Parameters<OtpClient['otp']['send']>[0];
type SendResult = Awaited<ReturnType<OtpClient['otp']['send']>>;

export interface FakeClient extends OtpClient {
  sent: SendParams[];
  /** Envois rejoués (même Idempotency-Key) : aucun SMS. */
  replays: number;
  verified: { otpId: string; code: string }[];
  otps: Map<string, FakeOtp>;
  /** Erreur levée au prochain envoi (puis remise à null). */
  nextSendError: unknown;
  nextVerifyError: unknown;
}

export interface FakeClientOptions {
  code?: string;
  maxAttempts?: number;
  ttlMs?: number;
  codeLength?: number;
  codeType?: 'numeric' | 'alphanumeric';
  resendAfterSeconds?: number;
  /** SDK < 1.1.0 / API < 1.2.0 : ni forme du code, ni délai, ni tentatives restantes. */
  legacy?: boolean;
}

export function fakeClient(options: FakeClientOptions = {}): FakeClient {
  let counter = 0;
  const byKey = new Map<string, SendResult>();
  const client: FakeClient = {
    sent: [],
    replays: 0,
    verified: [],
    otps: new Map(),
    nextSendError: null,
    nextVerifyError: null,
    otp: {
      send: (params) => {
        client.sent.push({ ...params });
        if (client.nextSendError !== null) {
          const error = client.nextSendError;
          client.nextSendError = null;
          return Promise.reject(error instanceof Error ? error : new Error('send failed'));
        }
        // Comme le backend : même Idempotency-Key = même réponse, sans nouveau SMS.
        const replayed = params.idempotencyKey === undefined ? undefined : byKey.get(params.idempotencyKey);
        if (replayed !== undefined) {
          client.replays += 1;
          return Promise.resolve(replayed);
        }
        // Comme le backend : un nouvel envoi invalide le code précédent du même numéro.
        for (const otp of client.otps.values()) {
          if (otp.status === 'pending') otp.status = 'expired';
        }
        counter += 1;
        const otpId = `9d5c0a3e-0000-4000-8000-${String(counter).padStart(12, '0')}`;
        const otp: FakeOtp = {
          code: options.code ?? '482913',
          attempts: 0,
          maxAttempts: options.maxAttempts ?? 3,
          status: 'pending',
          expiresAt: Date.now() + (options.ttlMs ?? 600_000),
        };
        client.otps.set(otpId, otp);
        const result: SendResult = {
          otpId,
          expiresAt: new Date(otp.expiresAt),
          maxAttempts: otp.maxAttempts,
          testCode: otp.code,
          ...(options.legacy === true
            ? {}
            : {
                codeLength: options.codeLength ?? otp.code.length,
                codeType: options.codeType ?? 'numeric',
                resendAfterSeconds: options.resendAfterSeconds ?? 60,
              }),
        };
        if (params.idempotencyKey !== undefined) byKey.set(params.idempotencyKey, result);
        return Promise.resolve(result);
      },
      verify: ({ otpId, code }) => {
        client.verified.push({ otpId, code });
        if (client.nextVerifyError !== null) {
          const error = client.nextVerifyError;
          client.nextVerifyError = null;
          return Promise.reject(error instanceof Error ? error : new Error('verify failed'));
        }
        const otp = client.otps.get(otpId);
        if (otp === undefined) {
          return Promise.reject(new NotFoundError('Vérification OTP introuvable.', 'NOT_FOUND', 404, {}, {}));
        }
        // Comme l'API 1.2.0 : `attempts_remaining` (absent en mode « legacy »).
        const remaining = (value: number | null) => (options.legacy === true ? {} : { attemptsRemaining: value });
        if (otp.status !== 'pending') {
          const reason = { verified: 'already_used', expired: 'expired', failed: 'max_attempts_reached' }[otp.status];
          return Promise.resolve({ verified: false, reason, ...remaining(reason === 'max_attempts_reached' ? 0 : null) });
        }
        otp.attempts += 1;
        if (code.replace(/\s+/g, '').toUpperCase() === otp.code) {
          otp.status = 'verified';
          return Promise.resolve({ verified: true, reason: null, ...remaining(null) });
        }
        if (otp.attempts >= otp.maxAttempts) {
          otp.status = 'failed';
          return Promise.resolve({ verified: false, reason: 'max_attempts_reached', ...remaining(0) });
        }
        return Promise.resolve({ verified: false, reason: 'invalid_code', ...remaining(otp.maxAttempts - otp.attempts) });
      },
    },
  };
  return client;
}

export const sdkErrors = {
  resendTooSoon: (seconds: number) =>
    new OtpResendTooSoonError('Patientez.', 'OTP_RESEND_TOO_SOON', 429, { retry_after_seconds: seconds }, {}),
  rateLimited: () => new OtpRateLimitedError('Plafond.', 'OTP_RATE_LIMITED', 429, { scope: 'phone', limit: 5 }, {}),
  serviceDisabled: () => new ServiceUnavailableError('Service coupé.', 'OTP_SERVICE_DISABLED', 503, {}, {}),
  accountSuspended: () => new AccountSuspendedError('Compte suspendu.', 'ACCOUNT_SUSPENDED', 403, {}, {}),
  inProgress: () =>
    new IdempotencyRequestInProgressError('En cours.', 'IDEMPOTENCY_REQUEST_IN_PROGRESS', 409, { retry_after_seconds: 5 }, { 'retry-after': '5' }),
  unresolved: () => new IdempotencyKeyUnresolvedError('Résultat inconnu.', 'IDEMPOTENCY_KEY_UNRESOLVED', 409, {}, {}),
};


export function handlers(client: OtpClient, options: Partial<OtpHandlersOptions> = {}): OtpHandlers {
  return createOtpHandlers({ client, secret: SECRET, onVerified: () => undefined, ...options });
}

/** Cookie « nom=valeur » d'un en-tête Set-Cookie, ou null s'il est effacé (Max-Age=0). */
export function cookiePair(setCookie: string): [string, string | null] {
  const [pair = ''] = setCookie.split(';');
  const index = pair.indexOf('=');
  const name = pair.slice(0, index);
  return [name, /Max-Age=0(;|$)/.test(setCookie) ? null : pair.slice(index + 1)];
}

export interface Exchange {
  url: string;
  requestBody: unknown;
  status: number;
  responseText: string;
  setCookies: string[];
}

/**
 * `fetch` du navigateur simulé : chaque appel passe par l'adaptateur Express du
 * vrai helper serveur (requête, en-têtes, cookies), sans réseau.
 */
export function browserFetch(otp: OtpHandlers, origin = 'http://localhost:3000') {
  const jar = new Map<string, string>();
  const exchanges: Exchange[] = [];
  const fetch = async (input: string | URL, init: RequestInit = {}): Promise<Response> => {
    const url = new URL(String(input), origin);
    const headers: Record<string, string> = { host: url.host, origin };
    // Comme Node : noms d'en-têtes en minuscules.
    new Headers(init.headers).forEach((value, name) => {
      headers[name.toLowerCase()] = value;
    });
    if (jar.size > 0) {
      headers['cookie'] = Array.from(jar, ([name, value]) => `${name}=${value}`).join('; ');
    }
    const requestBody = typeof init.body === 'string' ? (JSON.parse(init.body) as unknown) : undefined;
    const res = { statusCode: 200, headers: {} as Record<string, string | string[]>, text: '' };
    const handler = url.pathname.endsWith('/send') ? otp.express.send : otp.express.verify;
    await handler(
      {
        method: init.method ?? 'GET',
        headers,
        body: requestBody,
        originalUrl: url.pathname,
        protocol: url.protocol.replace(':', ''),
        async *[Symbol.asyncIterator]() {
          // Corps déjà lu (express.json()).
        },
      },
      {
        get statusCode() {
          return res.statusCode;
        },
        set statusCode(value: number) {
          res.statusCode = value;
        },
        setHeader(name: string, value: string | string[]) {
          res.headers[name.toLowerCase()] = value;
        },
        end(body?: string) {
          res.text = body ?? '';
        },
      },
      (error) => {
        throw error;
      },
    );
    const setCookies = ([] as string[]).concat(res.headers['set-cookie'] ?? []);
    for (const cookie of setCookies) {
      const [name, value] = cookiePair(cookie);
      if (value === null) jar.delete(name);
      else jar.set(name, value);
    }
    exchanges.push({ url: url.pathname, requestBody, status: res.statusCode, responseText: res.text, setCookies });
    return new Response(res.text, { status: res.statusCode, headers: { 'content-type': 'application/json' } });
  };
  return { fetch, jar, exchanges };
}

/** Laisse passer les promesses en attente (fetch simulé, handlers asynchrones, Web Crypto). */
export async function flush(times = 20): Promise<void> {
  for (let i = 0; i < times; i++) {
    await new Promise((resolve) => setTimeout(resolve, 0));
  }
}

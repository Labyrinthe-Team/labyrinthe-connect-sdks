/**
 * Server side of `<labyrinthe-otp>`: two endpoints that call Labyrinthe with
 * YOUR API key (through `@labyrinthe/connect`) and keep `otp_id` server-side.
 *
 *     import { Labyrinthe } from '@labyrinthe/connect';
 *     import { createOtpHandlers } from '@labyrinthe/otp-widget/server';
 *
 *     export const otp = createOtpHandlers({
 *       client: new Labyrinthe(),                       // LABYRINTHE_API_KEY
 *       secret: process.env.LABYRINTHE_OTP_SECRET,      // ≥ 32 random characters
 *       onVerified: async ({ phone }) => { … log the user in … },
 *     });
 *
 *     // Next.js App Router: app/api/otp/send/route.ts → export const POST = otp.send;
 *     // Express:            app.post('/api/otp/send', otp.express.send);
 *
 * What the browser gets: a masked number, expiry, attempts and resend delay;
 * never the API key, never `otp_id`, never the code (except `test_code` with a
 * test key when `exposeTestCode` is explicitly enabled in development).
 */
import { DEFAULT_COUNTRY_CODE, isCountryCode, maskPhone, normalizePhone } from '../phone.js';
import type {
  CodeType,
  ErrorBody,
  OtpFailureReason,
  SendSuccessBody,
  ServerErrorCode,
  VerifyFailureBody,
  VerifySuccessBody,
} from '../protocol.js';
import {
  Sealer,
  isSessionState,
  randomId,
  readCookie,
  serializeCookie,
  type CookieOptions,
  type OtpSessionState,
  type OtpSessionStore,
} from './session.js';

export { memoryStore, type CookieOptions, type OtpSessionState, type OtpSessionStore } from './session.js';
export type { ErrorBody, SendSuccessBody, ServerErrorCode, VerifyFailureBody, VerifySuccessBody } from '../protocol.js';

/**
 * The part of the `@labyrinthe/connect` client used here: pass a `Labyrinthe`
 * instance. (Structural type: no runtime import of the SDK.)
 */
export interface OtpClient {
  otp: {
    send(params: {
      phone: string;
      template?: string;
      locale?: 'fr' | 'en';
      webOtpDomain?: string;
      stripAccents?: boolean;
      idempotencyKey?: string;
    }): Promise<{
      otpId: string;
      expiresAt: Date;
      maxAttempts: number;
      /** `@labyrinthe/connect` ≥ 1.1.0 (API 1.2.0); absent or null with an older SDK. */
      codeLength?: number | null;
      codeType?: string | null;
      resendAfterSeconds?: number | null;
      readonly testCode: string | null;
    }>;
    verify(params: { otpId: string; code: string }): Promise<{
      verified: boolean;
      reason: string | null;
      /** `@labyrinthe/connect` ≥ 1.1.0; absent or null with an older SDK (then estimated). */
      attemptsRemaining?: number | null;
    }>;
  };
}

export interface BeforeSendContext {
  /** Normalised phone number (international, no "+"). */
  phone: string;
  /** Your framework's request: the Fetch `Request` (Next.js, edge…) or the Express `req` (e.g. `req.session`). */
  request: Request | ExpressLikeRequest;
}

export interface VerifiedContext extends BeforeSendContext {
  /** Headers added to the JSON response. */
  responseHeaders: Headers;
  /** Adds a `Set-Cookie` header to the response (e.g. your own session cookie). */
  setCookie: (cookie: string) => void;
}

export interface OtpHandlersOptions {
  /** A `Labyrinthe` client from `@labyrinthe/connect` (or a function returning it, called on first use). */
  client: OtpClient | (() => OtpClient);
  /**
   * Secret used to encrypt the session cookie, at least 32 characters.
   * Defaults to `process.env.LABYRINTHE_OTP_SECRET`. Not your API key.
   */
  secret?: string;
  /** Keep the state in your own store; the cookie then only holds a random session id. */
  store?: OtpSessionStore;
  cookie?: CookieOptions;
  /** Added to local numbers. Default "243" (DR Congo). */
  defaultCountryCode?: string;
  /** SMS template (must contain "{code}"). Default: the account's OTP settings. */
  template?: string;
  /** SMS language. Default: the widget's locale, then the account's OTP settings. */
  locale?: 'fr' | 'en';
  /**
   * Web OTP API: host name of YOUR site, as seen in the browser address bar
   * (e.g. "app.example.com", no scheme, port or path). The API ends the SMS with
   * the line `@app.example.com #123456`, and Chrome on Android then offers to
   * fill the code in `<labyrinthe-otp>` (top-level page on that exact host, HTTPS).
   */
  webOtpDomain?: string;
  /** Replace accents in the SMS with ASCII (GSM-7, often half the cost). Default false. */
  stripAccents?: boolean;
  /**
   * Override of the anti-resend delay sent to the widget. Default: the
   * `resend_after_seconds` returned by the API (account's OTP settings), then 60.
   * The API still enforces the real delay.
   */
  resendAfterSeconds?: number;
  /**
   * Override of the code length / alphabet sent to the widget. Default: the
   * `code_length` / `code_type` returned by the API (account's OTP settings).
   * Only needed with an API older than 1.2.0.
   */
  codeLength?: number;
  codeType?: CodeType;
  /**
   * DEVELOPMENT ONLY: return the `test_code` of a `lsk_test_` key to the
   * browser. Refused when NODE_ENV is "production". Never available with a live key.
   */
  exposeTestCode?: boolean;
  /** Origins allowed to call the endpoints. Default: the request's own host only. */
  allowedOrigins?: string[];
  /**
   * Your anti-abuse checks before a billed SMS is sent (rate limit per IP or
   * account, captcha, allow-list…). Return false to refuse (403 `forbidden`).
   */
  beforeSend?: (context: BeforeSendContext) => boolean | undefined | Promise<boolean | undefined>;
  /**
   * Called once the code is verified: this is where you trust the phone number
   * (log the user in, mark the number as verified…). Its return value is sent
   * to the browser as `data` (keep it JSON and non-secret). The browser event
   * alone must never be trusted.
   */
  onVerified: (context: VerifiedContext) => unknown;
  /** Called with unexpected and upstream errors, for your logs (no key, no code, no otp_id inside). */
  onError?: (error: unknown, context: { action: 'send' | 'verify' }) => void;
}

export type FetchHandler = (request: Request) => Promise<Response>;

export interface ExpressLikeRequest extends AsyncIterable<unknown> {
  method?: string;
  headers: Record<string, string | string[] | undefined>;
  body?: unknown;
  originalUrl?: string;
  url?: string;
  protocol?: string;
}

export interface ExpressLikeResponse {
  statusCode: number;
  setHeader(name: string, value: string | string[]): unknown;
  end(body?: string): unknown;
}

export type ExpressHandler = (req: ExpressLikeRequest, res: ExpressLikeResponse, next: (error?: unknown) => void) => Promise<void>;

export interface OtpHandlers {
  /** Fetch API handler (Next.js App Router, Hono, Bun, Deno, edge). */
  send: FetchHandler;
  verify: FetchHandler;
  /** Express / Connect / `node:http` handlers (with or without `express.json()`). */
  express: { send: ExpressHandler; verify: ExpressHandler };
}

interface Result {
  status: number;
  body: SendSuccessBody | VerifySuccessBody | VerifyFailureBody | ErrorBody;
  headers: Headers;
  cookies: string[];
}

const MAX_BODY_BYTES = 4096;
const MAX_CODE_LENGTH = 20;
const DEFAULT_RESEND_AFTER = 60;
/** L'état survit un peu à l'expiration du code, pour répondre « expired » plutôt que « no_pending_verification ». */
const COOKIE_GRACE_SECONDS = 300;

/** Requête entrante, indépendante du framework (Fetch ou Express). */
interface Incoming {
  method: string;
  url: string;
  header(name: string): string | null;
  text(): Promise<string>;
  source: Request | ExpressLikeRequest;
}

class HttpError extends Error {
  constructor(
    readonly status: number,
    readonly code: ServerErrorCode,
    message: string,
    readonly retryAfterSeconds?: number,
    /** Maintenance (FEATURE_DISABLED / OTP_SERVICE_DISABLED) : message de l'administrateur et date de retour. */
    readonly maintenance?: { adminMessage?: string; until?: string },
  ) {
    super(message);
  }
}

export function createOtpHandlers(options: OtpHandlersOptions): OtpHandlers {
  const secret = options.secret ?? readEnv('LABYRINTHE_OTP_SECRET');
  if (secret === undefined || secret.length < 32) {
    throw new Error('createOtpHandlers: `secret` (or LABYRINTHE_OTP_SECRET) must be at least 32 characters.');
  }
  if (typeof options.onVerified !== 'function') {
    throw new Error('createOtpHandlers: `onVerified` is required (it is where the verified phone number is trusted).');
  }
  if (options.exposeTestCode === true && readEnv('NODE_ENV') === 'production') {
    throw new Error('createOtpHandlers: `exposeTestCode` is for development only and is refused when NODE_ENV is "production".');
  }
  const defaultCountryCode = options.defaultCountryCode ?? DEFAULT_COUNTRY_CODE;
  if (!isCountryCode(defaultCountryCode)) {
    throw new Error('createOtpHandlers: `defaultCountryCode` must be 1 to 3 digits without "+".');
  }
  if (options.template !== undefined && !options.template.includes('{code}')) {
    throw new Error('createOtpHandlers: `template` must contain the {code} token.');
  }
  if (options.webOtpDomain !== undefined && !WEB_OTP_DOMAIN.test(options.webOtpDomain)) {
    throw new Error('createOtpHandlers: `webOtpDomain` must be a host name such as "app.example.com" (no scheme, port or path).');
  }

  const sealer = new Sealer(secret);
  const cookieName = options.cookie?.name ?? 'labyrinthe_otp';
  let client: OtpClient | undefined;
  const getClient = (): OtpClient => (client ??= typeof options.client === 'function' ? options.client() : options.client);

  // ----- Session (cookie chiffré, ou identifiant + store) ---------------------

  const cookieAttributes = (request: Incoming) => ({
    path: options.cookie?.path ?? '/',
    domain: options.cookie?.domain,
    sameSite: options.cookie?.sameSite ?? 'Strict',
    secure: options.cookie?.secure ?? (isHttps(request) || readEnv('NODE_ENV') === 'production'),
  });

  async function loadState(request: Incoming): Promise<{ state: OtpSessionState | null; sid: string | null }> {
    const payload = (await sealer.open(readCookie(request.header('cookie'), cookieName), cookieName)) as {
      s?: unknown;
      sid?: unknown;
    } | null;
    if (payload === null || typeof payload !== 'object') {
      return { state: null, sid: null };
    }
    if (options.store !== undefined) {
      if (typeof payload.sid !== 'string') {
        return { state: null, sid: null };
      }
      const stored = await options.store.get(payload.sid);
      return { state: isSessionState(stored) ? stored : null, sid: payload.sid };
    }
    return { state: isSessionState(payload.s) ? payload.s : null, sid: null };
  }

  async function saveState(request: Incoming, result: Result, state: OtpSessionState, sid: string | null): Promise<void> {
    const ttl = Math.max(1, Math.ceil((state.expiresAt - Date.now()) / 1000) + COOKIE_GRACE_SECONDS);
    let payload: unknown;
    if (options.store !== undefined) {
      const id = sid ?? (await randomId());
      await options.store.set(id, state, ttl);
      payload = { sid: id };
    } else {
      payload = { s: state };
    }
    result.cookies.push(serializeCookie(cookieName, await sealer.seal(payload, cookieName), ttl, cookieAttributes(request)));
  }

  async function clearState(request: Incoming, result: Result, sid: string | null): Promise<void> {
    if (sid !== null && options.store !== undefined) {
      await options.store.delete(sid);
    }
    result.cookies.push(serializeCookie(cookieName, '', 0, cookieAttributes(request)));
  }

  // ----- Contrôles communs ----------------------------------------------------

  async function readJson(request: Incoming): Promise<Record<string, unknown>> {
    if (request.method !== 'POST') {
      throw new HttpError(405, 'invalid_request', 'Use POST.');
    }
    if (!originAllowed(request, options.allowedOrigins)) {
      throw new HttpError(403, 'forbidden', 'Origin not allowed.');
    }
    // JSON obligatoire : un formulaire d'un autre site ne peut pas l'envoyer sans pré-vol CORS.
    if (!(request.header('content-type') ?? '').toLowerCase().includes('application/json')) {
      throw new HttpError(415, 'invalid_request', 'Content-Type must be application/json.');
    }
    const text = await request.text();
    if (text.length > MAX_BODY_BYTES) {
      throw new HttpError(413, 'invalid_request', 'Request body too large.');
    }
    let body: unknown;
    try {
      body = JSON.parse(text);
    } catch {
      throw new HttpError(400, 'invalid_request', 'Invalid JSON.');
    }
    if (typeof body !== 'object' || body === null || Array.isArray(body)) {
      throw new HttpError(400, 'invalid_request', 'Expected a JSON object.');
    }
    return body as Record<string, unknown>;
  }

  // ----- POST send ------------------------------------------------------------

  async function send(request: Incoming, result: Result): Promise<void> {
    const body = await readJson(request);
    const phone = typeof body['phone'] === 'string' ? normalizePhone(body['phone'], defaultCountryCode) : null;
    if (phone === null) {
      throw new HttpError(422, 'invalid_phone', 'Invalid phone number.');
    }
    if (options.beforeSend !== undefined && (await options.beforeSend({ phone, request: request.source })) === false) {
      throw new HttpError(403, 'forbidden', 'Refused.');
    }
    const locale = options.locale ?? (body['locale'] === 'fr' || body['locale'] === 'en' ? body['locale'] : undefined);
    // Jeton d'envoi du widget, réutilisé quand il relance après une réponse perdue : même
    // Idempotency-Key, donc pas de second SMS. Dérivé par HMAC avec le secret : un navigateur
    // ne peut pas choisir la clé envoyée à l'API, ni toucher aux clés de vos propres appels.
    const nonce = body['send_id'];
    if (nonce !== undefined && (typeof nonce !== 'string' || !SEND_ID.test(nonce))) {
      throw new HttpError(400, 'invalid_request', 'Invalid send_id.');
    }
    const idempotencyKey = nonce === undefined ? undefined : `otp-widget-${await sealer.tag(`send:${nonce}`)}`;

    let sent: Awaited<ReturnType<OtpClient['otp']['send']>>;
    try {
      sent = await getClient().otp.send({
        // « + » explicite : le numéro est déjà international, l'indicatif par défaut ne doit pas s'y ajouter.
        phone: `+${phone}`,
        ...(options.template === undefined ? {} : { template: options.template }),
        ...(locale === undefined ? {} : { locale }),
        ...(options.webOtpDomain === undefined ? {} : { webOtpDomain: options.webOtpDomain }),
        ...(options.stripAccents === undefined ? {} : { stripAccents: options.stripAccents }),
        ...(idempotencyKey === undefined ? {} : { idempotencyKey }),
      });
    } catch (error) {
      throw upstreamError(error, 'send', options.onError);
    }

    const previous = await loadState(request);
    const state: OtpSessionState = {
      otpId: sent.otpId,
      phone,
      expiresAt: sent.expiresAt.getTime(),
      attemptsLeft: sent.maxAttempts,
    };
    // Avec un store : nouvel identifiant à chaque envoi (l'ancien est supprimé).
    if (previous.sid !== null && options.store !== undefined) {
      await options.store.delete(previous.sid);
    }
    await saveState(request, result, state, null);

    // Valeurs renvoyées par l'API (paramètres OTP du compte), sauf surcharge explicite.
    const codeLength = options.codeLength ?? positiveInt(sent.codeLength);
    const codeType = options.codeType ?? (sent.codeType === 'numeric' || sent.codeType === 'alphanumeric' ? sent.codeType : undefined);
    const resendAfter = options.resendAfterSeconds ?? nonNegativeInt(sent.resendAfterSeconds) ?? DEFAULT_RESEND_AFTER;
    const response: SendSuccessBody = {
      sent: true,
      phone_masked: maskPhone(phone),
      expires_at: sent.expiresAt.toISOString(),
      max_attempts: sent.maxAttempts,
      resend_after_seconds: resendAfter,
      ...(codeLength === undefined ? {} : { code_length: codeLength }),
      ...(codeType === undefined ? {} : { code_type: codeType }),
    };
    const testCode = sent.testCode;
    if (options.exposeTestCode === true && typeof testCode === 'string' && testCode !== '') {
      response.test_code = testCode;
    }
    result.body = response;
  }

  // ----- POST verify ----------------------------------------------------------

  async function verify(request: Incoming, result: Result): Promise<void> {
    const body = await readJson(request);
    const code = typeof body['code'] === 'string' ? body['code'].trim() : '';
    if (code === '' || code.length > MAX_CODE_LENGTH) {
      throw new HttpError(422, 'invalid_request', `The code must be 1 to ${String(MAX_CODE_LENGTH)} characters.`);
    }
    const { state, sid } = await loadState(request);
    if (state === null) {
      await clearState(request, result, sid);
      throw new HttpError(400, 'no_pending_verification', 'No pending verification: send a new code.');
    }
    if (state.expiresAt <= Date.now()) {
      await clearState(request, result, sid);
      result.body = { verified: false, reason: 'expired', attempts_left: null };
      return;
    }

    let checked: Awaited<ReturnType<OtpClient['otp']['verify']>>;
    try {
      checked = await getClient().otp.verify({ otpId: state.otpId, code });
    } catch (error) {
      if (errorCode(error) === 'NOT_FOUND') {
        await clearState(request, result, sid);
        throw new HttpError(400, 'no_pending_verification', 'No pending verification: send a new code.');
      }
      throw upstreamError(error, 'verify', options.onError);
    }

    if (checked.verified) {
      await clearState(request, result, sid);
      const data = await options.onVerified({
        phone: state.phone,
        request: request.source,
        responseHeaders: result.headers,
        setCookie: (cookie) => result.cookies.push(cookie),
      });
      result.body = data === undefined ? { verified: true } : { verified: true, data };
      return;
    }

    const reason: OtpFailureReason =
      checked.reason === 'expired' || checked.reason === 'already_used' || checked.reason === 'max_attempts_reached'
        ? checked.reason
        : 'invalid_code';
    if (reason === 'invalid_code') {
      // Valeur réelle de l'API (1.2.0) ; estimation locale avec un SDK plus ancien.
      const fromApi = nonNegativeInt(checked.attemptsRemaining);
      const attemptsLeft = fromApi ?? (state.attemptsLeft === null ? null : Math.max(0, state.attemptsLeft - 1));
      await saveState(request, result, { ...state, attemptsLeft }, sid);
      result.body = { verified: false, reason, attempts_left: attemptsLeft };
    } else {
      // Le code est définitivement inutilisable : un nouvel envoi est nécessaire.
      await clearState(request, result, sid);
      result.body = { verified: false, reason, attempts_left: reason === 'max_attempts_reached' ? 0 : null };
    }
  }

  // ----- Adaptateurs ----------------------------------------------------------

  async function run(action: 'send' | 'verify', request: Incoming): Promise<Result> {
    const result: Result = { status: 200, body: { verified: false, reason: 'invalid_code', attempts_left: null }, headers: new Headers(), cookies: [] };
    try {
      await (action === 'send' ? send : verify)(request, result);
    } catch (error) {
      const httpError =
        error instanceof HttpError ? error : (options.onError?.(error, { action }), new HttpError(500, 'server_error', 'Unexpected error.'));
      result.status = httpError.status;
      result.body = {
        error: {
          code: httpError.code,
          message: httpError.message,
          ...(httpError.retryAfterSeconds === undefined ? {} : { retry_after_seconds: httpError.retryAfterSeconds }),
          ...(httpError.maintenance?.adminMessage === undefined ? {} : { admin_message: httpError.maintenance.adminMessage }),
          ...(httpError.maintenance?.until === undefined ? {} : { until: httpError.maintenance.until }),
        },
      };
      if (httpError.retryAfterSeconds !== undefined) {
        result.headers.set('Retry-After', String(httpError.retryAfterSeconds));
      }
    }
    result.headers.set('Content-Type', 'application/json');
    result.headers.set('Cache-Control', 'no-store');
    return result;
  }

  const fetchHandler =
    (action: 'send' | 'verify'): FetchHandler =>
    async (request) => {
      const result = await run(action, fromFetch(request));
      const headers = new Headers(result.headers);
      for (const cookie of result.cookies) {
        headers.append('Set-Cookie', cookie);
      }
      return new Response(JSON.stringify(result.body), { status: result.status, headers });
    };

  const expressHandler =
    (action: 'send' | 'verify'): ExpressHandler =>
    async (req, res, next) => {
      try {
        const result = await run(action, fromExpress(req));
        res.statusCode = result.status;
        result.headers.forEach((value, name) => {
          res.setHeader(name, value);
        });
        if (result.cookies.length > 0) {
          res.setHeader('Set-Cookie', result.cookies);
        }
        res.end(JSON.stringify(result.body));
      } catch (error) {
        next(error);
      }
    };

  return {
    send: fetchHandler('send'),
    verify: fetchHandler('verify'),
    express: { send: expressHandler('send'), verify: expressHandler('verify') },
  };
}

// ----- Erreurs de l'API Labyrinthe ------------------------------------------

function errorCode(error: unknown): string | null {
  const code = (error as { code?: unknown } | null)?.code;
  return typeof code === 'string' ? code : null;
}

/** Erreur du SDK → réponse au navigateur (sans jamais transmettre le message de l'API). */
function upstreamError(error: unknown, action: 'send' | 'verify', onError: OtpHandlersOptions['onError']): HttpError {
  const code = errorCode(error);
  switch (code) {
    case 'INVALID_ARGUMENT':
    case 'INVALID_PHONE':
    case 'RECIPIENT_NOT_SENDABLE':
      return new HttpError(422, 'invalid_phone', 'This phone number cannot receive a code.');
    case 'OTP_RESEND_TOO_SOON':
      return new HttpError(429, 'resend_too_soon', 'A code was sent recently.', retryAfter(error) ?? 30);
    case 'OTP_RATE_LIMITED':
    case 'TOO_MANY_REQUESTS':
      return new HttpError(429, 'rate_limited', 'Too many requests.', retryAfter(error));
    case 'IDEMPOTENCY_REQUEST_IN_PROGRESS':
      // L'envoi d'origine est encore en cours (le SDK a déjà patienté) : le widget réessaie plus tard.
      return new HttpError(429, 'rate_limited', 'A send is still in progress.', retryAfter(error) ?? 5);
    case 'IDEMPOTENCY_KEY_UNRESOLVED':
      // L'envoi d'origine a échoué de façon inattendue côté API : un SMS a pu partir. Jamais relancé
      // automatiquement (ni par le SDK, ni par le widget) ; l'utilisateur redemande un code s'il ne l'a pas reçu.
      onError?.(error, { action });
      return new HttpError(502, 'send_failed', 'The code may not have been sent.');
    case 'FEATURE_DISABLED':
    case 'OTP_SERVICE_DISABLED':
      // Fonction coupée par la plateforme Labyrinthe (maintenance) : le message de l'administrateur, destiné aux
      // utilisateurs, est le seul message de l'API relayé au navigateur ; Retry-After seulement si le retour est programmé.
      onError?.(error, { action });
      return new HttpError(
        503,
        'service_unavailable',
        'The verification service is temporarily unavailable.',
        featureRetryAfter(error),
        maintenance(error),
      );
    case 'ACCOUNT_SUSPENDED':
      // Compte Labyrinthe suspendu : rien à faire côté utilisateur ; code stable pour l'intégrateur.
      onError?.(error, { action });
      return new HttpError(503, 'account_suspended', 'The verification service is unavailable.');
    default:
      // Configuration (clé, crédits, Sender ID, service coupé) ou réseau : rien d'exploitable par l'utilisateur.
      onError?.(error, { action });
      return new HttpError(503, 'service_unavailable', 'The verification service is unavailable.');
  }
}

const WEB_OTP_DOMAIN =
  /^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/;
/** Jeton d'envoi généré par le widget (`send_id`). */
const SEND_ID = /^[A-Za-z0-9_-]{16,64}$/;

function positiveInt(value: unknown): number | undefined {
  return typeof value === 'number' && Number.isInteger(value) && value >= 1 ? value : undefined;
}

function nonNegativeInt(value: unknown): number | undefined {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0 ? value : undefined;
}

function retryAfter(error: unknown): number | undefined {

  const ms = (error as { retryAfterMs?: unknown } | null)?.retryAfterMs;
  if (typeof ms === 'number' && ms >= 0) {
    return Math.ceil(ms / 1000);
  }
  const seconds = (error as { details?: { retry_after_seconds?: unknown } } | null)?.details?.retry_after_seconds;
  return typeof seconds === 'number' && seconds >= 0 ? Math.ceil(seconds) : undefined;
}

/**
 * Délai de retour d'une fonction coupée : en-tête Retry-After de l'API uniquement (jamais de valeur par défaut).
 * Lu sur l'erreur (`retryAfterMs` de FeatureDisabledError) ou sur ses en-têtes (SDK antérieur à 1.2.0).
 */
function featureRetryAfter(error: unknown): number | undefined {
  const ms = (error as { retryAfterMs?: unknown } | null)?.retryAfterMs;
  if (typeof ms === 'number' && ms >= 0) {
    return Math.max(1, Math.ceil(ms / 1000));
  }
  const header = (error as { headers?: Record<string, unknown> } | null)?.headers?.['retry-after'];
  return typeof header === 'string' && /^\d+$/.test(header.trim()) ? Math.max(1, Number(header.trim())) : undefined;
}

/** `details.message` (texte de l'administrateur, tronqué) et `details.until` (date ISO 8601 valide). */
function maintenance(error: unknown): { adminMessage?: string; until?: string } {
  const details = (error as { details?: Record<string, unknown> } | null)?.details ?? {};
  const message = typeof details['message'] === 'string' ? details['message'].trim().slice(0, 500) : '';
  const until = details['until'];
  return {
    ...(message === '' ? {} : { adminMessage: message }),
    ...(typeof until === 'string' && !Number.isNaN(Date.parse(until)) ? { until } : {}),
  };
}

// ----- Requêtes ---------------------------------------------------------------

function originAllowed(request: Incoming, allowed: string[] | undefined): boolean {
  const origin = request.header('origin');
  if (allowed !== undefined) {
    return origin === null ? request.header('sec-fetch-site') !== 'cross-site' : allowed.includes(origin);
  }
  if (request.header('sec-fetch-site') === 'cross-site') {
    return false;
  }
  if (origin === null) {
    return true; // Appel hors navigateur ou même origine sans en-tête Origin.
  }
  const host = request.header('x-forwarded-host') ?? request.header('host') ?? safeHost(request.url);
  try {
    return new URL(origin).host === host;
  } catch {
    return false;
  }
}

function isHttps(request: Incoming): boolean {
  const forwarded = request.header('x-forwarded-proto');
  if (forwarded !== null) {
    return forwarded.split(',')[0]?.trim() === 'https';
  }
  return request.url.startsWith('https:');
}

function safeHost(url: string): string | null {
  try {
    return new URL(url).host;
  } catch {
    return null;
  }
}

function fromFetch(request: Request): Incoming {
  return {
    method: request.method.toUpperCase(),
    url: request.url,
    header: (name) => request.headers.get(name),
    text: () => request.text(),
    source: request,
  };
}

/**
 * Requête Express / `node:http`, lue directement (sans passer par `Request`) :
 * corps déjà lu par express.json() / express.text() / express.raw(), ou flux brut.
 */
function fromExpress(req: ExpressLikeRequest): Incoming {
  const header = (name: string): string | null => {
    const value = req.headers[name.toLowerCase()];
    return Array.isArray(value) ? value.join(name.toLowerCase() === 'cookie' ? '; ' : ', ') : (value ?? null);
  };
  return {
    method: (req.method ?? 'POST').toUpperCase(),
    url: `${req.protocol ?? 'http'}://${header('host') ?? 'localhost'}${req.originalUrl ?? req.url ?? '/'}`,
    header,
    text: async () => {
      if (typeof req.body === 'string') {
        return req.body;
      }
      if (req.body instanceof Uint8Array) {
        return new TextDecoder().decode(req.body);
      }
      if (req.body !== undefined) {
        return JSON.stringify(req.body); // express.json() a déjà lu le flux.
      }
      const decoder = new TextDecoder();
      let text = '';
      for await (const chunk of req) {
        text += typeof chunk === 'string' ? chunk : decoder.decode(chunk as Uint8Array, { stream: true });
        if (text.length > MAX_BODY_BYTES) {
          break;
        }
      }
      return text + decoder.decode();
    },
    source: req,
  };
}

function readEnv(name: string): string | undefined {
  try {
    return (globalThis as { process?: { env?: Record<string, string | undefined> } }).process?.env?.[name];
  } catch {
    return undefined;
  }
}

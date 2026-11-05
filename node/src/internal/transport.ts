/**
 * Envoie les requêtes, applique la politique de relance et convertit les erreurs.
 * Mêmes règles que le SDK PHP (php/src/Internal/Transport.php).
 *
 * @internal
 */
import {
  ApiError,
  ConnectionError,
  RequestAbortedError,
  TimeoutError,
  UnexpectedResponseError,
  errorFromResponse,
  isRecord,
  toInt,
} from '../errors.js';
import type { Json } from './data.js';
import { redactApiKey, redactPath, stripQueryStrings } from './redact.js';
import { clientIdentifier, isBrowser } from './runtime.js';

/**
 * Quand une requête peut être renvoyée automatiquement.
 *
 * - `safe` : le rejeu ne peut pas produire deux fois un effet (GET, estimate,
 *   POST /sms avec Idempotency-Key). Relancée sur erreur réseau, 5xx et 429 TOO_MANY_REQUESTS.
 * - `throttled-only` : POST /otp/send et /otp/verify. Relancée seulement sur
 *   429 TOO_MANY_REQUESTS (refusée avant traitement). Jamais sur erreur réseau ni 5xx
 *   (risque d'un second SMS ou d'une tentative consommée).
 */
export type RetryMode = 'safe' | 'throttled-only';

/** Minimal logger: `console`, pino, winston… Never receives the key, codes, bodies or full phone numbers. */
export interface Logger {
  debug?(message: string, context?: Record<string, unknown>): void;
  info?(message: string, context?: Record<string, unknown>): void;
  warn?(message: string, context?: Record<string, unknown>): void;
  error?(message: string, context?: Record<string, unknown>): void;
}

/** Options passed to `fetch` by the SDK. */
export interface FetchInit {
  method: string;
  headers: Record<string, string>;
  body?: string;
  signal: AbortSignal;
  redirect: 'manual';
}

/** The subset of the Fetch `Response` the SDK reads. */
export interface FetchResponse {
  status: number;
  headers: { forEach(callback: (value: string, key: string) => void): void };
  text(): Promise<string>;
}

/** Any WHATWG-compatible `fetch` (global fetch, undici, a mock…). */
export type FetchLike = (url: string, init: FetchInit) => Promise<FetchResponse>;

export type Sleep = (ms: number, signal?: AbortSignal) => Promise<void>;

export interface TransportConfig {
  apiKey: string;
  baseUrl: string;
  timeoutMs: number;
  maxRetries: number;
  maxRetryWaitMs: number;
  fetch: FetchLike;
  logger: Logger | undefined;
  sleep: Sleep;
}

export interface RequestParams {
  query?: Record<string, string | number | undefined>;
  body?: Json;
  headers?: Record<string, string>;
  signal?: AbortSignal | undefined;
  timeoutMs?: number | undefined;
}

export interface ApiResponse {
  status: number;
  body: Json;
  headers: Record<string, string>;
}

const BASE_DELAY_MS = 500;
const MAX_BACKOFF_MS = 8000;

export const defaultSleep: Sleep = (ms, signal) =>
  new Promise((resolve, reject) => {
    if (signal?.aborted === true) {
      reject(new RequestAbortedError());
      return;
    }
    const onAbort = (): void => {
      clearTimeout(timer);
      reject(new RequestAbortedError());
    };
    const timer = setTimeout(() => {
      signal?.removeEventListener('abort', onAbort);
      resolve();
    }, ms);
    signal?.addEventListener('abort', onAbort, { once: true });
  });

type AttemptFailure = { kind: 'network'; reason: string } | { kind: 'timeout' };

export class Transport {
  readonly #apiKey: string;
  readonly #config: Omit<TransportConfig, 'apiKey'>;
  readonly #identityHeader: [string, string];

  constructor(config: TransportConfig) {
    const { apiKey, ...rest } = config;
    this.#apiKey = apiKey;
    this.#config = rest;
    // Les navigateurs interdisent de fixer User-Agent.
    this.#identityHeader = [isBrowser() ? 'X-Labyrinthe-Client' : 'User-Agent', clientIdentifier()];
  }

  get baseUrl(): string {
    return this.#config.baseUrl;
  }

  async request(method: 'GET' | 'POST', path: string, mode: RetryMode, params: RequestParams = {}): Promise<ApiResponse> {
    const { fetch, logger, maxRetries, sleep } = this.#config;
    const query = new URLSearchParams();
    for (const [name, value] of Object.entries(params.query ?? {})) {
      if (value !== undefined) query.append(name, String(value));
    }
    const queryString = query.toString();
    const url = this.#config.baseUrl + path + (queryString === '' ? '' : `?${queryString}`);
    const logPath = redactPath(path, query);
    const payload = params.body === undefined ? undefined : JSON.stringify(params.body);
    const timeoutMs = params.timeoutMs ?? this.#config.timeoutMs;
    const signal = params.signal;

    const headers: Record<string, string> = {
      Authorization: `Bearer ${this.#apiKey}`,
      Accept: 'application/json',
      [this.#identityHeader[0]]: this.#identityHeader[1],
      ...params.headers,
    };
    if (payload !== undefined) {
      headers['Content-Type'] = 'application/json';
    }

    for (let attempt = 0; ; attempt++) {
      if (signal?.aborted === true) {
        throw new RequestAbortedError();
      }
      log(logger, 'debug', 'Labyrinthe Connect request', { method, path: logPath, attempt: attempt + 1 });
      const startedAt = Date.now();

      const result = await this.#attempt(fetch, url, { method, headers, body: payload }, timeoutMs, signal);

      if ('kind' in result) {
        const timeout = result.kind === 'timeout';
        if (mode === 'safe' && attempt < maxRetries) {
          const delay = this.#backoff(attempt);
          log(logger, 'warn', 'Labyrinthe Connect network error, retrying', {
            method,
            path: logPath,
            attempt: attempt + 1,
            delayMs: Math.round(delay),
            timeout,
          });
          await sleep(delay, signal);
          continue;
        }
        log(logger, 'error', 'Labyrinthe Connect network error', { method, path: logPath, timeout });
        const suffix =
          mode === 'throttled-only' ? ' The request may have been processed; it was not retried automatically.' : '';
        if (result.kind === 'timeout') {
          throw new TimeoutError(
            `The Labyrinthe Connect API did not answer within ${String(timeoutMs)} ms (${method} ${path}).${suffix}`,
          );
        }
        throw new ConnectionError(
          `Could not reach the Labyrinthe Connect API (${method} ${path}): ${result.reason}.${suffix}`,
        );
      }

      const { status, headers: responseHeaders, text } = result;
      log(logger, 'debug', 'Labyrinthe Connect response', {
        method,
        path: logPath,
        status,
        durationMs: Date.now() - startedAt,
        replayed: responseHeaders['idempotent-replayed'] === 'true',
      });

      if (status >= 200 && status < 300) {
        return { status, body: decode(text, status), headers: responseHeaders };
      }

      const error = errorFromResponse(status, text, responseHeaders);
      if (attempt < maxRetries && isRetryable(mode, error)) {
        const delay = this.#retryDelay(error, attempt);
        if (delay !== null) {
          log(logger, 'warn', 'Labyrinthe Connect request failed, retrying', {
            method,
            path: logPath,
            status,
            code: error.code,
            attempt: attempt + 1,
            delayMs: Math.round(delay),
          });
          await sleep(delay, signal);
          continue;
        }
      }

      log(logger, 'info', 'Labyrinthe Connect API error', { method, path: logPath, status, code: error.code });
      throw error;
    }
  }

  async #attempt(
    fetch: FetchLike,
    url: string,
    init: { method: string; headers: Record<string, string>; body: string | undefined },
    timeoutMs: number,
    signal: AbortSignal | undefined,
  ): Promise<AttemptFailure | { status: number; headers: Record<string, string>; text: string }> {
    const controller = new AbortController();
    const state = { timedOut: false };
    const timer = setTimeout(() => {
      state.timedOut = true;
      controller.abort();
    }, timeoutMs);
    const onAbort = (): void => {
      controller.abort();
    };
    signal?.addEventListener('abort', onAbort, { once: true });

    try {
      const response = await fetch(url, {
        method: init.method,
        headers: init.headers,
        ...(init.body === undefined ? {} : { body: init.body }),
        signal: controller.signal,
        redirect: 'manual',
      });
      const headers: Record<string, string> = {};
      response.headers.forEach((value, key) => {
        headers[key.toLowerCase()] = value;
      });
      const text = await response.text();
      return { status: response.status, headers, text };
    } catch (error) {
      if (signal?.aborted === true) {
        throw new RequestAbortedError();
      }
      if (state.timedOut) {
        return { kind: 'timeout' };
      }
      return { kind: 'network', reason: describeNetworkError(error) };
    } finally {
      clearTimeout(timer);
      signal?.removeEventListener('abort', onAbort);
    }
  }

  /** Délai avant la prochaine tentative, ou null pour abandonner (l'API demande d'attendre plus que le maximum). */
  #retryDelay(error: ApiError, attempt: number): number | null {
    if (error.status === 429) {
      const retryAfter = retryAfterMs(error);
      if (retryAfter !== null) {
        return retryAfter <= this.#config.maxRetryWaitMs ? retryAfter : null;
      }
    }
    return this.#backoff(attempt);
  }

  /** Backoff exponentiel avec jitter : 0,5 s, 1 s, 2 s… (50 à 100 %), plafonné. */
  #backoff(attempt: number): number {
    const delay = Math.min(MAX_BACKOFF_MS, BASE_DELAY_MS * 2 ** attempt);
    const jitter = 0.5 + Math.random() * 0.5;
    return Math.min(this.#config.maxRetryWaitMs, delay * jitter);
  }

  toJSON(): Record<string, unknown> {
    return { apiKey: redactApiKey(this.#apiKey), baseUrl: this.#config.baseUrl, maxRetries: this.#config.maxRetries };
  }

  [Symbol.for('nodejs.util.inspect.custom')](): Record<string, unknown> {
    return this.toJSON();
  }
}

function isRetryable(mode: RetryMode, error: ApiError): boolean {
  // Seulement la limite générique : OTP_RESEND_TOO_SOON / OTP_RATE_LIMITED sont des limites métier.
  if (error.status === 429) {
    return error.code === 'TOO_MANY_REQUESTS';
  }
  return mode === 'safe' && error.status >= 500;
}

function retryAfterMs(error: ApiError): number | null {
  const header = error.headers['retry-after']?.trim();
  if (header !== undefined && header !== '') {
    if (/^\d+(\.\d+)?$/.test(header)) {
      return Number(header) * 1000;
    }
    const date = Date.parse(header);
    if (!Number.isNaN(date)) {
      return Math.max(0, date - Date.now());
    }
  }
  const seconds = toInt(error.details['retry_after_seconds']);
  return seconds === null ? null : seconds * 1000;
}

function decode(text: string, status: number): Json {
  if (text.trim() === '') {
    return {};
  }
  let decoded: unknown;
  try {
    decoded = JSON.parse(text);
  } catch {
    throw new UnexpectedResponseError('Unexpected API response: the body is not valid JSON.', status);
  }
  if (!isRecord(decoded)) {
    throw new UnexpectedResponseError('Unexpected API response: a JSON object was expected.', status);
  }
  return decoded;
}

/** Message court et sans URL : l'erreur d'origine n'est pas chaînée (elle peut citer la requête). */
function describeNetworkError(error: unknown): string {
  if (!(error instanceof Error)) {
    return 'network error';
  }
  const cause = (error as { cause?: unknown }).cause;
  const code = isRecord(cause) && typeof cause['code'] === 'string' ? cause['code'] : undefined;
  const message = stripQueryStrings(error.message);
  return code === undefined ? message : `${message} (${code})`;
}

function log(
  logger: Logger | undefined,
  level: 'debug' | 'info' | 'warn' | 'error',
  message: string,
  context: Record<string, unknown>,
): void {
  try {
    logger?.[level]?.(message, context);
  } catch {
    // Un logger défaillant ne doit jamais casser un appel.
  }
}

import { InvalidArgumentError } from './errors.js';
import { redactApiKey } from './internal/redact.js';
import { env, isBrowser } from './internal/runtime.js';
import { Transport, defaultSleep, type FetchLike, type Logger, type Sleep } from './internal/transport.js';
import { DEFAULT_COUNTRY_CODE, assertCountryCode } from './phone.js';
import { BalanceResource, SenderIds } from './resources/account.js';
import { Otp } from './resources/otp.js';
import { Sms } from './resources/sms.js';

export const DEFAULT_BASE_URL = 'https://api.connect.labyrinthe-rdc.com/api/public/v1';
const API_PATH = '/api/public/v1';

export interface LabyrintheOptions {
  /** `lsk_live_…` or `lsk_test_…`. Defaults to `process.env.LABYRINTHE_API_KEY`. Keep it server-side. */
  apiKey?: string;
  /**
   * API root. Defaults to `process.env.LABYRINTHE_BASE_URL`, then production.
   * A bare host ("http://localhost:8000") is completed with `/api/public/v1`.
   */
  baseUrl?: string;
  /** Timeout of each attempt, in milliseconds. Default 30000. */
  timeoutMs?: number;
  /** Automatic retries, only when they are safe (0 disables them). Default 2, max 10. */
  maxRetries?: number;
  /**
   * Longest wait accepted before a retry (Retry-After), in milliseconds. Default 10000.
   * A 503 FEATURE_DISABLED is retried only when its Retry-After fits in this wait.
   */
  maxRetryWaitMs?: number;
  /** Added to local numbers: "0812345678" → "243812345678". Default "243" (DR Congo). */
  defaultCountryCode?: string;
  /** Sender ID used when `sms.send()` gets none. */
  defaultSender?: string;
  /** Custom `fetch` implementation (defaults to the global `fetch`). */
  fetch?: FetchLike;
  /** Receives debug/info/warn/error logs: no key, no code, no body, masked phone numbers. */
  logger?: Logger;
  /**
   * The API key gives full access to your account: it must never reach a
   * browser. The client refuses to start in a browser unless this is true.
   */
  dangerouslyAllowBrowser?: boolean;
  /** @internal waits between retries (tests). */
  sleep?: Sleep;
}

const INSPECT = Symbol.for('nodejs.util.inspect.custom');
const DENO_INSPECT = Symbol.for('Deno.customInspect');

/**
 * Labyrinthe Connect API client.
 *
 *     const client = new Labyrinthe({ apiKey: 'lsk_test_…' });
 *     const message = await client.sms.send({ to: '0812345678', body: 'Hello!' });
 *
 * Keys starting with `lsk_test_` use the sandbox: nothing is sent, no credit
 * is used, responses have the same shape as in production.
 */
export class Labyrinthe {
  /** Send and track SMS. */
  readonly sms: Sms;
  /** Credit balance. */
  readonly balance: BalanceResource;
  /** Approved Sender IDs. */
  readonly senderIds: SenderIds;
  /** One-time passwords by SMS. */
  readonly otp: Otp;

  readonly #apiKey: string;
  readonly #baseUrl: string;

  constructor(options: LabyrintheOptions = {}) {
    const apiKey = (options.apiKey ?? env('LABYRINTHE_API_KEY') ?? '').trim();
    if (apiKey === '') {
      throw new InvalidArgumentError(
        'A Labyrinthe Connect API key is required (lsk_live_… or lsk_test_…): pass `apiKey` or set LABYRINTHE_API_KEY.',
      );
    }
    if (isBrowser() && options.dangerouslyAllowBrowser !== true) {
      throw new InvalidArgumentError(
        'The Labyrinthe Connect client must run server-side: your API key would be exposed in the browser. ' +
          'Call your own backend instead (or set dangerouslyAllowBrowser: true if you really know what you are doing).',
      );
    }

    const timeoutMs = options.timeoutMs ?? 30_000;
    const maxRetries = options.maxRetries ?? 2;
    const maxRetryWaitMs = options.maxRetryWaitMs ?? 10_000;
    const defaultCountryCode = options.defaultCountryCode ?? DEFAULT_COUNTRY_CODE;
    if (!Number.isFinite(timeoutMs) || timeoutMs <= 0) {
      throw new InvalidArgumentError('timeoutMs must be greater than 0.');
    }
    if (!Number.isInteger(maxRetries) || maxRetries < 0 || maxRetries > 10) {
      throw new InvalidArgumentError('maxRetries must be an integer between 0 and 10.');
    }
    if (!Number.isFinite(maxRetryWaitMs) || maxRetryWaitMs < 0) {
      throw new InvalidArgumentError('maxRetryWaitMs cannot be negative.');
    }
    assertCountryCode(defaultCountryCode);
    const defaultSender = options.defaultSender === '' ? undefined : options.defaultSender;

    this.#apiKey = apiKey;
    this.#baseUrl = normalizeBaseUrl(options.baseUrl ?? env('LABYRINTHE_BASE_URL') ?? DEFAULT_BASE_URL);

    const customFetch = options.fetch;
    const transport = new Transport({
      apiKey,
      baseUrl: this.#baseUrl,
      timeoutMs,
      maxRetries,
      maxRetryWaitMs,
      // Lu à chaque appel (et lié à globalThis : Workers refuse un fetch détaché).
      fetch: customFetch ?? ((url, init) => globalThis.fetch(url, init)),
      logger: options.logger,
      sleep: options.sleep ?? defaultSleep,
    });

    this.sms = new Sms(transport, defaultCountryCode, defaultSender);
    this.balance = new BalanceResource(transport);
    this.senderIds = new SenderIds(transport);
    this.otp = new Otp(transport, defaultCountryCode);
  }

  /** True with a sandbox key (`lsk_test_…`): nothing is sent, no credit is used. */
  get isTestMode(): boolean {
    return this.#apiKey.startsWith('lsk_test_');
  }

  /** True with a production key (`lsk_live_…`). */
  get isLiveMode(): boolean {
    return this.#apiKey.startsWith('lsk_live_');
  }

  /** The API root used by this client. */
  get baseUrl(): string {
    return this.#baseUrl;
  }

  toJSON(): Record<string, unknown> {
    return { apiKey: redactApiKey(this.#apiKey), baseUrl: this.#baseUrl, testMode: this.isTestMode };
  }

  [INSPECT](): Record<string, unknown> {
    return this.toJSON();
  }

  [DENO_INSPECT](): string {
    return `Labyrinthe ${JSON.stringify(this.toJSON())}`;
  }
}

function normalizeBaseUrl(baseUrl: string): string {
  const trimmed = baseUrl.trim().replace(/\/+$/, '');
  let url: URL;
  try {
    url = new URL(trimmed);
  } catch {
    url = new URL('invalid:');
  }
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    throw new InvalidArgumentError(
      'baseUrl must be an absolute http(s) URL, e.g. https://api.connect.labyrinthe-rdc.com/api/public/v1.',
    );
  }
  // Un hôte seul ("http://localhost:8000") désigne la racine du backend.
  return url.pathname === '/' || url.pathname === '' ? trimmed + API_PATH : trimmed;
}

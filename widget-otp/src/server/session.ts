/**
 * État OTP côté serveur et son transport dans un cookie.
 *
 * Le cookie est **chiffré et authentifié** (AES-256-GCM, clé dérivée du secret
 * par HKDF-SHA-256) : le navigateur ne peut ni lire `otp_id` ni modifier l'état.
 * Avec un `store`, le cookie ne contient qu'un identifiant de session aléatoire
 * (lui aussi chiffré) et l'état reste dans le store.
 *
 * Uniquement Web Crypto : Node ≥ 18, edge, Deno, Bun. Aucun module Node importé
 * statiquement.
 */

/** What the server keeps between `send` and `verify`. Never sent to the browser in clear. */
export interface OtpSessionState {
  /** Labyrinthe `otp_id`. */
  otpId: string;
  /** Normalised phone number the code was sent to. */
  phone: string;
  /** Expiry of the code, in milliseconds since the epoch. */
  expiresAt: number;
  /** Attempts left, tracked from `max_attempts` and the `invalid_code` answers; null when unknown. */
  attemptsLeft: number | null;
}

/**
 * Optional server-side store (Redis, database, your framework's session…).
 * Without it, the state travels in the encrypted cookie.
 */
export interface OtpSessionStore {
  get(id: string): OtpSessionState | null | undefined | Promise<OtpSessionState | null | undefined>;
  set(id: string, state: OtpSessionState, ttlSeconds: number): void | Promise<void>;
  delete(id: string): void | Promise<void>;
}

/** In-memory store, for development and tests (lost on restart, not shared between instances). */
export function memoryStore(): OtpSessionStore & { readonly size: number } {
  const entries = new Map<string, { state: OtpSessionState; until: number }>();
  return {
    get size() {
      return entries.size;
    },
    get(id) {
      const entry = entries.get(id);
      if (entry === undefined || entry.until < Date.now()) {
        entries.delete(id);
        return null;
      }
      return { ...entry.state };
    },
    set(id, state, ttlSeconds) {
      entries.set(id, { state: { ...state }, until: Date.now() + ttlSeconds * 1000 });
    },
    delete(id) {
      entries.delete(id);
    },
  };
}

const encoder = new TextEncoder();
const decoder = new TextDecoder();
const TOKEN_PREFIX = 'v1.';
const IV_LENGTH = 12;

interface GlobalWithCrypto {
  crypto?: Crypto;
  process?: { versions?: Record<string, string | undefined> };
}

let cryptoPromise: Promise<Crypto> | undefined;

/** Web Crypto : global partout sauf sur Node 18 (lu alors dans `node:crypto`, import dynamique non littéral). */
export function getCrypto(): Promise<Crypto> {
  const g = globalThis as GlobalWithCrypto;
  if (g.crypto?.subtle !== undefined) {
    return Promise.resolve(g.crypto);
  }
  cryptoPromise ??= (async () => {
    const specifier = ['node', 'crypto'].join(':');
    const mod = (await import(/* webpackIgnore: true */ /* @vite-ignore */ specifier)) as { webcrypto?: Crypto };
    if (mod.webcrypto?.subtle === undefined) {
      throw new Error('Web Crypto is not available in this runtime.');
    }
    return mod.webcrypto;
  })();
  return cryptoPromise;
}

/** Chiffre et déchiffre de petits objets JSON avec une clé dérivée du secret. */
export class Sealer {
  readonly #key: Promise<CryptoKey>;

  constructor(secret: string) {
    this.#key = (async () => {
      const crypto = await getCrypto();
      const material = await crypto.subtle.importKey('raw', encoder.encode(secret), 'HKDF', false, ['deriveKey']);
      return crypto.subtle.deriveKey(
        { name: 'HKDF', hash: 'SHA-256', salt: encoder.encode('labyrinthe-otp-widget'), info: encoder.encode('session-cookie-v1') },
        material,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt'],
      );
    })();
  }

  /** `context` (nom du cookie) est authentifié avec le contenu : un jeton ne sert que là où il a été émis. */
  async seal(value: unknown, context: string): Promise<string> {
    const crypto = await getCrypto();
    const iv = crypto.getRandomValues(new Uint8Array(IV_LENGTH));
    const cipher = await crypto.subtle.encrypt(
      { name: 'AES-GCM', iv, additionalData: encoder.encode(context) },
      await this.#key,
      encoder.encode(JSON.stringify(value)),
    );
    const bytes = new Uint8Array(IV_LENGTH + cipher.byteLength);
    bytes.set(iv, 0);
    bytes.set(new Uint8Array(cipher), IV_LENGTH);
    return TOKEN_PREFIX + toBase64Url(bytes);
  }

  /** null si le jeton est absent, altéré, émis avec un autre secret ou illisible. */
  async open(token: string | null | undefined, context: string): Promise<unknown> {
    if (typeof token !== 'string' || !token.startsWith(TOKEN_PREFIX) || token.length > 4096) {
      return null;
    }
    try {
      const bytes = fromBase64Url(token.slice(TOKEN_PREFIX.length));
      if (bytes.length <= IV_LENGTH) {
        return null;
      }
      const crypto = await getCrypto();
      const plain = await crypto.subtle.decrypt(
        { name: 'AES-GCM', iv: bytes.slice(0, IV_LENGTH), additionalData: encoder.encode(context) },
        await this.#key,
        bytes.slice(IV_LENGTH),
      );
      return JSON.parse(decoder.decode(plain)) as unknown;
    } catch {
      return null;
    }
  }
}

/** Identifiant de session aléatoire (256 bits). */
export async function randomId(): Promise<string> {
  const crypto = await getCrypto();
  return toBase64Url(crypto.getRandomValues(new Uint8Array(32)));
}

export function isSessionState(value: unknown): value is OtpSessionState {
  if (typeof value !== 'object' || value === null) {
    return false;
  }
  const v = value as Record<string, unknown>;
  return (
    typeof v['otpId'] === 'string' &&
    typeof v['phone'] === 'string' &&
    typeof v['expiresAt'] === 'number' &&
    (v['attemptsLeft'] === null || typeof v['attemptsLeft'] === 'number')
  );
}

function toBase64Url(bytes: Uint8Array): string {
  let binary = '';
  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function fromBase64Url(value: string): Uint8Array {
  if (!/^[A-Za-z0-9_-]*$/.test(value)) {
    throw new Error('invalid base64url');
  }
  const binary = atob(value.replace(/-/g, '+').replace(/_/g, '/'));
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes;
}

// ----- Cookies --------------------------------------------------------------

export interface CookieOptions {
  /** Default "labyrinthe_otp". */
  name?: string;
  /** Default "/". Restrict it (e.g. "/api/otp") when both endpoints share a prefix. */
  path?: string;
  domain?: string;
  /** Default "Strict". Use "None" (with `secure`) only for endpoints on another site. */
  sameSite?: 'Strict' | 'Lax' | 'None';
  /** Default: true on HTTPS requests and when NODE_ENV is "production", false on plain-HTTP development. */
  secure?: boolean;
}

export function readCookie(header: string | null, name: string): string | null {
  if (header === null) {
    return null;
  }
  for (const part of header.split(';')) {
    const index = part.indexOf('=');
    if (index > 0 && part.slice(0, index).trim() === name) {
      return part.slice(index + 1).trim();
    }
  }
  return null;
}

export function serializeCookie(
  name: string,
  value: string,
  maxAgeSeconds: number,
  options: { path: string; domain?: string | undefined; sameSite: string; secure: boolean },
): string {
  let cookie = `${name}=${value}; Max-Age=${String(Math.max(0, Math.floor(maxAgeSeconds)))}; Path=${options.path}; HttpOnly; SameSite=${options.sameSite}`;
  if (options.domain !== undefined) {
    cookie += `; Domain=${options.domain}`;
  }
  if (options.secure) {
    cookie += '; Secure';
  }
  return cookie;
}

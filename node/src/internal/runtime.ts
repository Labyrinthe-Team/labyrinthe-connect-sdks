/**
 * Accès à l'environnement d'exécution sans dépendre de Node : le cœur du SDK
 * tourne aussi sur les runtimes edge (Vercel, Cloudflare Workers), Deno et Bun.
 * Aucun import `node:*` statique ici.
 *
 * @internal
 */
import { VERSION } from '../version.js';

interface ProcessLike {
  env?: Record<string, string | undefined>;
  versions?: Record<string, string | undefined>;
}

interface GlobalLike {
  process?: ProcessLike;
  crypto?: Crypto;
  window?: { document?: unknown };
  Deno?: { version?: { deno?: string } };
  Bun?: { version?: string };
  navigator?: { userAgent?: string };
  EdgeRuntime?: string;
}

const g = globalThis as unknown as GlobalLike;

/** Lit une variable d'environnement quand `process.env` existe (Node, Bun, Deno 2, Next.js edge). */
export function env(name: string): string | undefined {
  try {
    const value = g.process?.env?.[name];
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : undefined;
  } catch {
    // Deno sans --allow-env : on ignore.
    return undefined;
  }
}

/** Vrai dans un vrai navigateur (pas dans un worker edge ni dans Deno). */
export function isBrowser(): boolean {
  return g.window?.document !== undefined;
}

function runtimeName(): string {
  if (typeof g.Bun?.version === 'string') return `bun/${g.Bun.version}`;
  if (typeof g.Deno?.version?.deno === 'string') return `deno/${g.Deno.version.deno}`;
  if (typeof g.EdgeRuntime === 'string') return 'edge-runtime';
  const node = g.process?.versions?.['node'];
  if (typeof node === 'string') return `node/${node}`;
  if (g.navigator?.userAgent === 'Cloudflare-Workers') return 'workerd';
  return 'unknown';
}

/** "labyrinthe-node/1.0.0 node/22.11.0" */
export function clientIdentifier(): string {
  return `labyrinthe-node/${VERSION} ${runtimeName()}`;
}

let cryptoPromise: Promise<Crypto> | undefined;

/**
 * Web Crypto. Global partout (Node ≥ 20, Deno, Bun, edge) sauf sur Node 18,
 * où il faut le lire dans le module crypto : import dynamique, jamais exécuté
 * ailleurs, et ignoré par les bundlers (spécificateur non littéral).
 */
export function getCrypto(): Promise<Crypto> {
  if (g.crypto?.subtle !== undefined && typeof g.crypto.randomUUID === 'function') {
    return Promise.resolve(g.crypto);
  }
  cryptoPromise ??= loadNodeCrypto();
  return cryptoPromise;
}

async function loadNodeCrypto(): Promise<Crypto> {
  if (typeof g.process?.versions?.['node'] === 'string') {
    const specifier = ['node', 'crypto'].join(':');
    try {
      const mod = (await import(/* webpackIgnore: true */ /* @vite-ignore */ specifier)) as { webcrypto?: Crypto };
      if (mod.webcrypto?.subtle !== undefined) {
        return mod.webcrypto;
      }
    } catch {
      // On tombe sur l'erreur ci-dessous.
    }
  }
  cryptoPromise = undefined;
  throw new Error('Web Crypto (globalThis.crypto) is not available in this runtime.');
}

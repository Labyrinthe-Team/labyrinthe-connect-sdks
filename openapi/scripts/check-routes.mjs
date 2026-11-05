// Vérifie que la spec et les routes Laravel `/api/public/v1/*` correspondent
// exactement (aucune route non documentée, aucun chemin documenté inexistant).
import { execFileSync } from 'node:child_process';
import { BACKEND_DIR, HTTP_METHODS, loadSpec } from './lib.mjs';

const PREFIX = 'api/public/v1';
const spec = loadSpec();

const documented = new Set();
for (const [path, item] of Object.entries(spec.paths ?? {})) {
  for (const method of HTTP_METHODS) if (item[method]) documented.add(`${method.toUpperCase()} ${path}`);
}

const raw = execFileSync('php', ['artisan', 'route:list', '--json', `--path=${PREFIX}`], { cwd: BACKEND_DIR, encoding: 'utf8' });
const actual = new Set();
for (const route of JSON.parse(raw)) {
  if (!route.uri.startsWith(PREFIX)) continue;
  const path = route.uri.slice(PREFIX.length) || '/';
  for (const method of route.method.split('|')) {
    if (method === 'HEAD') continue;
    actual.add(`${method} ${path}`);
  }
}

const missing = [...actual].filter((r) => !documented.has(r)).sort();
const stale = [...documented].filter((r) => !actual.has(r)).sort();

if (missing.length) console.error(`Routes non documentées :\n  ${missing.join('\n  ')}`);
if (stale.length) console.error(`Chemins documentés absents du backend :\n  ${stale.join('\n  ')}`);
if (missing.length || stale.length) process.exit(1);
console.log(`OK : ${actual.size} routes, toutes documentées.`);

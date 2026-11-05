// Utilitaires communs aux scripts de la spec.
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import YAML from 'yaml';

export const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
export const SPEC_PATH = resolve(ROOT, 'openapi.yaml');
export const BACKEND_DIR = resolve(process.env.LABYRINTHE_BACKEND_DIR ?? resolve(ROOT, '../../labyrinthe-connect-backend'));
export const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete'];

export function loadSpec() {
  return YAML.parse(readFileSync(SPEC_PATH, 'utf8'));
}

// Copie la spec dans le backend (docs/openapi.yaml), qui la sert publiquement
// sur /api/public/openapi.yaml. À lancer après chaque modification.
import { copyFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { BACKEND_DIR, SPEC_PATH } from './lib.mjs';

const target = resolve(BACKEND_DIR, 'docs/openapi.yaml');
if (!existsSync(resolve(BACKEND_DIR, 'artisan'))) {
  console.error(`Backend introuvable : ${BACKEND_DIR} (variable LABYRINTHE_BACKEND_DIR).`);
  process.exit(1);
}
copyFileSync(SPEC_PATH, target);
console.log(`Spec copiée vers ${target}`);

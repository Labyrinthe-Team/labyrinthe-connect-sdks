// Lance la collection avec Newman contre un backend (local par défaut), avec une clé de TEST.
//
//   LABYRINTHE_API_KEY=lsk_test_… npm test
//   facultatif : LABYRINTHE_BASE_URL (défaut : baseUrl de local.postman_environment.json)
//
// La clé n'est lue que dans l'environnement du processus et injectée en mémoire (envVar) :
// jamais sur la ligne de commande, jamais dans un fichier, jamais affichée.
import { readFileSync } from 'node:fs';
import newman from 'newman';

const apiKey = (process.env.LABYRINTHE_API_KEY ?? '').trim();
if (!apiKey.startsWith('lsk_test_')) {
  console.error('Set LABYRINTHE_API_KEY to a TEST key (lsk_test_…): the collection sends SMS and OTP.');
  process.exit(1);
}

const collection = JSON.parse(readFileSync(new URL('../labyrinthe-connect.postman_collection.json', import.meta.url), 'utf8'));
const environment = JSON.parse(readFileSync(new URL('../local.postman_environment.json', import.meta.url), 'utf8'));
const envVar = [{ key: 'apiKey', value: apiKey }];
const baseUrl = process.env.LABYRINTHE_BASE_URL?.trim();
if (baseUrl) {
  // Un hôte nu (http://localhost:8000) est complété avec /api/public/v1, comme dans les SDK.
  envVar.push({ key: 'baseUrl', value: /\/api\/public\/v1\/?$/.test(baseUrl) ? baseUrl.replace(/\/$/, '') : `${baseUrl.replace(/\/$/, '')}/api/public/v1` });
}

newman.run(
  {
    collection,
    environment,
    envVar,
    reporters: ['cli'],
    // Les en-têtes de requête (dont Authorization) ne sont pas affichés par le reporter cli.
    reporter: { cli: { noSummary: false, showTimestamps: false } },
    timeoutRequest: 30_000,
  },
  (error, summary) => {
    if (error) {
      console.error(error.message);
      process.exit(1);
    }
    const { requests, assertions } = summary.run.stats;
    const failed = summary.run.failures.length;
    console.log(`Requests: ${requests.total - requests.failed}/${requests.total}, assertions: ${assertions.total - assertions.failed}/${assertions.total}`);
    process.exit(failed > 0 ? 1 : 0);
  },
);

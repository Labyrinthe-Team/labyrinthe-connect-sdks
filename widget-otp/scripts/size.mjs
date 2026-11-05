// Taille des bundles (minifiés, gzip niveau 9 et brotli). Échoue au-delà du budget du widget.
import { readFileSync } from 'node:fs';
import { brotliCompressSync, gzipSync } from 'node:zlib';

const BUDGET_GZIP = 10 * 1024; // widget autonome (CDN), min + gzip

const files = [
  ['labyrinthe-otp.min.js', 'widget, standalone <script> (min)'],
  ['index.js', 'widget, ESM (not minified)'],
  ['react.js', 'React wrapper, ESM (not minified, without the widget)'],
  ['server.js', 'server helper, ESM (not minified)'],
];

const kb = (bytes) => `${(bytes / 1024).toFixed(2)} kB`;
let failed = false;
for (const [file, label] of files) {
  const code = readFileSync(new URL(`../dist/${file}`, import.meta.url));
  const gzip = gzipSync(code, { level: 9 }).length;
  const brotli = brotliCompressSync(code).length;
  console.log(`${file.padEnd(24)} ${kb(code.length).padStart(10)}  gzip ${kb(gzip).padStart(9)}  brotli ${kb(brotli).padStart(9)}  ${label}`);
  if (file === 'labyrinthe-otp.min.js' && gzip > BUDGET_GZIP) {
    console.error(`::error::${file} is ${kb(gzip)} min+gzip, budget ${kb(BUDGET_GZIP)}`);
    failed = true;
  }
}
process.exit(failed ? 1 : 0);

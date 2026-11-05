import { defineConfig, type Options } from 'tsup';

const common: Options = {
  format: ['esm', 'cjs'],
  dts: true,
  tsconfig: 'tsconfig.build.json',
  // Le wrapper React importe l'élément par le nom du package : une seule classe, une seule définition.
  external: ['@labyrinthe/otp-widget', 'react'],
  target: 'es2022',
  sourcemap: false,
  splitting: false,
  treeshake: true,
};

export default defineConfig([
  // Élément <labyrinthe-otp> (navigateur ; importable côté serveur sans effet).
  { ...common, entry: { index: 'src/index.ts' }, platform: 'browser', clean: true },
  // Wrapper React : composant client (Next.js App Router).
  // (sans treeshake rollup, qui supprimerait la directive « use client »).
  { ...common, entry: { react: 'src/react.tsx' }, platform: 'browser', treeshake: false, banner: { js: "'use client';" } },
  // Helper serveur : Fetch API (Next.js, edge) + adaptateur Express, sans module Node.
  { ...common, entry: { server: 'src/server/index.ts' }, platform: 'neutral' },
  // Bundle autonome pour une balise <script> (CDN, page statique) : minifié, sans types.
  {
    entry: { 'labyrinthe-otp.min': 'src/index.ts' },
    format: ['iife'],
    globalName: 'LabyrintheOtp',
    platform: 'browser',
    // Champs privés natifs (Chrome 84, Firefox 90, Safari 15) : bundle plus petit qu'en ES2020.
    target: 'es2022',
    minify: true,
    outExtension: () => ({ js: '.js' }),
    dts: false,
    sourcemap: false,
  },
]);

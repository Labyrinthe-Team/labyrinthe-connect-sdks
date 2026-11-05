import { defineConfig } from 'tsup';

export default defineConfig({
  entry: { index: 'src/index.ts', express: 'src/express.ts', next: 'src/next.ts' },
  format: ['esm', 'cjs'],
  dts: true,
  tsconfig: 'tsconfig.build.json',
  // Les helpers importent le cœur par le nom du package : une seule copie des classes d'erreur.
  external: ['@labyrinthe/connect'],
  platform: 'neutral',
  target: 'es2022',
  clean: true,
  sourcemap: false,
  splitting: false,
  treeshake: true,
});

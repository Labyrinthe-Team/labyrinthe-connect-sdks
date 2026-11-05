import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

const here = (path: string) => fileURLToPath(new URL(path, import.meta.url));

export default defineConfig({
  resolve: {
    alias: [
      { find: /^@labyrinthe\/otp-widget$/, replacement: here('./src/index.ts') },
      { find: /^@labyrinthe\/otp-widget\/server$/, replacement: here('./src/server/index.ts') },
      { find: /^@labyrinthe\/otp-widget\/react$/, replacement: here('./src/react.tsx') },
      // SDK Node du monorepo (sources), pour le test d'intégration et la parité de normalisation.
      { find: /^@labyrinthe\/connect$/, replacement: here('../node/src/index.ts') },
    ],
  },
  test: {
    include: ['tests/**/*.test.ts', 'tests/**/*.test.tsx'],
    // Par défaut Node (helper serveur) ; les tests du composant déclarent `@vitest-environment happy-dom`.
    environment: 'node',
    testTimeout: 20_000,
  },
});

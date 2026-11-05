# Spécification OpenAPI : API développeurs Labyrinthe Connect

`openapi.yaml` (OpenAPI 3.1) est **la source de vérité** de l'API publique `/api/public/v1`. Elle sert de base à
tous les SDK, à la documentation et aux tests de contrat. Elle décrit l'API **telle qu'elle est** ; les
améliorations prévues sont dans `../BACKEND-TODO.md`.

Contenu : 8 opérations (SMS, estimation, solde, Sender IDs, OTP send/verify), 4 webhooks (`message.status_changed`,
`campaign.completed`, `balance.low`, `test.ping`), le format d'erreur et chaque code d'erreur, les limites de débit, l'idempotence et la signature des
webhooks.

## Commandes

```bash
npm install

npm run lint            # validation de la spec (Redocly)
npm run check:routes    # spec ↔ routes Laravel : aucune route oubliée, aucun chemin mort
npm run test:contract   # appels réels à un backend, réponses validées contre la spec
npm run sync            # copie vers ../../labyrinthe-connect-backend/docs/openapi.yaml
npm run docs            # documentation HTML statique dans dist/
```

### Test de contrat

```bash
LABYRINTHE_API_KEY=lsk_test_… npm run test:contract
# facultatif : LABYRINTHE_BASE_URL (défaut http://localhost:8000/api/public/v1), LABYRINTHE_TEST_PHONE
```

- Le script **refuse une clé live** : il envoie des SMS et des OTP.
- Il vérifie le statut HTTP, que ce statut est documenté, que le corps respecte le schéma, et quelques règles métier
  (idempotence, encodage UCS-2, codes d'erreur).

## Modifier l'API

1. Modifier le backend, puis `openapi.yaml`.
2. `npm run lint && npm run check:routes`, puis le test de contrat sur un backend local.
3. `npm run sync`. Le test backend `OpenApiDocumentTest` échoue si la spec copiée ne correspond plus aux routes.
4. Monter `info.version` (SemVer) et noter le changement dans les CHANGELOG des SDK concernés.

Le backend sert la copie sur `GET /api/public/openapi.yaml` (sans authentification).

Après une modification de la spec, régénérer aussi la collection Postman : `cd ../postman && npm run generate`.

## Publication sur GitHub Pages

`npm run docs` produit une page HTML autonome (Redocly) dans `dist/index.html`. Le workflow
`.github/workflows/docs.yml` la reconstruit (lint + docs) et la déploie sur GitHub Pages à chaque push sur `main`
touchant `openapi/**`, ou à la demande (*Run workflow*).

Mise en place, une seule fois : *Settings > Pages > Build and deployment > Source* : **GitHub Actions**.
L'environnement `github-pages` est créé automatiquement au premier déploiement.

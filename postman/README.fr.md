# Labyrinthe Connect — Collection Postman

*[English version](README.md)*

Collection [Postman](https://www.postman.com/) (v2.1) prête à l'emploi de l'API développeurs Labyrinthe Connect
(SMS et OTP), générée depuis [`../openapi/openapi.yaml`](../openapi/openapi.yaml), la source de vérité de l'API.
Collection **1.3.1**, générée depuis la spec 1.3.1 (voir le [CHANGELOG](CHANGELOG.md)).

| Fichier | Contenu |
|---|---|
| `labyrinthe-connect.postman_collection.json` | la collection : un dossier par tag (SMS, Compte, OTP), auth Bearer au niveau de la collection, scripts de test légers |
| `local.postman_environment.json` | `baseUrl` = `http://localhost:8000/api/public/v1`, `apiKey` vide |
| `production.postman_environment.json` | `baseUrl` = `https://api.connect.labyrinthe-rdc.com/api/public/v1`, `apiKey` vide |

Requêtes : `POST /sms`, `GET /sms`, `GET /sms/:id`, `POST /sms/estimate` (SMS) ; `GET /balance`, `GET /sender-ids`
(Compte) ; `POST /otp/send`, son rejeu avec la même `Idempotency-Key`, `POST /otp/verify` (OTP). Chaque requête
documente ses réponses, dont `503 FEATURE_DISABLED` (fonctionnalité coupée par la plateforme).

## 1. Importer

1. Postman > **Import** > `labyrinthe-connect.postman_collection.json`.
2. Importer un environnement : `local.postman_environment.json` ou `production.postman_environment.json`, puis le
   sélectionner (sélecteur d'environnement en haut à droite).

## 2. Renseigner la clé API

Dans l'environnement sélectionné, renseigner `apiKey` (type *secret*) avec une **clé de test** `lsk_test_…`, créée
dans l'espace client (*Développeurs > Clés API*). Avec une clé de test, rien n'est envoyé ni facturé. Ne garder la clé
que dans la *current value* de l'environnement : ne jamais versionner une clé ni partager un environnement exporté
qui en contient une.

La clé est envoyée en `Authorization: Bearer {{apiKey}}` par l'auth de la collection, héritée par chaque requête.

## 3. Jouer le parcours nominal

Avec une clé `lsk_test_…`, exécuter toute la collection dans l'ordre (**Run collection**, Collection Runner). Elle
joue :

1. `POST /sms` → enregistre `messageId`, puis `GET /sms/:id` lit ce message ;
2. `POST /otp/send` (numéro RDC aléatoire) → enregistre `otpId` et `otpTestCode` (renvoyé en mode test seulement) ;
3. le rejeu de `POST /otp/send` avec la même `Idempotency-Key` → même `otp_id`, `Idempotent-Replayed: true`, aucun
   nouveau SMS ;
4. `POST /otp/verify` avec `otpTestCode` → `verified: true`.

Chaque test vérifie aussi les en-têtes de réponse `X-Request-Id` et `X-Api-Version: 1`. Avec une clé live,
`test_code` n'est jamais renvoyé : saisir le code reçu par SMS dans le corps de `POST /otp/verify`.

### Variables

| Variable | Renseignée par | Utilisée par |
|---|---|---|
| `baseUrl` | environnement (défaut de la collection : local) | toutes les requêtes |
| `apiKey` | vous (secret) | auth Bearer de la collection, héritée par chaque requête |
| `messageId` | script de test de `POST /sms` | `GET /sms/:id` |
| `otpPhone` | script de pré-requête de `POST /otp/send` (numéro RDC aléatoire) | `POST /otp/send` |
| `otpIdempotencyKey` | script de pré-requête de `POST /otp/send` (UUID) | `POST /otp/send` et son rejeu |
| `otpId`, `otpTestCode` | script de test de `POST /otp/send` | rejeu de `POST /otp/send`, `POST /otp/verify` |

`POST /sms` envoie `Idempotency-Key: {{$guid}}` (une nouvelle clé à chaque exécution). `POST /otp/send` envoie
`Idempotency-Key: {{otpIdempotencyKey}}`, puis la requête « Replay with the same Idempotency-Key » le rejoue. Chaque
requête a un en-tête `X-Request-Id` (`{{$guid}}`) désactivé : l'activer pour suivre une requête avec le support.

Les limites de débit s'appliquent comme en production : 10 `POST /otp/send` par minute et par clé. Attendre une
minute entre deux exécutions complètes.

## 4. Newman (ligne de commande, CI)

```bash
npm ci
LABYRINTHE_API_KEY=lsk_test_… npm test    # Newman contre un backend local (LABYRINTHE_BASE_URL pour changer)
```

`npm test` (`scripts/newman.mjs`) refuse une clé live ; la clé n'est lue que dans l'environnement, injectée en
mémoire et jamais affichée. `LABYRINTHE_BASE_URL` accepte un hôte nu (`http://localhost:8000` est complété avec
`/api/public/v1`).

Avec la CLI Newman directement (la clé apparaît alors dans les arguments du processus et l'historique du shell —
préférer `npm test`) :

```bash
npx newman run labyrinthe-connect.postman_collection.json -e local.postman_environment.json \
  --env-var "apiKey=$LABYRINTHE_API_KEY"
```

## 5. Régénérer et vérifier

```bash
npm ci
npm run generate    # réécrit labyrinthe-connect.postman_collection.json depuis ../openapi/openapi.yaml (déterministe)
npm run check       # échoue si la collection versionnée n'est pas à jour (CI : postman.yml)
```

Ne pas modifier la collection à la main : modifier la spec (ou `scripts/generate.mjs`, qui ajoute les scripts et les
variables) puis régénérer. Après une modification de la spec, lancer `npm run generate`, versionner la collection, et
aligner la version de `package.json` et du [CHANGELOG](CHANGELOG.md) sur celle de la spec.

## Licence

MIT — voir [LICENSE](LICENSE).

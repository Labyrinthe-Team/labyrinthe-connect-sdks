# Labyrinthe Connect — Collection Postman

*[English version](README.md)*

Collection [Postman](https://www.postman.com/) prête à l'emploi de l'API Labyrinthe Connect (SMS et OTP),
générée depuis [`../openapi/openapi.yaml`](../openapi/openapi.yaml), la source de vérité de l'API.

## Import

1. Postman > **Import** > `labyrinthe-connect.postman_collection.json`.
2. Importer un environnement : `local.postman_environment.json` (`http://localhost:8000/api/public/v1`) ou
   `production.postman_environment.json` (`https://api.labyrinthe-rdc.com/api/public/v1`).
3. Renseigner `apiKey` (type *secret*) dans l'environnement, de préférence une **clé de test** `lsk_test_…`.
   Ne jamais versionner une clé.

## Variables

| Variable | Renseignée par | Utilisée par |
|---|---|---|
| `baseUrl` | environnement (défaut de la collection : local) | toutes les requêtes |
| `apiKey` | vous (secret) | auth Bearer de la collection, héritée par chaque requête |
| `messageId` | script de test de `POST /sms` | `GET /sms/:id` |
| `otpPhone` | script de pré-requête de `POST /otp/send` (numéro RDC aléatoire) | `POST /otp/send` |
| `otpIdempotencyKey` | script de pré-requête de `POST /otp/send` (UUID) | `POST /otp/send` et son rejeu |
| `otpId`, `otpTestCode` | script de test de `POST /otp/send` | rejeu de `POST /otp/send`, `POST /otp/verify` |

`POST /sms` envoie `Idempotency-Key: {{$guid}}` (une nouvelle clé à chaque exécution). `POST /otp/send` envoie
`Idempotency-Key: {{otpIdempotencyKey}}`, puis la requête « Replay with the same Idempotency-Key » le rejoue : même
`otp_id`, en-tête `Idempotent-Replayed: true`, aucun nouveau SMS. Chaque requête a un en-tête `X-Request-Id`
(`{{$guid}}`) désactivé, à activer pour suivre une requête avec le support ; chaque test vérifie les en-têtes de
réponse `X-Request-Id` et `X-Api-Version: 1`.

## Mode test

Avec une clé `lsk_test_…`, rien n'est envoyé ni facturé. `POST /otp/send` renvoie alors `test_code`, enregistré
dans `otpTestCode` : exécuter toute la collection dans l'ordre (Collection Runner) joue le parcours nominal
envoi → détail, et OTP send → rejeu → verify (`verified: true`). Avec une clé live, `test_code` n'est jamais renvoyé :
saisir le code reçu par SMS dans le corps de `POST /otp/verify`.

## Régénérer et vérifier

```bash
npm ci
npm run generate                          # réécrit labyrinthe-connect.postman_collection.json (sortie déterministe)
npm run check                             # échoue si la collection versionnée n'est pas à jour (CI : postman.yml)
LABYRINTHE_API_KEY=lsk_test_… npm test    # Newman contre un backend local (LABYRINTHE_BASE_URL pour changer)
```

`npm test` refuse une clé live ; la clé n'est lue que dans l'environnement et n'est jamais affichée.

Ne pas modifier la collection à la main : modifier la spec (ou `scripts/generate.mjs`) puis régénérer.

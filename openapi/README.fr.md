# Labyrinthe Connect — Spécification OpenAPI

*[English version](README.md)*

[`openapi.yaml`](openapi.yaml) (OpenAPI 3.1) est **la source de vérité** de l'API développeurs publique de
Labyrinthe Connect (`/api/public/v1`) : envoi de SMS et vérification OTP. Elle sert de base à tous les SDK, à la
collection Postman, à la documentation HTML et aux tests de contrat. Elle décrit l'API **telle qu'elle est**.

Version **1.3.1**. Les changements de chaque version sont décrits dans les sections « Nouveautés 1.3.1 », « Nouveautés 1.3.0 » et
« Nouveautés 1.2.0 » de `info.description` ; `info.version` suit SemVer. La spec est rédigée en français (descriptions,
exemples de messages d'erreur), comme les messages renvoyés par l'API.

- Documentation HTML publiée : <https://labyrinthe-team.github.io/labyrinthe-connect-sdks/> (GitHub Pages, voir
  [plus bas](#publication-sur-github-pages)).
- Copie servie par le backend, sans authentification : `GET https://api.connect.labyrinthe-rdc.com/api/public/openapi.yaml`.

## Contenu

| Partie | Contenu |
|---|---|
| `info.description` | authentification (`lsk_live_…` / `lsk_test_…`), numéros, crédits et segments, idempotence, format d'erreur, maintenance, identifiant de requête, versions, dates, limites de débit, nouveautés |
| `servers` | production `https://api.connect.labyrinthe-rdc.com/api/public/v1`, local `http://localhost:8000/api/public/v1` |
| `paths` (8 opérations) | `POST /sms` (`sendSms`), `GET /sms` (`listSms`), `POST /sms/estimate` (`estimateSms`), `GET /sms/{id}` (`getSms`), `GET /balance` (`getBalance`), `GET /sender-ids` (`listSenderIds`), `POST /otp/send` (`sendOtp`), `POST /otp/verify` (`verifyOtp`) ; tags SMS, Compte, OTP |
| `webhooks` (6) | `message.status_changed`, `campaign.completed`, `balance.low`, `otp.verified`, `otp.failed`, `test.ping` : enveloppe `{ id, event, data, timestamp }`, signature `X-Signature: sha256=…` (HMAC-SHA256 du corps brut), 5 tentatives |
| `components.schemas` | `Message`, `Estimate`, `Balance`, `SenderId`, `OtpSendResult`, `OtpVerifyResult`, `Paginated`, enums (`MessageStatus`, `Encoding`, `SenderIdStatus`, `RejectionReason`), `Error` et chaque erreur typée (`ValidationError`, `InsufficientCreditsError`, `IdempotencyInProgressError`, `RecipientNotSendableError`, `TooManyRequestsError`, `OtpResendTooSoonError`, `OtpRateLimitedError`, `FeatureDisabledError`), `ErrorCode`, données des webhooks |
| `components.responses` | réponses réutilisées : `Unauthenticated` (401), `Forbidden` (403), `NotFound` (404), `ValidationError` (422), `TooManyRequests` (429), `ServiceUnavailable` (503) |
| `components.headers` | `X-Request-Id`, `X-Api-Version`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Idempotent-Replayed`, `Retry-After` (et ses variantes idempotence et maintenance) |
| `x-common-responses` | réponses communes à toutes les routes, hors opération (OpenAPI n'a pas de réponses globales) : `404 NOT_FOUND` « Route introuvable. » et `405 HTTP_ERROR` (`details.allowed_methods`, en-tête `Allow`) |

### Erreurs

Toujours `{ "error": { "code", "message", "details" } }`, `details` **toujours un objet**, messages en français.
Les codes (`ErrorCode`) forment une liste ouverte : un client doit accepter un code inconnu. Principaux codes :
`VALIDATION_ERROR` (422), `UNAUTHENTICATED` (401), `FORBIDDEN` / `ACCOUNT_SUSPENDED` / `OTP_DISABLED_FOR_ACCOUNT`
(403), `NOT_FOUND` (404), `HTTP_ERROR` (405), `INSUFFICIENT_CREDITS`, `IDEMPOTENCY_KEY_REUSED`,
`IDEMPOTENCY_REQUEST_IN_PROGRESS`, `IDEMPOTENCY_KEY_UNRESOLVED` (409), `RECIPIENT_NOT_SENDABLE`,
`SENDER_ID_NOT_APPROVED`, `INVALID_PHONE`, `INVALID_OTP_TEMPLATE` (422), `TOO_MANY_REQUESTS`, `OTP_RESEND_TOO_SOON`,
`OTP_RATE_LIMITED` (429), `FEATURE_DISABLED`, `OTP_SERVICE_DISABLED` (503).

### Maintenance (503 `FEATURE_DISABLED`)

Nouveauté de la 1.3.0 : toute opération peut renvoyer `503 FEATURE_DISABLED` quand un administrateur coupe toute
l'API (`api`, refus **avant** l'authentification) ou une fonction (`api.sms_send`, `api.otp_send`, `api.otp_verify`).
Rien n'est fait (aucun envoi, aucun débit). `details` = `{ feature, blocked_by, message, until }`, et l'en-tête
`Retry-After` est présent quand une réactivation est programmée. `503 OTP_SERVICE_DISABLED` (`POST /otp/send`, service
OTP entier coupé) porte les mêmes `details`. Description complète : section « Maintenance » de `info.description`,
schéma `FeatureDisabledError`, réponse `ServiceUnavailable`. Un client relance après `Retry-After` s'il est présent,
jamais en boucle.

## Commandes

Node.js 22 (comme la CI) :

```bash
npm ci

npm run lint            # validation de la spec (Redocly, redocly.yaml)
npm run check:routes    # spec ↔ routes Laravel : aucune route oubliée, aucun chemin mort
npm run test:contract   # appels réels à un backend, réponses validées contre la spec
npm run sync            # copie vers ../../labyrinthe-connect-backend/docs/openapi.yaml
npm run docs            # documentation HTML statique dans dist/index.html
```

`check:routes` et `sync` lisent le backend dans `../../labyrinthe-connect-backend` (variable
`LABYRINTHE_BACKEND_DIR` pour changer) ; `check:routes` lance `php artisan route:list`.

### Test de contrat

```bash
LABYRINTHE_API_KEY=lsk_test_… npm run test:contract
# facultatif : LABYRINTHE_BASE_URL (défaut http://localhost:8000/api/public/v1), LABYRINTHE_TEST_PHONE
```

- Le script **refuse une clé live** : il envoie des SMS et des OTP. La clé n'est lue que dans l'environnement.
- Il vérifie le statut HTTP, que ce statut est documenté, que le corps respecte le schéma, les en-têtes communs
  (`X-Request-Id`, `X-Api-Version: 1`, `details` toujours objet) et des règles métier (idempotence SMS et OTP,
  encodage, `strip_accents`, dédoublonnage de l'estimation, numéros étrangers, `Retry-After`, 405, messages en
  français).
- Il appelle 8 fois `POST /otp/send` (limite : 10 / minute par clé) : attendre une minute avant de le relancer.

## Modifier l'API

La spec suit le backend, jamais l'inverse ; les SDK suivent la spec (voir [`../CONTRIBUTING.md`](../CONTRIBUTING.md)).

1. Modifier le backend, puis `openapi.yaml`. Monter `info.version` (SemVer) et ajouter une section « Nouveautés x.y.z »
   à `info.description`.
2. `npm run lint && npm run check:routes`, puis le test de contrat sur un backend local
   (`LABYRINTHE_API_KEY=lsk_test_… npm run test:contract`).
3. `npm run sync`. Le test backend `OpenApiDocumentTest` échoue si la spec copiée ne correspond plus aux routes. Le
   backend sert cette copie sur `GET /api/public/openapi.yaml`.
4. Régénérer la collection Postman : `cd ../postman && npm run generate` (la CI `postman.yml` vérifie qu'elle est à
   jour).
5. Mettre à jour chaque SDK (types, enums, erreurs, docs) et son CHANGELOG ; les tests de contrat des SDK échouent
   quand les enums, limites ou opérations divergent de la spec.
6. Une limite de l'API qui gêne l'intégration est signalée à l'équipe backend avec une proposition ; elle n'est
   jamais contournée dans un SDK.

La CI [`openapi.yml`](../.github/workflows/openapi.yml) lance le lint sur chaque push et pull request touchant
`openapi/**`, et `check:routes` quand la variable de dépôt `LABYRINTHE_BACKEND_REPOSITORY` et le secret
`LABYRINTHE_BACKEND_TOKEN` donnent accès au dépôt du backend.

## Publication sur GitHub Pages

`npm run docs` produit une page HTML autonome (Redocly) dans `dist/index.html`. Le workflow
[`.github/workflows/docs.yml`](../.github/workflows/docs.yml) la reconstruit (`npm ci`, lint, docs) et la déploie sur
GitHub Pages à chaque push sur `main` touchant `openapi/**`, ou à la demande (*Run workflow*). Adresse :
<https://labyrinthe-team.github.io/labyrinthe-connect-sdks/>.

Mise en place, une seule fois : *Settings > Pages > Build and deployment > Source* : **GitHub Actions**.
L'environnement `github-pages` est créé automatiquement au premier déploiement.

## Générer un client

Les SDK officiels (PHP, Laravel, Node.js, Python, .NET, Java) couvrent l'idempotence, les relances sûres et la
vérification des webhooks : préférez-les. Pour un autre langage, la spec permet de générer un client, par exemple
avec [OpenAPI Generator](https://openapi-generator.tech/) :

```bash
# depuis le fichier local…
npx @openapitools/openapi-generator-cli generate -i openapi.yaml -g go -o ./labyrinthe-go
# …ou depuis la copie servie par l'API
npx @openapitools/openapi-generator-cli generate \
  -i https://api.connect.labyrinthe-rdc.com/api/public/openapi.yaml -g ruby -o ./labyrinthe-ruby
```

Points d'attention pour un client généré :

- envoyer une `Idempotency-Key` (UUID) sur `POST /sms` et `POST /otp/send`, et la **réutiliser** à chaque relance ;
- ne relancer qu'en 429 / 5xx / erreur réseau (jamais `POST /otp/verify` après une erreur réseau ou une 5xx), et une
  503 `FEATURE_DISABLED` seulement après `Retry-After` ;
- accepter les champs, codes d'erreur et événements inconnus (ajouts compatibles sans préavis en v1) ;
- vérifier les webhooks soi-même (HMAC-SHA256 du corps brut, comparaison en temps constant, horodatage) : la plupart
  des générateurs ignorent la section `webhooks` ;
- certains générateurs gèrent encore partiellement OpenAPI 3.1 : vérifier le code produit.

La spec convient aussi aux outils qui lisent OpenAPI : import dans Postman ou Insomnia, serveurs simulés (Prism),
validation de requêtes.

## Fichiers

```
openapi.yaml              la spécification
redocly.yaml              règles du lint Redocly
scripts/lib.mjs           utilitaires communs (chemin du backend, lecture de la spec)
scripts/check-routes.mjs  comparaison avec php artisan route:list
scripts/contract.mjs      test de contrat contre un backend
scripts/sync.mjs          copie vers le backend
dist/                     documentation HTML générée (non versionnée)
```

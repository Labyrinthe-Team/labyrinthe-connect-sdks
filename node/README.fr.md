# Labyrinthe Connect — SDK Node.js / TypeScript

[![npm](https://img.shields.io/npm/v/@labyrinthe/connect.svg)](https://www.npmjs.com/package/@labyrinthe/connect)
[![CI](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/node.yml/badge.svg)](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/node.yml)
[![Licence : MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)
![Node](https://img.shields.io/badge/node-%E2%89%A5%2018-339933)

Client JavaScript / TypeScript officiel de [Labyrinthe Connect](https://connect.labyrinthe-rdc.com), la plateforme SMS et OTP
pour la RD Congo et l'Afrique centrale : envoyer des SMS, suivre leur remise et vérifier des numéros de téléphone par
code à usage unique (OTP).

*English version: [README.md](README.md).*

- **Entièrement typé** : objets en camelCase, `Date`, enums en littéraux de chaîne, une classe d'erreur par code.
- **Jamais de double SMS** : chaque envoi porte une `Idempotency-Key` ; les relances automatiques n'ont lieu que
  lorsqu'un rejeu est sans risque.
- **Aucune dépendance**, ESM + CommonJS, Node.js 18+, Vercel / Next.js Edge, Cloudflare Workers, Deno et Bun.
- **Sûr par défaut** : la clé API, les codes OTP et les numéros complets n'apparaissent jamais dans les logs,
  `console.log()` ou `JSON.stringify()` ; les webhooks sont vérifiés en temps constant sur le corps brut.
- Helpers de webhook pour **Express** et **Next.js** (App Router, et tout runtime Fetch `Request` / `Response`).

Version **1.2.1**, construite sur la spécification de l'API **1.3.1**
([`openapi.yaml`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/openapi/openapi.yaml)).
Nouveautés : [CHANGELOG](CHANGELOG.md) (en anglais).

## Sommaire

1. [Prérequis](#prérequis)
2. [Installation](#installation)
3. [Démarrage rapide](#démarrage-rapide)
4. [Configuration](#configuration)
5. [Envoyer des SMS](#envoyer-des-sms)
6. [Solde et Sender IDs](#solde-et-sender-ids)
7. [Vérifier un numéro par OTP](#vérifier-un-numéro-par-otp)
8. [Webhooks](#webhooks)
9. [Erreurs](#erreurs)
10. [Relances et idempotence](#relances-et-idempotence)
11. [Mode test, journalisation, identifiants de requête et types](#mode-test-journalisation-identifiants-de-requête-et-types)
12. [Sans le package (HTTP brut)](#sans-le-package-http-brut)
13. [Mise à jour](#mise-à-jour)
14. [Contribuer](#contribuer) · [Licence](#licence)

## Prérequis

| Runtime | Prise en charge |
|---|---|
| Node.js 18, 20, 22, 24 (`engines: >=18`) | ✅ `fetch` global ; sur Node 18, Web Crypto est chargé depuis le module crypto au premier usage |
| Vercel Edge, routes Edge de Next.js | ✅ |
| Cloudflare Workers | ✅ sans le drapeau `nodejs_compat` |
| Deno, Bun | ✅ (`npm:@labyrinthe/connect` sous Deno) |
| Navigateurs | ❌ refusé par défaut : la clé API doit rester côté serveur |

Les types sont fournis pour ESM (`.d.ts`) et CommonJS (`.d.cts`). `express` (≥ 4.17) et `next` (≥ 13.4) sont des
dépendances pairs facultatives, utilisées seulement par les helpers `@labyrinthe/connect/express` et
`@labyrinthe/connect/next`.

## Installation

```bash
npm install @labyrinthe/connect
# ou
pnpm add @labyrinthe/connect
# ou
yarn add @labyrinthe/connect
```

Deno : `import { Labyrinthe } from 'npm:@labyrinthe/connect';` — Bun : `bun add @labyrinthe/connect`.

## Démarrage rapide

Créez une clé dans l'espace client (*Développeurs > Clés API*). Commencez avec une **clé de test** (`lsk_test_…`) :
rien n'est envoyé ni facturé, mais les réponses ont la même forme qu'en production.

```ts
// ESM / TypeScript
import { Labyrinthe } from '@labyrinthe/connect';

const client = new Labyrinthe({ apiKey: 'lsk_test_…' });   // ou LABYRINTHE_API_KEY, puis new Labyrinthe()
const message = await client.sms.send({ to: '0812345678', body: 'Votre commande 1042 est prête.' });
console.log(message.id, message.status);                    // "9d5c0a3e-…" "queued"
```

```js
// CommonJS
const { Labyrinthe } = require('@labyrinthe/connect');

async function main() {
  const client = new Labyrinthe();                           // lit LABYRINTHE_API_KEY
  const message = await client.sms.send({ to: '0812345678', body: 'Votre commande 1042 est prête.' });
  console.log(message.status);                               // "queued"
}
main();
```

| Clé | Mode | Effet |
|---|---|---|
| `lsk_test_…` | Bac à sable | Fournisseur simulé : rien n'est envoyé, aucun crédit consommé, solde non vérifié. `otp.send()` renvoie le code (`testCode`). |
| `lsk_live_…` | Production | Les SMS partent réellement et consomment des crédits. |

**Gardez la clé côté serveur** : jamais dans un navigateur, une application mobile ou une variable `NEXT_PUBLIC_…`.
Le client refuse de démarrer dans un navigateur (`InvalidArgumentError`), sauf avec `dangerouslyAllowBrowser: true`.
Pour vérifier un numéro depuis une page web, utilisez
[`@labyrinthe/otp-widget`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/tree/main/widget-otp), qui appelle
*votre* serveur.

Exemples exécutables : [`examples/send-sms.ts`](examples/send-sms.ts), [`examples/otp-login.ts`](examples/otp-login.ts),
[`examples/webhook-express.ts`](examples/webhook-express.ts), [`examples/webhook-next-route.ts`](examples/webhook-next-route.ts).

## Configuration

```ts
import { Labyrinthe } from '@labyrinthe/connect';

const client = new Labyrinthe({
  apiKey: process.env.LABYRINTHE_API_KEY,                  // par défaut
  baseUrl: 'https://api.connect.labyrinthe-rdc.com/api/public/v1', // par défaut (ou LABYRINTHE_BASE_URL)
  timeoutMs: 30_000,
  maxRetries: 2,
  maxRetryWaitMs: 10_000,
  defaultCountryCode: '243',
  defaultSender: 'MYSHOP',
  fetch: globalThis.fetch,
  logger: console,
});
```

| Option | Défaut | Description |
|---|---|---|
| `apiKey` | `LABYRINTHE_API_KEY` | `lsk_live_…` ou `lsk_test_…`. Absente → `InvalidArgumentError`. |
| `baseUrl` | `LABYRINTHE_BASE_URL`, puis `https://api.connect.labyrinthe-rdc.com/api/public/v1` | Un hôte seul (`http://localhost:8000`) est complété par `/api/public/v1`. |
| `timeoutMs` | `30000` | Délai maximal de **chaque tentative**, en millisecondes. |
| `maxRetries` | `2` | Relances automatiques quand elles sont sans risque (0 à 10 ; `0` les désactive). |
| `maxRetryWaitMs` | `10000` | Plus long `Retry-After` accepté ; au-delà, l'erreur est levée. Plafonne aussi le backoff. |
| `defaultCountryCode` | `'243'` (RD Congo) | Ajouté aux numéros locaux : `0812345678` → `243812345678`. 1 à 3 chiffres, sans `+`. |
| `defaultSender` | — | Sender ID utilisé quand `sms.send()` / `sendMany()` n'en reçoivent pas (sinon celui de la plateforme). |
| `fetch` | `fetch` global | Tout `fetch` compatible WHATWG (undici, agent proxy, mock). |
| `logger` | — | `{ debug?, info?, warn?, error? }(message, context)` : `console`, pino, winston… Voir [journalisation](#journalisation-et-secrets). |
| `dangerouslyAllowBrowser` | `false` | Autorise le client dans un navigateur. Votre clé deviendrait publique : à éviter. |

| Variable d'environnement | Lue par |
|---|---|
| `LABYRINTHE_API_KEY` | `new Labyrinthe()` sans `apiKey` |
| `LABYRINTHE_BASE_URL` | `new Labyrinthe()` sans `baseUrl` (backend local, préproduction) |
| `LABYRINTHE_WEBHOOK_SECRET` | `labyrintheWebhook()` (Express) et `labyrintheWebhookHandler()` (Next.js) sans `secret` |

Propriétés en lecture seule : `client.isTestMode`, `client.isLiveMode`, `client.baseUrl`. `console.log(client)` et
`JSON.stringify(client)` affichent `{ apiKey: 'lsk_test_****', baseUrl, testMode }`, jamais la clé.

Chaque méthode accepte un dernier argument facultatif d'options par appel (`RequestOptions`) :

| Option | Description |
|---|---|
| `signal` | `AbortSignal` : annule l'appel, attentes entre relances comprises (`RequestAbortedError`). |
| `timeoutMs` | Délai maximal de chaque tentative pour cet appel. |
| `requestId` | Votre propre `X-Request-Id` (1 à 128 caractères parmi `A-Z a-z 0-9 . _ : -`), envoyé à chaque relance. |
| `onResponse` | Reçoit `{ status, requestId, apiVersion, replayed, rateLimit: { limit, remaining }, headers }` de la réponse réussie. |

```ts
await client.balance.get({ signal: AbortSignal.timeout(5_000) });
```

### Numéros de téléphone

Le SDK normalise les numéros exactement comme l'API et les valide avant tout appel (`InvalidArgumentError` ; le
numéro n'est jamais cité dans le message) :

| Saisie | Normalisé |
|---|---|
| `+243 81 234 5678`, `00243812345678`, `243812345678` | `243812345678` |
| `0812345678`, `812345678` (local) | `243812345678` (avec `defaultCountryCode`) |
| `+33 6 12 34 56 78`, `33612345678` (sans `+`, 11 chiffres ou plus ne commençant pas par 0) | `33612345678` |

Les numéros partent vers l'API en E.164 avec `+`, donc un numéro étranger court garde son indicatif ; pour un numéro
étranger de 10 chiffres ou moins, saisissez toujours le `+`. Les réponses renvoient les chiffres sans `+`. Helpers :
`PhoneNumber.normalize(raw, countryCode?)` (`string | null`), `PhoneNumber.isValid()`, `PhoneNumber.normalizeOrThrow()`
et `PhoneNumber.mask('243812345678')` → `24381*****78`.

## Envoyer des SMS

### Un destinataire

```ts
const message = await client.sms.send({
  to: '+243 81 234 5678',      // +243…, 00243…, 243…, 0812…, 812… sont acceptés
  body: 'Votre commande 1042 est prête.',
  sender: 'MYSHOP',            // facultatif : un Sender ID approuvé (voir client.senderIds.list())
  reference: 'order-1042',     // facultatif : votre référence (64 caractères max.), filtrable dans sms.list()
  stripAccents: false,         // facultatif : voir « Coût avant envoi »
  idempotencyKey: 'order-1042-ready', // facultatif : voir « Relances et idempotence »
});

message.id;              // "9d5c0a3e-…"
message.recipient;       // "243812345678"
message.status;          // "queued"
message.encoding;        // "gsm7" | "ucs2"
message.segments;        // 1
message.creditsCharged;  // 1 (fictif avec une clé de test)
message.reference;       // "order-1042"
message.createdAt;       // Date
```

Limites vérifiées localement : `body` de 1 à 1600 caractères, `sender` de 1 à 11 caractères, `reference` ≤ 64
caractères. Un `Message` ne contient jamais le texte (il peut contenir un code).

L'envoi est asynchrone : `202 Accepted` signifie *mis en file et payé*, pas *remis*. Suivez le statut avec le webhook
`message.status_changed` (recommandé) ou en interrogeant `get()` :

```ts
import { isFailedStatus, isFinalStatus } from '@labyrinthe/connect';

const latest = await client.sms.get(message.id);       // NotFoundError s'il est inconnu
if (isFinalStatus(latest.status)) {
  console.log(isFailedStatus(latest.status) ? `Échec : ${latest.errorCode}` : 'Remis');
}
```

Statuts (`MessageStatus`) : `queued` → `submitted` → `sent` → `delivered`, ou `failed`, `rejected`, `expired`,
`undeliverable` (définitifs). `unknown` désigne un statut ajouté à l'API après cette version du SDK. Helpers :
`isPendingStatus()`, `isFinalStatus()`, `isFailedStatus()`.

### Plusieurs destinataires : tout ou rien

```ts
import { RecipientNotSendableError } from '@labyrinthe/connect';

try {
  const batch = await client.sms.sendMany({
    recipients: ['0812345678', '0899999999', '+33612345678'],
    body: 'Réunion demain à 9h.',
    reference: 'meeting-2026-11-07',
  });
  batch.messages;        // un Message par numéro distinct (doublons fusionnés après normalisation)
  batch.idempotencyKey;  // la clé utilisée (la vôtre ou générée)
  batch.replayed;        // true si l'API a rejoué un envoi identique précédent
  batch.requestId;       // X-Request-Id de la réponse
} catch (error) {
  if (error instanceof RecipientNotSendableError) {
    // RIEN n'a été envoyé ni facturé : retirez ces numéros et renvoyez.
    for (const { recipient, reason } of error.rejected) console.log(recipient, reason); // invalid_phone | blacklisted | opted_out
  } else {
    throw error;
  }
}
```

Jusqu'à 1000 destinataires par appel. Chaque destinataire, le Sender ID et le solde sont vérifiés **avant** tout
envoi : un seul numéro refusé (ou un solde insuffisant) et aucun SMS ne part. Les numéros que le SDK sait déjà
invalides sont refusés localement (`InvalidArgumentError`, avec leur position dans `recipients`).

### Coût avant envoi

1 crédit = 1 segment SMS. Un message purement ASCII (GSM-7) tient en 160 caractères (153 par segment au-delà).
**Tout caractère non ASCII** (é, à, ç, emoji, guillemets typographiques, tiret long…) passe le message en UCS-2 :
70 caractères (67 par segment au-delà), soit un coût souvent doublé.

```ts
const estimate = await client.sms.estimate({
  body: 'Réunion demain à 9h',
  recipients: ['0812345678', '0899999999'],   // une chaîne ou un tableau
});
estimate.encoding;           // "ucs2"
estimate.characters;         // 19
estimate.segmentsPerMessage; // 1
estimate.recipientsCount;    // 2 (normalisés, dédoublonnés, numéros invalides ignorés)
estimate.totalCredits;       // 2
estimate.balanceAfter;       // solde après l'envoi (négatif s'il est insuffisant), null si le compte n'a jamais eu de crédits
estimate.isAffordable;       // balanceAfter !== null && balanceAfter >= 0

// stripAccents : accents et caractères typographiques remplacés par de l'ASCII → GSM-7, souvent moitié prix
const ascii = await client.sms.estimate({ body: 'Réunion demain à 9h', recipients: '0812345678', stripAccents: true });
ascii.encoding;              // "gsm7"
```

`stripAccents` (`false` par défaut) existe sur `send()`, `sendMany()`, `estimate()` et `otp.send()`. La
translittération est faite par le serveur : ne l'utilisez pas quand le texte exact compte. `estimate()` n'envoie rien.

### Lister les messages

```ts
const page = await client.sms.list({
  status: 'failed',            // MessageStatus
  recipient: '0812345678',     // tout format, normalisé
  reference: 'order-1042',     // correspondance exacte
  from: '2026-11-01',          // inclus : une date, une date-heure ou une Date
  to: new Date(),              // inclus : une date couvre toute la journée
  page: 1,                     // 1 par défaut
  perPage: 50,                 // 1 à 100, 15 par défaut
});
page.data;          // Message[]
page.total; page.currentPage; page.lastPage; page.perPage;
page.hasMore;       // currentPage < lastPage
const next = await page.nextPage();   // Page | null

// Tous les messages de toutes les pages, chargées à la demande :
for await (const message of client.sms.list({ reference: 'order-1042' })) {
  console.log(message.createdAt, message.status);
}
```

`list()` renvoie une `PagePromise` : `await` pour une page, ou `for await` pour tout parcourir (une `Page` est aussi
itérable de manière asynchrone, à partir de cette page). La liste ne contient que les messages envoyés **avec la clé
API appelante** ; `get(id)` trouve tout message du compte par son `id`.

## Solde et Sender IDs

```ts
const { balance, held } = await client.balance.get();
// balance : crédits disponibles ; held : crédits réservés par les campagnes en cours

const senderIds = await client.senderIds.list();   // noms approuvés uniquement, utilisables comme `sender`
for (const s of senderIds) console.log(s.value, s.isDefault, s.status); // "MYSHOP" true "approved"
```

`SenderId` : `id`, `value` (≤ 11 caractères), `status`, `purpose`, `rejectionReason`, `isDefault`, `createdAt`. Les
Sender IDs se demandent et s'approuvent dans l'espace client.

## Vérifier un numéro par OTP

Le code est généré, envoyé par SMS et vérifié par Labyrinthe Connect. Il est stocké haché : l'API ne le renvoie jamais
en production et le SDK ne le journalise jamais.

```ts
import { Labyrinthe } from '@labyrinthe/connect';

const client = new Labyrinthe();

// 1. Envoyer le code. Gardez otpId CÔTÉ SERVEUR (session, cache, base), jamais dans le navigateur.
const otp = await client.otp.send({ phone: '0812345678' });
req.session.otpId = otp.otpId;
otp.expiresAt;           // Date
otp.maxAttempts;         // 5
otp.codeLength;          // 6 — affichez autant de cases (4 à 8, paramètres OTP du compte)
otp.codeType;            // "numeric" | "alphanumeric" (saisie insensible à la casse)
otp.resendAfterSeconds;  // 60 — compte à rebours « Renvoyer le code »
otp.testCode;            // le code avec une clé lsk_test_, null avec une clé live

// 2. L'utilisateur saisit le code reçu par SMS.

// 3. Le vérifier. Un code faux n'est PAS une exception.
const result = await client.otp.verify({ otpId: req.session.otpId, code: req.body.code });
if (result.verified) {
  delete req.session.otpId;
  // connecter l'utilisateur / marquer le numéro comme vérifié
} else if (result.reason === 'invalid_code') {
  console.log(`Encore ${String(result.attemptsRemaining)} essai(s)`);   // laisser réessayer
} else {
  // 'expired' | 'already_used' | 'max_attempts_reached' : demander un nouveau code
}
```

Options de `otp.send()` :

| Option | Description |
|---|---|
| `phone` | Obligatoire. Tout format accepté (voir [Numéros de téléphone](#numéros-de-téléphone)). |
| `template` | Doit contenir `{code}` ; `{app_name}`, `{minutes}` facultatifs. ≤ 1600 caractères. Défaut : paramètres OTP du compte. |
| `locale` | `'fr'` ou `'en'`, langue du modèle standard. Défaut : paramètres OTP du compte. |
| `webOtpDomain` | Hôte de votre site pour la Web OTP API (ci-dessous). Validé localement, mis en minuscules. |
| `stripAccents` | SMS en ASCII seulement (GSM-7). `false` par défaut. |
| `idempotencyKey` | ≤ 255 caractères ; générée si absente (voir [Relances](#relances-et-idempotence)). |

`OtpSendResult` expose aussi `idempotencyKey`, `replayed` (un rejeu renvoie le même `otpId`, sans nouveau SMS) et
`requestId`. `testCode` est masqué dans `console.log()` et `JSON.stringify()`.

`otp.verify({ otpId, code })` renvoie `{ verified, reason, attemptsRemaining }` et est **gratuit** :

| `verified` | `reason` | `attemptsRemaining` | Que faire |
|---|---|---|---|
| `true` | `null` | `null` | Faire confiance au numéro. |
| `false` | `invalid_code` | entier | Code faux, un essai consommé : laisser réessayer. |
| `false` | `max_attempts_reached` | `0` | Code bloqué : en envoyer un nouveau. |
| `false` | `expired` | `null` | Envoyer un nouveau code. |
| `false` | `already_used` | `null` | Chaque code est à usage unique : en envoyer un nouveau. |

Un `otpId` inconnu (ou d'un autre compte) lève `NotFoundError`. L'API ignore les espaces et la casse du code.

Règles et limites :

- Un nouvel `send()` au même numéro invalide le code précédent. Après une vérification réussie, un nouvel envoi est
  accepté immédiatement.
- Longueur (4 à 8), alphabet, validité, essais, délai de renvoi, Sender ID et modèle viennent des paramètres OTP du
  compte (espace client > *Vérification OTP*).
- `OtpResendTooSoonError` (429 `OTP_RESEND_TOO_SOON`) : un code a été envoyé à ce numéro trop récemment ; affichez un
  compte à rebours de `retryAfterMs`. `OtpRateLimitedError` (429 `OTP_RATE_LIMITED`) : plafond horaire atteint pour ce
  numéro ou pour tout le compte (`scope` : `'phone' | 'account'`, `limit`, `retryAfterMs`). Aucune des deux n'est
  relancée par le SDK.
- `otp/send` est limité à 10 requêtes par minute et par clé API (en plus des 120 par minute générales).
- L'issue de chaque vérification est aussi notifiée par les webhooks `otp.verified` / `otp.failed`.

### Web OTP (remplissage automatique sur Android)

Avec `webOtpDomain`, l'API termine le SMS par la ligne `@app.example.com #123456`, le format de la
[Web OTP API](https://developer.mozilla.org/docs/Web/API/WebOTP_API) : Chrome sur Android propose alors de remplir le
code dans votre page servie depuis **exactement cet hôte**, en HTTPS (page de premier niveau).

```ts
await client.otp.send({ phone: '0812345678', webOtpDomain: 'app.example.com' }); // hôte seul : ni schéma, ni port, ni chemin
```

```js
// Dans le navigateur (@labyrinthe/otp-widget le fait pour vous)
const { code } = await navigator.credentials.get({ otp: { transport: ['sms'] } });
```

## Webhooks

Configurez un point de terminaison dans l'espace client (*Développeurs > Webhooks*) et copiez son secret (il n'existe
pas d'API de gestion des webhooks). Les événements arrivent en `POST` JSON `{ id, event, data, timestamp }`, signés :
`X-Signature: sha256=<HMAC-SHA256(secret, corps brut) en hexadécimal>`. Le SDK vérifie la signature en temps constant
sur le **corps brut**, avant tout parsing, puis le `timestamp` signé (5 minutes par défaut) pour bloquer les rejeux.

Répondez `2xx` en moins de 10 secondes (mettez le travail long en file). Sinon la livraison est retentée jusqu'à
5 fois (après 30 s, 2 min, 5 min, 15 min) : **dédoublonnez sur `event.id`**, stable d'une tentative à l'autre. L'ordre
n'est pas garanti.

| `event` | `data` |
|---|---|
| `message.status_changed` | `messageId`, `recipient`, `status`, `providerMessageId`, `campaignId`, `reference`, `errorCode`, `errorMessage` |
| `campaign.completed` | `campaignId`, `name`, `recipientsCount`, `messagesByStatus`, `creditsCharged`, `completedAt` |
| `balance.low` | `balance`, `threshold` |
| `otp.verified` | `otpId`, `phone`, `status` (`verified`), `reason` (`null`), `verifiedAt` — jamais le code |
| `otp.failed` | mêmes champs : `status` `failed` (`reason` `max_attempts_reached`) ou `expired` (`reason` `expired`), `verifiedAt` `null` |
| `test.ping` | `{}` — envoyé par le bouton *Tester* de l'espace client |
| `unknown` | un événement ajouté après cette version du SDK : son nom dans `type`, son contenu dans `data` (tel quel). Répondez 2xx. |

Chaque événement a aussi `id`, `type` (le nom tel qu'envoyé), `timestamp` (`Date`) et `raw` (l'enveloppe décodée).
Faites un `switch` sur `event.event` pour obtenir un `data` typé.

### Express

```ts
import express from 'express';
import { labyrintheWebhook } from '@labyrinthe/connect/express';

const app = express();

// AVANT express.json() (ou avec express.raw({ type: 'application/json' }) sur cette route) : il faut le corps brut.
app.post('/webhooks/labyrinthe', labyrintheWebhook({
  secret: process.env.LABYRINTHE_WEBHOOK_SECRET,   // par défaut
  toleranceSeconds: 300,                           // par défaut ; 0 désactive le contrôle d'horodatage
  onEvent: async (event) => {
    if (!(await markProcessed(event.id))) return;  // votre dédoublonnage (ex. insertion unique) : déjà traité
    switch (event.event) {
      case 'message.status_changed':
        await updateMessage(event.data.messageId, event.data.status);
        break;
      case 'otp.failed':
        await flagPhone(event.data.phone, event.data.reason);
        break;
    }
  },
}));

app.use(express.json());
```

Signature ou contenu invalide → `400` (`{ error: { code, message } }`). `onEvent` se termine → `200 { received: true }`
(sauf si vous avez déjà répondu). `onEvent` lève une erreur → `next(error)` (500, la livraison sera retentée). Sans
`onEvent`, l'événement vérifié est placé dans `req.labyrintheEvent` et `next()` est appelé. Fonctionne aussi avec
Connect et `node:http`.

### Next.js (App Router)

```ts
// app/api/webhooks/labyrinthe/route.ts
import { labyrintheWebhookHandler } from '@labyrinthe/connect/next';

export const POST = labyrintheWebhookHandler({
  secret: process.env.LABYRINTHE_WEBHOOK_SECRET,   // par défaut
  onEvent: async (event) => {
    if (event.event === 'balance.low') {
      await alertFinance(event.data.balance, event.data.threshold);
    }
    // ne rien renvoyer → 200 { received: true } ; ou renvoyer votre propre Response
  },
});
```

Fonctionne sur les runtimes Node.js et Edge, et partout où un handler reçoit une `Request` Fetch et renvoie une
`Response` (Hono, `Bun.serve`, `Deno.serve`…). Signature invalide → `400` ; aucun secret configuré → `500`
`WEBHOOK_SECRET_MISSING` ; `onEvent` lève une erreur → `500` `WEBHOOK_HANDLER_FAILED` (journalisée avec l'id de
l'événement ; la livraison sera retentée).

### Tout runtime (Cloudflare Workers, Deno, Bun…)

```ts
import { InvalidWebhookPayloadError, SignatureVerificationError, Webhooks } from '@labyrinthe/connect';

export default {
  async fetch(request: Request, env: { LABYRINTHE_WEBHOOK_SECRET: string }): Promise<Response> {
    try {
      const event = await Webhooks.constructEvent(
        await request.arrayBuffer(),               // corps BRUT : string, Uint8Array/Buffer ou ArrayBuffer
        request.headers.get('x-signature'),
        env.LABYRINTHE_WEBHOOK_SECRET,
        { toleranceSeconds: 300 },
      );
      // … traiter l'événement (dédoublonner sur event.id)
      return new Response('ok');
    } catch (error) {
      if (error instanceof SignatureVerificationError || error instanceof InvalidWebhookPayloadError) {
        return new Response('invalid', { status: 400 });
      }
      throw error;
    }
  },
};
```

`Webhooks.constructEvent()` est asynchrone (Web Crypto) et n'accepte jamais un objet déjà parsé. Il lève
`SignatureVerificationError` (signature absente ou fausse, horodatage hors tolérance) ou `InvalidWebhookPayloadError`
(corps signé qui n'est pas une enveloppe) : répondez `400`. `Webhooks.verifySignature(body, header, secret)` renvoie un
booléen ; `Webhooks.sign(body, secret)` construit une valeur `X-Signature` valide pour tester votre point de
terminaison. `Webhooks.SIGNATURE_HEADER` vaut `'X-Signature'`.

## Erreurs

Toutes les erreurs héritent de `LabyrintheError` et ont `code` (stable : c'est sur lui qu'il faut brancher),
`message`, `status` (statut HTTP, ou `null` sans réponse) et `details` (toujours un objet). Les erreurs de l'API
héritent de `ApiError` et exposent aussi `headers`, `requestId` et `apiVersion`. Les messages de l'API sont en
français, pour les humains ; la liste des codes est ouverte, gardez donc toujours une branche générique.

| Classe | HTTP | `code` | Que faire |
|---|---|---|---|
| `AuthenticationError` | 401 | `UNAUTHENTICATED` | Clé absente, invalide ou révoquée : vérifiez `LABYRINTHE_API_KEY`. |
| `PermissionError` | 403 | `FORBIDDEN`, `OTP_DISABLED_FOR_ACCOUNT` | IP non autorisée pour cette clé ; OTP désactivé pour le compte. |
| `AccountSuspendedError` | 403 | `ACCOUNT_SUSPENDED` | Toute l'API est fermée : contactez le support avec `requestId`. Hérite de `PermissionError`. |
| `NotFoundError` | 404 | `NOT_FOUND` | Id de message ou `otpId` inconnu (ou d'un autre compte). |
| `ValidationError` | 422 | `VALIDATION_ERROR` | Corrigez les champs de `fieldErrors` (`{ body: ['…'] }`). |
| `RecipientNotSendableError` | 422 | `RECIPIENT_NOT_SENDABLE` | Rien n'est parti : retirez `rejected` (`reason` : `invalid_phone`, `blacklisted`, `opted_out`). |
| `SenderIdNotApprovedError` | 422 | `SENDER_ID_NOT_APPROVED` | Utilisez un nom de `senderIds.list()`. |
| `InvalidPhoneError` | 422 | `INVALID_PHONE` | Numéro OTP inutilisable : demandez-en un autre. |
| `ApiError` | 422 | `INVALID_OTP_TEMPLATE` | Votre `template` est invalide (doit contenir `{code}`). |
| `InsufficientCreditsError` | 409 | `INSUFFICIENT_CREDITS` | Rien n'est parti : achetez des crédits (`available`, `required`). Jamais en mode test. |
| `IdempotencyKeyReusedError` | 409 | `IDEMPOTENCY_KEY_REUSED` | Même clé, autre contenu : nouvelle clé pour un nouveau message. |
| `IdempotencyRequestInProgressError` | 409 | `IDEMPOTENCY_REQUEST_IN_PROGRESS` | Déjà relancée par le SDK ; attendez `retryAfterMs` et rappelez avec la **même** clé. |
| `IdempotencyKeyUnresolvedError` | 409 | `IDEMPOTENCY_KEY_UNRESOLVED` | Issue inconnue, un SMS a pu partir : vérifiez, puis utilisez une **nouvelle** clé ([ci-dessous](#relances-et-idempotence)). |
| `RateLimitError` | 429 | `TOO_MANY_REQUESTS` | Déjà relancée quand c'était sans risque ; attendez `retryAfterMs`. |
| `OtpResendTooSoonError` | 429 | `OTP_RESEND_TOO_SOON` | Affichez un compte à rebours de `retryAfterMs`. Hérite de `RateLimitError`. |
| `OtpRateLimitedError` | 429 | `OTP_RATE_LIMITED` | Plafond horaire (`scope`, `limit`, `retryAfterMs`). Hérite de `RateLimitError`. |
| `FeatureDisabledError` | 503 | `FEATURE_DISABLED`, `OTP_SERVICE_DISABLED` | Maintenance : rien n'a été fait ([ci-dessous](#maintenance-503-feature_disabled)). Hérite de `ServiceUnavailableError`. |
| `ServiceUnavailableError` | 5xx | tout code | Déjà relancée quand c'était sans risque ; réessayez plus tard. |
| `ApiError` | autre | `HTTP_ERROR` (405), codes inconnus | Journalisez `code`, `status`, `requestId`. |
| `ConnectionError` | — | `CONNECTION_ERROR` | API injoignable (DNS, TLS…). Les envois ont déjà été relancés ; rappeler avec la même `idempotencyKey` est sans risque. |
| `TimeoutError` | — | `TIMEOUT` | Une tentative a dépassé `timeoutMs`. Hérite de `ConnectionError`. |
| `RequestAbortedError` | — | `REQUEST_ABORTED` | Annulé par `signal`. Jamais relancé. |
| `InvalidArgumentError` | — | `INVALID_ARGUMENT` | Saisie invalide détectée avant tout appel (numéro, longueur, option). |
| `UnexpectedResponseError` | 2xx | `UNEXPECTED_RESPONSE` | Réponse illisible pour le SDK (page d'un proxy…). |
| `SignatureVerificationError` | — | `SIGNATURE_VERIFICATION_FAILED` | Signature ou horodatage de webhook invalide : répondez 400. |
| `InvalidWebhookPayloadError` | — | `INVALID_WEBHOOK_PAYLOAD` | Corps de webhook signé qui n'est pas une enveloppe : répondez 400. |

```ts
import { ApiError, InsufficientCreditsError, LabyrintheError, ValidationError } from '@labyrinthe/connect';

try {
  await client.sms.send({ to: '0812345678', body: 'Bonjour' });
} catch (error) {
  if (error instanceof InsufficientCreditsError) {
    await alertFinance(error.available, error.required);
  } else if (error instanceof ValidationError) {
    console.error(error.fieldErrors);
  } else if (error instanceof ApiError) {
    console.error(error.code, error.status, error.requestId);   // requestId pour le support
  } else if (error instanceof LabyrintheError) {
    console.error(error.code);                                  // CONNECTION_ERROR, TIMEOUT, INVALID_ARGUMENT…
  } else {
    throw error;
  }
}
```

### Maintenance (503 `FEATURE_DISABLED`)

Un administrateur de la plateforme peut couper temporairement toute l'API (`blockedBy === 'api'` : tous les appels,
refusés avant même la vérification de la clé) ou une fonction (`'api.sms_send'`, `'api.otp_send'`,
`'api.otp_verify'`). L'appel lève `FeatureDisabledError` et **rien n'a été fait** : aucun SMS, aucun crédit consommé.
Le service OTP entier coupé lève la même classe avec le code historique `OTP_SERVICE_DISABLED`
(`feature === blockedBy === 'otp'` ; la vérification des codes déjà envoyés reste possible).

| Propriété | Type | Description |
|---|---|---|
| `feature` | `string \| null` | Fonction demandée par l'appel (`api`, `api.sms_send`, `api.otp_send`, `api.otp_verify`, `otp`… liste ouverte). |
| `blockedBy` | `string \| null` | Fonction réellement coupée : `feature` elle-même ou une parente (`api`). |
| `adminMessage` | `string \| null` | Message de l'administrateur (`details.message`) ; `error.message` le reprend ou reste générique. |
| `until` | `Date \| null` | Réactivation automatique programmée ; `null` si inconnue. |
| `retryAfter` / `retryAfterMs` | `number \| null` | Temps restant jusque-là (en-tête `Retry-After`), en secondes / millisecondes. |

Le SDK ne la relance jamais par backoff : seulement si `Retry-After` est présent et ne dépasse pas `maxRetryWaitMs`,
après exactement ce délai (pour tous les appels, `otp.verify()` compris, puisque la requête a été refusée avant
traitement). Sinon, traitez-la comme un échec temporaire :

```ts
import { FeatureDisabledError } from '@labyrinthe/connect';

try {
  await client.sms.send({ to, body, idempotencyKey: `order-${orderId}-ready` });
} catch (error) {
  if (error instanceof FeatureDisabledError) {
    // Même clé d'idempotence plus tard : rien ne peut partir deux fois.
    await queue.retryLater({ orderId }, { at: error.until ?? new Date(Date.now() + 15 * 60_000) });
    logger.warn('Maintenance Labyrinthe', {
      feature: error.feature, blockedBy: error.blockedBy, message: error.adminMessage, requestId: error.requestId,
    });
  } else {
    throw error;
  }
}
```

## Relances et idempotence

Le SDK relance automatiquement (2 relances par défaut, backoff exponentiel 0,5 s → 1 s → 2 s… avec jitter, plafonné
par `maxRetryWaitMs`) **uniquement quand un rejeu ne peut pas produire deux fois un effet** :

| Appel | Erreur réseau / délai dépassé | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` |
|---|---|---|---|---|
| `sms.get`, `sms.list`, `sms.estimate`, `balance.get`, `senderIds.list` | relancé | relancé | après `Retry-After` | — |
| `sms.send`, `sms.sendMany`, `otp.send` (toujours avec une `Idempotency-Key`) | relancé, même clé | relancé, même clé | après `Retry-After` | après `Retry-After`, même clé |
| `otp.verify` | **jamais** (un essai a pu être consommé) | **jamais** | après `Retry-After` | — |

- `503 FEATURE_DISABLED` / `OTP_SERVICE_DISABLED` : pour tous les appels, seulement après un `Retry-After` ≤
  `maxRetryWaitMs`, jamais par backoff (voir [Maintenance](#maintenance-503-feature_disabled)).
- Jamais relancés : les autres 4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, `ACCOUNT_SUSPENDED`,
  `IDEMPOTENCY_KEY_UNRESOLVED`. Quand l'API demande d'attendre plus que `maxRetryWaitMs`, l'erreur est levée tout de
  suite.

**Idempotence.** Chaque envoi de SMS ou d'OTP porte une `Idempotency-Key`. Sans `idempotencyKey`, le SDK génère un
UUID (`crypto.randomUUID()`) une fois par appel et le réutilise pour ses propres relances : une erreur réseau ne
produit jamais de second SMS. Pour protéger aussi **vos** relances (jobs de file, double clic, plantage), passez une
clé dérivée de votre événement métier. Un rejeu dans les 24 heures renvoie la réponse d'origine sans nouvel envoi ni
débit (`replayed: true`) :

```ts
const batch = await client.sms.sendMany({
  recipients: ['0812345678'], body: 'Votre commande 1042 est prête.', idempotencyKey: 'order-1042-ready',
});
batch.replayed;   // true s'il s'agit d'un rejeu

const otp = await client.otp.send({ phone: '0812345678', idempotencyKey: `login-${loginAttemptId}` });
otp.replayed;     // true : même otpId (et même testCode en mode test), aucun nouveau SMS
```

Les clés sont propres à votre clé API et à l'opération (`/sms` et `/otp/send` ont des espaces de clés séparés), font
255 caractères au maximum et sont liées au contenu **normalisé** : la même clé avec un autre contenu lève
`IdempotencyKeyReusedError`. Une requête terminée en erreur n'enregistre pas la clé.

**Issue inconnue.** `IdempotencyKeyUnresolvedError` (409 `IDEMPOTENCY_KEY_UNRESOLVED`) signifie que la requête
d'origine avec cette clé a échoué de façon inattendue côté API : un SMS a peut-être déjà été envoyé. Le SDK ne la
relance jamais ; ne la relancez pas automatiquement non plus. Vérifiez d'abord, puis renvoyez avec une **nouvelle**
clé (la même clé renverra toujours cette erreur) :

```ts
import { IdempotencyKeyUnresolvedError } from '@labyrinthe/connect';

try {
  await client.sms.send({ to, body, reference, idempotencyKey });
} catch (error) {
  if (error instanceof IdempotencyKeyUnresolvedError) {
    const { data } = await client.sms.list({ reference });
    if (data.length === 0) await client.sms.send({ to, body, reference, idempotencyKey: crypto.randomUUID() });
  } else {
    throw error;
  }
}
```

Pour un OTP, demandez à l'utilisateur si le code est arrivé avant d'en envoyer un nouveau.

**Limites de débit.** 120 requêtes par minute par clé API, 10 par minute par clé sur `otp/send`, 120 par minute par
adresse IP. `onResponse` expose `rateLimit: { limit, remaining }` (en-têtes `X-RateLimit-*`).

## Mode test, journalisation, identifiants de requête et types

### Mode test

Avec une clé `lsk_test_…`, le fournisseur est simulé : rien n'est envoyé, aucun crédit n'est consommé, statuts et
réponses ont la forme de la production. Deux différences : le solde n'est pas vérifié (`INSUFFICIENT_CREDITS` n'arrive
jamais en mode test) et `otp.send()` renvoie le code dans `testCode`, pour tester tout le parcours OTP.
`client.isTestMode` indique le mode utilisé. Utilisez des clés de test en développement, en CI et dans les exemples.

### Journalisation et secrets

Passez `logger: console` (ou pino, winston…) pour recevoir des entrées `debug` (chaque requête et réponse : méthode,
chemin, statut, durée, `replayed`, `requestId`), `warn` (relances) et `info` / `error` (erreurs de l'API et du
réseau). Les logs ne contiennent jamais la clé API, les codes OTP, les corps de requête ou de réponse, ni les numéros
complets (les paramètres `recipient` / `phone` sont masqués). Les messages d'erreur réseau ne citent jamais la query
de l'URL. `console.log()` / `JSON.stringify()` du client, de ses ressources et de `OtpSendResult` masquent la clé et
`testCode`.

### Identifiants de requête

Chaque réponse de l'API porte `X-Request-Id` : `error.requestId` sur les erreurs, `requestId` sur les lots de
`sendMany()` et les résultats de `otp.send()`, et `onResponse` pour tout appel. Communiquez-le au support. Vous pouvez
envoyer le vôtre (réutilisé à chaque relance de l'appel) :

```ts
await client.sms.send({ to: '0812345678', body: 'Bonjour' }, {
  requestId: 'order-1042',
  onResponse: ({ requestId, apiVersion, rateLimit, replayed }) => logger.debug({ requestId, apiVersion, rateLimit, replayed }),
});
```

Le `User-Agent` est `labyrinthe-node/<version> <runtime>/<version>` ; `VERSION` est exportée.

### Types TypeScript

Tout est exporté depuis `@labyrinthe/connect` :

- Objets : `Message`, `MessageBatch`, `Estimate`, `Balance`, `SenderId`, `RejectedRecipient`, `OtpSendResult`,
  `OtpVerifyResult` (discriminé sur `verified`), `Page`, `PagePromise`, `ResponseInfo`, `RequestOptions`,
  `LabyrintheOptions`.
- Paramètres : `SendSmsParams`, `SendManySmsParams`, `EstimateSmsParams`, `ListSmsParams`, `SendOtpParams`,
  `VerifyOtpParams`, `OtpLocale`.
- Enums (objet constant + type union du même nom) : `MessageStatus`, `Encoding`, `SenderIdStatus`, `RejectionReason`,
  `OtpFailureReason`, `OtpCodeType`, `OtpVerificationStatus`, `OtpRateLimitScope`, `WebhookEventType`. Tous sauf
  `Encoding` et `WebhookEventType` ont un membre `unknown` pour les valeurs ajoutées plus tard à l'API.
- Webhooks : `WebhookEvent` (union), `MessageStatusChangedEvent`, `CampaignCompletedEvent`, `BalanceLowEvent`,
  `OtpVerifiedEvent`, `OtpFailedEvent`, `OtpWebhookData`, `TestPingEvent`, `UnknownEvent`, `RawBody`,
  `ConstructEventOptions`.
- Erreurs : toutes les classes ci-dessus, `ApiErrorCode`, `SdkErrorCode`, `ErrorCode`, `ErrorDetails`.
- Transport : `FetchLike`, `FetchInit`, `FetchResponse`, `Logger`.

```ts
import { MessageStatus, type Message } from '@labyrinthe/connect';

const failed = (m: Message) => m.status === MessageStatus.Failed;
```

## Sans le package (HTTP brut)

L'API est du JSON sur HTTPS ; le SDK n'ajoute que la validation, les relances, le typage et les helpers de webhook.
Avec `fetch` (Node 18+) :

```ts
const API = 'https://api.connect.labyrinthe-rdc.com/api/public/v1';
const headers = {
  Authorization: `Bearer ${process.env.LABYRINTHE_API_KEY}`,   // lsk_test_… pendant les tests
  'Content-Type': 'application/json',
  Accept: 'application/json',
};

// Envoyer un SMS (202). Idempotency-Key : rejouez sans risque avec la MÊME clé pendant 24 h.
const res = await fetch(`${API}/sms`, {
  method: 'POST',
  headers: { ...headers, 'Idempotency-Key': crypto.randomUUID() },
  body: JSON.stringify({ recipient: '+243812345678', body: 'Votre commande 1042 est prête.', reference: 'order-1042' }),
});
const json = await res.json();
if (!res.ok) {
  // { error: { code, message, details } }
  throw new Error(`${json.error.code} (HTTP ${res.status}, requête ${res.headers.get('x-request-id')})`);
}
const [message] = json.data;   // [{ id, recipient, status: "queued", segments, credits_charged, … }]

// OTP : envoi (201), puis vérification (200)
const sent = await (await fetch(`${API}/otp/send`, {
  method: 'POST', headers: { ...headers, 'Idempotency-Key': crypto.randomUUID() },
  body: JSON.stringify({ phone: '+243812345678', locale: 'fr' }),
})).json();                    // { data: { otp_id, expires_at, max_attempts, code_length, code_type, resend_after_seconds, test_code? } }

const checked = await (await fetch(`${API}/otp/verify`, {
  method: 'POST', headers, body: JSON.stringify({ otp_id: sent.data.otp_id, code: '123456' }),
})).json();                    // { data: { verified, reason, attempts_remaining } }
```

Signature d'un webhook avec `node:crypto`, sur le corps brut :

```ts
import { createHmac, timingSafeEqual } from 'node:crypto';

function verifyWebhook(rawBody: Buffer, signatureHeader: string | undefined, secret: string): boolean {
  const expected = Buffer.from('sha256=' + createHmac('sha256', secret).update(rawBody).digest('hex'));
  const given = Buffer.from(signatureHeader ?? '');
  if (given.length !== expected.length || !timingSafeEqual(given, expected)) return false;
  const { timestamp } = JSON.parse(rawBody.toString('utf8'));
  return Math.abs(Date.now() / 1000 - timestamp) <= 300;   // protection contre le rejeu
}
```

Référence : la spécification OpenAPI 3.1
[`openapi/openapi.yaml`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/openapi/openapi.yaml)
(aussi servie par l'API sur `https://api.connect.labyrinthe-rdc.com/api/public/openapi.yaml`) et la
[collection Postman](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/tree/main/postman).

## Mise à jour

- **1.2.0 → 1.2.1** (API 1.3.1) : aucun changement de code. L'URL par défaut de l'API est désormais
  `https://api.connect.labyrinthe-rdc.com/api/public/v1` (celle de la 1.2.0 ne répondait pas). Mettez à jour, ou indiquez cette URL
  explicitement si vous restez en 1.2.0.
- **1.1.x → 1.2.0** (API 1.3.0) : aucun changement de code. Nouvelle `FeatureDisabledError` pour 503
  `FEATURE_DISABLED` ; 503 `OTP_SERVICE_DISABLED` est désormais levée elle aussi en `FeatureDisabledError`. Les deux
  restent `instanceof ServiceUnavailableError` : vos branches `catch` existantes fonctionnent toujours. Elles ne sont
  plus relancées par backoff, seulement après un `Retry-After` court.
- **1.1.0 → 1.1.1** : les numéros partent vers l'API en E.164 avec `+` (les numéros étrangers courts gardent leur
  indicatif). Ajout de `IdempotencyKeyUnresolvedError`. Aucun changement de code.
- **1.0.x → 1.1.0** (API 1.2.0) : `OtpVerifyResult` gagne `attemptsRemaining` (adaptez les comparaisons profondes du
  résultat entier) ; `otp.send()` est idempotent et relancé sans risque ; les numéros sans `+` de 11 chiffres ou plus
  ne commençant pas par 0 restent internationaux (`33612345678`).

Détails : [CHANGELOG](CHANGELOG.md). Dans la v1, l'API n'apporte que des ajouts compatibles (champs, codes,
événements) : le SDK ignore ce qu'il ne connaît pas.

## Contribuer

Tickets et pull requests vont dans le monorepo
[Labyrinthe-Team/labyrinthe-connect-sdks](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks)
(voir [CONTRIBUTING.md](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/CONTRIBUTING.md)). Dans
`node/` :

```bash
npm ci
npm run check            # lint + types + tests (fetch simulé, contrat OpenAPI) + build + check:dist + publint + attw

# tests d'intégration contre un backend local, avec une clé de TEST uniquement
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 npm run test:integration
```

Failles de sécurité : écrivez à connect@labyrinthe-rdc.com plutôt que d'ouvrir un ticket public.

## Licence

MIT — voir [LICENSE](LICENSE).

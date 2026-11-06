# Labyrinthe Connect — SDK Node.js / TypeScript

[![CI](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/node.yml/badge.svg)](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/node.yml)
[![npm](https://img.shields.io/npm/v/@labyrinthe/connect.svg)](https://www.npmjs.com/package/@labyrinthe/connect)
![Node](https://img.shields.io/badge/node-%E2%89%A5%2018-339933)

Client JavaScript / TypeScript officiel de [Labyrinthe Connect](https://labyrinthe-rdc.com) : envoyez des SMS et
vérifiez des numéros de téléphone par code à usage unique (OTP), en RD Congo et en Afrique centrale.

*English version: [README.md](README.md).*

- Entièrement typé : objets en camelCase, `Date`, énumérations en littéraux, une classe d'erreur par erreur.
- Sûr par défaut : relances automatiques **uniquement** quand elles ne peuvent pas envoyer deux fois un SMS.
- Aucune dépendance d'exécution. ESM + CommonJS. Node.js 18+, Vercel / Cloudflare Workers, Deno, Bun.
- N'écrit jamais votre clé API, les codes OTP ni les numéros complets dans les logs.

> **Nouveautés 1.2.0** (spécification de l'API 1.3.0) : `FeatureDisabledError` pour le 503 `FEATURE_DISABLED` (API
> ou fonction coupée temporairement par la plateforme, voir [Maintenance](#maintenance-503-feature_disabled)),
> utilisée aussi pour `OTP_SERVICE_DISABLED` ; relancée seulement après un `Retry-After` court.
>
> **Nouveautés 1.1.0** (spécification de l'API 1.2.0) : `otp.send()` idempotent (relancé sans risque, `replayed`),
> forme du code (`codeLength`, `codeType`) et délai de renvoi (`resendAfterSeconds`), `attemptsRemaining` sur
> `otp.verify()`, Web OTP (`webOtpDomain`), `stripAccents`, `requestId` sur toutes les erreurs et réponses (et votre
> propre `X-Request-Id`), `AccountSuspendedError`, `IdempotencyRequestInProgressError` (relancée automatiquement),
> webhooks `otp.verified` / `otp.failed`, numéros étrangers sans « + » conservés. Voir le [CHANGELOG](CHANGELOG.md).

## Installation

```bash
npm install @labyrinthe/connect
```

## Démarrage

```ts
import { Labyrinthe } from '@labyrinthe/connect';

const client = new Labyrinthe({ apiKey: 'lsk_test_…' });       // ou la variable LABYRINTHE_API_KEY
const message = await client.sms.send({ to: '0812345678', body: 'Votre commande 1042 est prête.' });
console.log(message.status);                                     // "queued"
```

Créez vos clés dans l'espace client (*Développeurs > Clés API*). **Gardez-les côté serveur**, jamais dans un
navigateur ni une application mobile (le client refuse de démarrer dans un navigateur).

| Clé | Mode | Effet |
|---|---|---|
| `lsk_test_…` | Bac à sable | Rien n'est envoyé, aucun crédit consommé. Réponses identiques à la production. L'envoi d'OTP renvoie le code (`testCode`). |
| `lsk_live_…` | Production | Les SMS partent réellement et consomment des crédits. |

`client.isTestMode` indique le mode utilisé. Tous les exemples ci-dessous fonctionnent avec une clé de test.

## Envoyer des SMS

```ts
const message = await client.sms.send({
  to: '+243 81 234 5678',      // +243…, 00243…, 243…, 0812…, 812… sont acceptés
  body: 'Votre commande 1042 est prête.',
  sender: 'MABOUTIQUE',        // facultatif : un Sender ID approuvé (client.senderIds.list())
  reference: 'commande-1042',  // facultatif : votre référence, 64 caractères au plus
});

message.id;              // "9d5c0a3e-…"
message.recipient;       // "243812345678"
message.status;          // "queued"
message.creditsCharged;  // 1
message.createdAt;       // Date
```

L'envoi est asynchrone : `202 Accepted` signifie *mis en file et payé*, pas *remis*. Suivez le statut par webhook
(`message.status_changed`, recommandé) ou en interrogeant l'API :

```ts
import { isFinalStatus } from '@labyrinthe/connect';

const latest = await client.sms.get(message.id);
if (isFinalStatus(latest.status)) {
  console.log(latest.status === 'delivered' ? 'Remis' : `Échec : ${latest.errorCode}`);
}
```

### Coût avant envoi

1 crédit = 1 segment SMS. Un message en ASCII pur (GSM-7) tient en 160 caractères (153 par segment au-delà).
**Tout caractère non ASCII** (é, à, ç, emoji, guillemets typographiques…) passe en UCS-2 : 70 caractères (67 par
segment au-delà), soit un coût souvent doublé.

```ts
const estimate = await client.sms.estimate({ body: 'Réunion demain à 9h', recipients: ['0812345678', '0899999999'] });
estimate.encoding;      // "ucs2"
estimate.totalCredits;  // 2
estimate.isAffordable;  // le solde suffit-il ?

// stripAccents : accents et caractères typographiques remplacés par de l'ASCII → GSM-7, souvent deux fois moins cher
(await client.sms.estimate({ body: 'Réunion demain à 9h', recipients: '0812345678', stripAccents: true })).encoding; // "gsm7"
await client.sms.send({ to: '0812345678', body: 'Réunion demain à 9h', stripAccents: true });
```

`stripAccents` (`false` par défaut) existe sur `send()`, `sendMany()`, `estimate()` et `otp.send()`. La
translittération dépend du serveur (`Café` peut devenir `Cafe` ou `Caf'e`) : ne pas s'en servir pour un texte
exact. L'estimation compte les destinataires comme l'envoi (normalisés, doublons fusionnés, invalides ignorés).

### Plusieurs destinataires : tout ou rien

```ts
import { RecipientNotSendableError } from '@labyrinthe/connect';

try {
  const batch = await client.sms.sendMany({ recipients: ['0812345678', '0899999999'], body: 'Réunion demain à 9h.' });
  batch.messages.length;   // un message par numéro distinct (les doublons sont fusionnés)
} catch (error) {
  if (error instanceof RecipientNotSendableError) {
    // RIEN n'a été envoyé : corrigez la liste et recommencez.
    for (const { recipient, reason } of error.rejected) console.log(recipient, reason); // invalid_phone, blacklisted, opted_out
  }
}
```

1000 destinataires au plus par appel. Tous les destinataires, le Sender ID et le solde sont vérifiés **avant** tout
envoi : un seul numéro refusé (ou un solde insuffisant) et aucun SMS ne part. Les numéros que le SDK sait déjà
invalides sont refusés localement (`InvalidArgumentError`, avec leur position), avant tout appel réseau.

### Idempotence (jamais de double envoi)

Chaque envoi porte un `Idempotency-Key`. Si vous n'en donnez pas, le SDK génère un UUID (`crypto.randomUUID()`) et
le réutilise pour ses propres relances : une erreur réseau ne produit jamais un second SMS.

Pour vous protéger aussi de **vos** relances (jobs, double clic, plantage), passez une clé dérivée de votre
événement métier. Un rejeu dans les 24 h renvoie les messages d'origine sans rien renvoyer ni facturer :

```ts
const batch = await client.sms.sendMany({ recipients: ['0812345678'], body: 'Votre commande 1042 est prête.', idempotencyKey: 'commande-1042-prete' });
batch.replayed;   // true s'il s'agit d'un rejeu
```

La même clé avec un autre contenu (destinataires, texte, expéditeur ou référence) est refusée :
`IdempotencyKeyReusedError` (409).

### Lister les messages

```ts
const page = await client.sms.list({ status: 'failed', from: '2026-11-01', to: new Date(), perPage: 50 });
page.total; page.data; page.hasMore; await page.nextPage();

for await (const message of client.sms.list({ reference: 'commande-1042' })) {
  // toutes les pages, chargées à la demande
}
```

Filtres : `status`, `recipient` (tout format), `reference`, `from`, `to` (une date couvre toute la journée), `page`,
`perPage` (100 au plus). La liste ne contient que les messages envoyés **avec la clé API appelante**.

### Solde et Sender IDs

```ts
const { balance, held } = await client.balance.get();
const senderIds = await client.senderIds.list();   // noms approuvés, utilisables comme `sender`
```

## OTP : vérifier un numéro

Le code est généré, envoyé par SMS et vérifié par Labyrinthe Connect. L'API ne le renvoie jamais (sauf avec une
clé de test) et le SDK ne le journalise jamais.

```ts
// 1. Envoyer le code. Gardez otpId CÔTÉ SERVEUR (session, cache, base), jamais dans le navigateur.
const otp = await client.otp.send({ phone: '0812345678' });
req.session.otpId = otp.otpId;
otp.codeLength;          // 6 — nombre de cases à afficher
otp.codeType;            // "numeric" | "alphanumeric" (insensible à la casse)
otp.resendAfterSeconds;  // 60 — compte à rebours « Renvoyer le code »

// 2. L'utilisateur saisit le code reçu par SMS.

// 3. Le vérifier.
const result = await client.otp.verify({ otpId: req.session.otpId, code: req.body.code });
if (result.verified) {
  delete req.session.otpId;
  // connecter l'utilisateur / marquer le numéro comme vérifié
} else if (result.reason === 'invalid_code') {
  // code faux : une tentative consommée, l'utilisateur peut réessayer
  console.log(`${String(result.attemptsRemaining)} tentative(s) restante(s)`);
} else {
  // 'expired' | 'already_used' | 'max_attempts_reached' : demander un nouveau code
}
```

- Un code faux n'est **pas** une erreur : `verify()` renvoie `{ verified: false, reason, attemptsRemaining }`
  (`attemptsRemaining` : entier après `invalid_code`, `0` avec `max_attempts_reached`, sinon `null`).
- Un nouvel `send()` au même numéro invalide le code précédent. Après une vérification réussie, un nouvel envoi est
  accepté tout de suite.
- Longueur, alphabet, validité, tentatives, délai de renvoi, Sender ID et modèle viennent des paramètres OTP du
  compte (espace client > *Vérification OTP*) et sont renvoyés par `send()`. Vous pouvez imposer le modèle
  (`template: 'Votre code MaBoutique : {code}'`, avec `{code}` obligatoire ; `{app_name}`, `{minutes}` facultatifs),
  la langue (`locale: 'fr' | 'en'`) et retirer les accents (`stripAccents: true`).
- Limites : `OtpResendTooSoonError` (attendre `retryAfterMs` avant de renvoyer à ce numéro) et
  `OtpRateLimitedError` (plafond horaire par numéro ou par compte, `scope`, `limit`, `retryAfterMs`). Jamais relancées.
- **Mode test** : avec une clé `lsk_test_`, aucun SMS ne part et `otp.testCode` contient le code, pour tester tout
  le parcours. Il vaut toujours `null` avec une clé live, et il est masqué dans `console.log()` et
  `JSON.stringify()`.

### Envoi OTP idempotent

`otp.send()` porte toujours une `Idempotency-Key` (générée si vous n'en donnez pas) : le SDK le relance donc sans
risque après une erreur réseau, un délai dépassé, une 5xx, une 429 `TOO_MANY_REQUESTS` ou une 409
`IDEMPOTENCY_REQUEST_IN_PROGRESS`. Un rejeu renvoie le même `otpId` (et le même `testCode` en mode test), sans
second SMS ni débit. Passez votre propre clé pour couvrir aussi vos relances (double clic, job relancé) pendant 24 h :

```ts
const otp = await client.otp.send({ phone: '0812345678', idempotencyKey: `login-${loginAttemptId}` });
otp.replayed;   // true : rien n'a été renvoyé ni facturé
```

### Web OTP (remplissage automatique sur Android)

Avec `webOtpDomain`, l'API termine le SMS par la ligne `@app.exemple.com #123456`, le format de la
[Web OTP API](https://developer.mozilla.org/docs/Web/API/WebOTP_API) : Chrome sur Android propose alors de remplir
le code dans votre page servie depuis **exactement cet hôte**, en HTTPS (page de premier niveau).

```ts
await client.otp.send({ phone: '0812345678', webOtpDomain: 'app.exemple.com' }); // hôte seul : ni schéma, ni port, ni chemin
```

```js
// Dans le navigateur (le widget @labyrinthe/otp-widget le fait pour vous)
const { code } = await navigator.credentials.get({ otp: { transport: ['sms'] } });
```

## Webhooks

Configurez un endpoint dans l'espace client (*Développeurs > Webhooks*) et copiez son secret. Les événements sont
envoyés en `POST` JSON et signés (`X-Signature: sha256=<HMAC-SHA256 du corps brut>`). Le SDK vérifie la signature
en temps constant sur le **corps brut**, puis l'horodatage signé (5 minutes par défaut) contre le rejeu.

Chaque événement a `id` (dédoublonnez dessus : jusqu'à 5 tentatives, ordre non garanti), `type`, `timestamp`
(`Date`), `raw` et un `data` typé, discriminé par `event` :

| `event` | `data` |
|---|---|
| `message.status_changed` | `messageId`, `recipient`, `status`, `providerMessageId`, `campaignId`, `reference`, `errorCode`, `errorMessage` |
| `campaign.completed` | `campaignId`, `name`, `recipientsCount`, `messagesByStatus`, `creditsCharged`, `completedAt` |
| `balance.low` | `balance`, `threshold` |
| `otp.verified` | `otpId`, `phone`, `status` (`verified`), `reason` (`null`), `verifiedAt` — émis une fois, jamais le code |
| `otp.failed` | mêmes champs : `status` `failed` (`reason` `max_attempts_reached`) ou `expired` (`reason` `expired`), `verifiedAt` `null` |
| `test.ping` | `{}` |
| `unknown` | un type ajouté après cette version du SDK : son nom est dans `type`, son contenu dans `data` (tel quel). Répondez 2xx. |

### Express

```ts
import express from 'express';
import { labyrintheWebhook } from '@labyrinthe/connect/express';

const app = express();
// AVANT express.json() (ou après express.raw({ type: 'application/json' })) : il faut le corps brut.
app.post('/webhooks/labyrinthe', labyrintheWebhook({
  secret: process.env.LABYRINTHE_WEBHOOK_SECRET,
  onEvent: async (event) => {
    if (event.event === 'message.status_changed') await updateMessage(event.data.messageId, event.data.status);
  },
}));
app.use(express.json());
```

Signature ou contenu invalide → `400`. `onEvent` lève une erreur → `next(error)` (500, la livraison sera
retentée). Sans `onEvent`, l'événement vérifié est placé dans `req.labyrintheEvent` puis `next()` est appelé.

### Next.js (App Router)

```ts
// app/api/webhooks/labyrinthe/route.ts
import { labyrintheWebhookHandler } from '@labyrinthe/connect/next';

export const POST = labyrintheWebhookHandler({
  secret: process.env.LABYRINTHE_WEBHOOK_SECRET,   // valeur par défaut
  onEvent: async (event) => {
    switch (event.event) {
      case 'balance.low':
        await alertFinance(event.data.balance, event.data.threshold);
        break;
    }
  },
});
```

Fonctionne avec les runtimes Node.js et Edge, et partout où un handler reçoit une `Request` Fetch et renvoie une
`Response` (Hono, `Bun.serve`, `Deno.serve`…). `onEvent` peut renvoyer sa propre `Response` ; s'il lève une erreur,
le handler répond 500.

### Cloudflare Workers (ou tout autre runtime)

```ts
import { SignatureVerificationError, Webhooks } from '@labyrinthe/connect';

export default {
  async fetch(request: Request, env: { LABYRINTHE_WEBHOOK_SECRET: string }) {
    try {
      const event = await Webhooks.constructEvent(
        await request.arrayBuffer(), request.headers.get('x-signature'), env.LABYRINTHE_WEBHOOK_SECRET,
      );
      // … traiter l'événement
      return new Response('ok');
    } catch (error) {
      if (error instanceof SignatureVerificationError) return new Response('invalid', { status: 400 });
      throw error;
    }
  },
};
```

`Webhooks.constructEvent(rawBody, signatureHeader, secret, { toleranceSeconds })` est asynchrone (Web Crypto) et
accepte une chaîne, un `Uint8Array`/`Buffer` ou un `ArrayBuffer` — jamais un objet déjà parsé. Elle lève
`SignatureVerificationError` (signature absente ou fausse, horodatage hors tolérance) ou
`InvalidWebhookPayloadError` (corps signé qui n'est pas une enveloppe) : répondez `400`.
`Webhooks.sign(body, secret)` produit un en-tête valide pour tester votre endpoint. Répondez `2xx` en moins de
10 secondes ; mettez les traitements longs en file.

## Erreurs

Toutes les erreurs héritent de `LabyrintheError` et ont `code` (stable, pour votre code), `message`, `status`
(statut HTTP, ou `null` sans réponse) et `details`. Les erreurs de l'API héritent d'`ApiError` et exposent aussi
`headers` et `requestId`.

| Erreur | Quand |
|---|---|
| `AuthenticationError` | 401 — clé absente, invalide ou révoquée |
| `PermissionError` | 403 — IP non autorisée (`FORBIDDEN`), OTP désactivé pour le compte (`OTP_DISABLED_FOR_ACCOUNT`) |
| `AccountSuspendedError` | 403 `ACCOUNT_SUSPENDED` — hérite de `PermissionError` ; toute l'API est fermée, contacter le support |
| `NotFoundError` | 404 — message ou `otpId` inconnu |
| `ValidationError` | 422 `VALIDATION_ERROR` — `fieldErrors` |
| `RecipientNotSendableError` | 422 — `rejected`, `reason` ; rien n'a été envoyé |
| `SenderIdNotApprovedError` | 422 — `sender` n'est pas un Sender ID approuvé |
| `InvalidPhoneError` | 422 `INVALID_PHONE` (OTP) |
| `InsufficientCreditsError` | 409 — `available`, `required` ; rien n'a été envoyé |
| `IdempotencyKeyReusedError` | 409 — même clé, autre requête |
| `IdempotencyRequestInProgressError` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` — déjà relancée ; rappeler avec la même clé après `retryAfterMs` |
| `IdempotencyKeyUnresolvedError` | 409 `IDEMPOTENCY_KEY_UNRESOLVED` — résultat inconnu, un SMS a pu partir ; **jamais** relancée (voir plus bas) |
| `RateLimitError` | 429 `TOO_MANY_REQUESTS` — `retryAfterMs` |
| `OtpResendTooSoonError`, `OtpRateLimitedError` | 429 — héritent de `RateLimitError` ; `retryAfterMs`, `scope`, `limit` |
| `FeatureDisabledError` | 503 `FEATURE_DISABLED` / `OTP_SERVICE_DISABLED` — hérite de `ServiceUnavailableError` ; `feature`, `blockedBy`, `adminMessage`, `until`, `retryAfter` (voir [Maintenance](#maintenance-503-feature_disabled)) |
| `ServiceUnavailableError` | autres 5xx |
| `ApiError` | tout autre code (liste ouverte : de nouveaux codes peuvent apparaître) |
| `ConnectionError`, `TimeoutError` | erreur réseau (`CONNECTION_ERROR`) ou délai dépassé (`TIMEOUT`) |
| `RequestAbortedError` | appel annulé par son `signal` |
| `InvalidArgumentError` | donnée invalide détectée par le SDK, avant tout appel |
| `UnexpectedResponseError` | réponse illisible pour le SDK |

```ts
import { ApiError, InsufficientCreditsError } from '@labyrinthe/connect';

try {
  await client.sms.send({ to: '0812345678', body: 'Bonjour' });
} catch (error) {
  if (error instanceof InsufficientCreditsError) alertFinance(error.available, error.required);
  else if (error instanceof ApiError) console.error(error.code, error.status, error.requestId);
  else throw error;
}
```

`error.details` est toujours un objet (`{}` s'il est vide). Les messages sont en français, pour les humains : testez
`code`.

### Maintenance (503 `FEATURE_DISABLED`)

Un administrateur de la plateforme peut couper temporairement toute l'API (`blockedBy === 'api'`, tous les appels,
avant même la vérification de la clé) ou une fonction (`'api.sms_send'`, `'api.otp_send'`, `'api.otp_verify'`).
L'appel lève `FeatureDisabledError` et **rien n'est fait** (aucun SMS, aucun crédit consommé). Le service OTP entier
coupé lève la même classe avec le code historique `OTP_SERVICE_DISABLED` (`feature === blockedBy === 'otp'`).

- `feature` : fonction demandée ; `blockedBy` : celle qui est réellement coupée (elle-même ou une parente) ;
- `adminMessage` : message de l'administrateur, ou `null` ; `until` : réactivation programmée (`Date`), ou `null` ;
- `retryAfter` : secondes restantes (en-tête `Retry-After`), ou `null` si inconnu.

Le SDK ne la relance jamais avec backoff : seulement si `Retry-After` est présent et ne dépasse pas
`maxRetryWaitMs`, après exactement ce délai. Sinon, traitez-la comme un échec temporaire :

```ts
import { FeatureDisabledError } from '@labyrinthe/connect';

try {
  await client.sms.send({ to, body, idempotencyKey: `commande-${orderId}` });
} catch (error) {
  if (error instanceof FeatureDisabledError) {
    // Même clé d'idempotence plus tard : rien ne peut partir deux fois.
    await queue.retryLater({ orderId }, { at: error.until ?? new Date(Date.now() + 15 * 60_000) });
    log.warn('Maintenance Labyrinthe', { blockedBy: error.blockedBy, message: error.adminMessage });
  } else throw error;
}
```

### Identifiant de requête

Chaque réponse de l'API porte `X-Request-Id` : `error.requestId` sur les erreurs, `requestId` sur les lots de
`sendMany()` et les résultats d'`otp.send()`, et `onResponse` pour tout appel. Communiquez-le au support. Vous
pouvez aussi envoyer le vôtre (1 à 128 caractères parmi `A-Z a-z 0-9 . _ : -`, réutilisé à chaque relance) :

```ts
await client.sms.send({ to: '0812345678', body: 'Bonjour' }, {
  requestId: 'commande-1042',
  onResponse: ({ requestId, apiVersion, rateLimit, replayed }) => log.debug({ requestId, rateLimit }),
});
```

## Relances, délais et annulation

Le SDK relance automatiquement (par défaut 2 relances, backoff exponentiel 0,5 s → 1 s → 2 s avec jitter)
**uniquement quand le rejeu ne peut pas produire deux fois un effet** :

| Requête | Erreur réseau / délai | 5xx | 429 `TOO_MANY_REQUESTS` |
|---|---|---|---|
| `get`, `list`, `balance.get`, `senderIds.list`, `estimate` | relancée | relancée | relancée après `Retry-After` |
| `send` / `sendMany` / `otp.send` (toujours avec `Idempotency-Key`) | relancée, même clé | relancée, même clé | relancée après `Retry-After` |
| `otp.verify` | **jamais** (une tentative peut avoir été consommée) | **jamais** | relancée (refusée avant traitement) |

`503 FEATURE_DISABLED` et `OTP_SERVICE_DISABLED` (refusées avant traitement) font exception à la colonne 5xx, pour
tous les appels, `otp.verify` compris : relancées seulement si `Retry-After` est présent et ne dépasse pas
`maxRetryWaitMs`, après exactement ce délai, jamais avec backoff exponentiel.

`409 IDEMPOTENCY_REQUEST_IN_PROGRESS` (la requête d'origine avec cette clé est encore en cours) est relancée après
`Retry-After` avec la même clé, pour `send`, `sendMany` et `otp.send`. Les autres erreurs métier (4xx,
`OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, `ACCOUNT_SUSPENDED`) ne sont jamais relancées. Si l'API demande
d'attendre plus que `maxRetryWaitMs` (10 s par défaut), le SDK lève l'erreur au lieu d'attendre.

`409 IDEMPOTENCY_KEY_UNRESOLVED` (`sms.send`, `sms.sendMany`, `otp.send`) signifie que la requête d'origine avec
cette clé a échoué de façon inattendue côté API : son résultat est inconnu et un SMS a pu partir. Le SDK ne la
relance **jamais** et lève `IdempotencyKeyUnresolvedError`. Ne la relancez pas automatiquement non plus : vérifiez
d'abord ce qui s'est passé (`client.sms.list({ reference })` pour un SMS envoyé avec une `reference`, ou demandez à
l'utilisateur s'il a reçu le code), puis, seulement si rien n'est parti, renvoyez avec une **nouvelle** clé
d'idempotence (la même clé renvoie toujours cette erreur).

```ts
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

Chaque méthode accepte un dernier argument `options` : `{ signal?, timeoutMs?, requestId?, onResponse? }`.

```ts
await client.balance.get({ signal: AbortSignal.timeout(5_000) });
```

## Configuration

```ts
const client = new Labyrinthe({
  apiKey: process.env.LABYRINTHE_API_KEY,   // valeur par défaut
  baseUrl: 'https://api.labyrinthe-rdc.com/api/public/v1', // défaut (ou LABYRINTHE_BASE_URL) ; "http://localhost:8000" est complété
  timeoutMs: 30_000,              // par tentative
  maxRetries: 2,                  // 0 désactive les relances
  maxRetryWaitMs: 10_000,         // Retry-After maximal accepté
  defaultCountryCode: '243',      // ajouté aux numéros locaux : 0812345678 → 243812345678
  defaultSender: 'MABOUTIQUE',    // utilisé quand send() n'a pas de sender
  fetch: customFetch,             // tout fetch WHATWG (par défaut, le fetch global)
  logger: console,                // debug/info/warn/error(message, contexte) : ni clé, ni code, ni texte, numéros masqués
});
```

`PhoneNumber.normalize('0812345678')` renvoie `"243812345678"` (ou `null`) avec les mêmes règles que l'API ;
`PhoneNumber.isValid()` et `PhoneNumber.mask()` sont aussi exportés. Les numéros internationaux (`+33…`, `+1…`) ne
sont jamais bloqués. Sans « + », 11 chiffres ou plus ne commençant pas par 0 sont déjà internationaux
(`33612345678` reste `33612345678`) ; pour un numéro étranger court (10 chiffres ou moins), mettez toujours le « + ».
Le SDK transmet chaque numéro à l'API au format E.164 avec « + » (`+243812345678`, `+2481234567`) : l'indicatif saisi
est conservé ; les réponses de l'API donnent les chiffres sans « + ».
 `JSON.stringify(client)` et `console.log(client)` affichent `lsk_test_****`, jamais la clé.

## Compatibilité des runtimes

| Runtime | Support |
|---|---|
| Node.js 18, 20, 22, 24 | ✅ (`fetch` global ; sur Node 18, Web Crypto est chargé depuis le module crypto au premier usage) |
| Vercel Edge, routes Edge de Next.js | ✅ |
| Cloudflare Workers | ✅ (sans le drapeau `nodejs_compat`) |
| Deno, Bun | ✅ (`npm:@labyrinthe/connect` sous Deno) |
| Navigateurs | ❌ refusé par défaut : la clé API doit rester côté serveur |

Le cœur n'importe aucun module Node.js et n'a aucune dépendance ; `express` et `next` sont des dépendances pair
facultatives des seuls helpers `/express` et `/next`. Le `User-Agent` est
`labyrinthe-node/<version> <runtime>/<version>`.

## Développement

```bash
npm ci
npm run lint && npm run typecheck
npm test                 # tests unitaires + contrat OpenAPI (fetch simulé, sans réseau)
npm run build && npm run check:dist && npm run publint && npm run attw

# tests d'intégration contre un backend local, avec une clé de TEST uniquement
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 npm run test:integration
```

Le contrat de l'API est [`openapi/openapi.yaml`](../openapi/openapi.yaml) ; les tests de contrat gardent le SDK
aligné. Exemples : [`examples/`](examples).

## Licence

MIT — voir [LICENSE](LICENSE).

# @labyrinthe/otp-widget

[![npm](https://img.shields.io/npm/v/@labyrinthe/otp-widget.svg)](https://www.npmjs.com/package/@labyrinthe/otp-widget)
[![CI](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/widget-otp.yml/badge.svg)](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/widget-otp.yml)
[![Licence : MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)
![min+gzip](https://img.shields.io/badge/min%2Bgzip-8.1%20kB-008374)

Vérification de numéro de téléphone prête à l'emploi pour [Labyrinthe Connect](https://connect.labyrinthe-rdc.com), la
plateforme SMS et OTP pour la RD Congo et l'Afrique centrale : un composant web `<labyrinthe-otp>`, un wrapper React,
et les deux points de terminaison serveur dont il a besoin (Express, Next.js et tout runtime Fetch API).

*English version: [README.md](README.md).*

- **Aucune clé API dans le navigateur.** Le widget n'appelle que *votre* serveur ; votre serveur appelle Labyrinthe
  avec [`@labyrinthe/connect`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/tree/main/node). `otp_id` et
  le code n'arrivent jamais dans le navigateur.
- Étape du numéro (RD Congo `+243` par défaut, même normalisation que le SDK), cases de code séparées, collage,
  `autocomplete="one-time-code"`, Web OTP API sur Android, envoi automatique, compte à rebours de renvoi, essais
  restants, chaque erreur expliquée.
- Accessible (libellés, `role="alert"`, région live, clavier, gestion du focus), responsive, clair / sombre,
  personnalisable par propriétés CSS et `::part()`, français (par défaut) et anglais, chaque texte remplaçable.
- Aucune dépendance, Shadow DOM, **8,1 ko min+gzip** (script autonome), compatible SSR.

Version **1.2.1**, construite sur la spécification de l'API **1.3.0** et `@labyrinthe/connect` 1.2. Nouveautés :
[CHANGELOG](CHANGELOG.md) (en anglais).

## Sommaire

1. [Fonctionnement](#fonctionnement)
2. [Prérequis](#prérequis)
3. [Installation](#installation)
4. [Démarrage rapide](#démarrage-rapide)
5. [Handlers serveur](#handlers-serveur) (Express, Next.js, Hono, Bun, Deno, autres langages)
6. [Options serveur](#options-serveur)
7. [Élément web](#élément-web) (balise script, bundler, attributs, propriétés, événements)
8. [React](#react)
9. [Styles et thèmes](#styles-et-thèmes)
10. [Textes et langues](#textes-et-langues)
11. [Accessibilité](#accessibilité)
12. [Erreurs](#erreurs) et [maintenance](#maintenance-503-service_unavailable)
13. [Sécurité](#sécurité)
14. [Mode test](#mode-test) · [Taille](#taille)
15. [Mise à jour](#mise-à-jour) · [Contribuer](#contribuer) · [Licence](#licence)

## Fonctionnement

Une clé API Labyrinthe (`lsk_live_…`) donne un accès complet à votre compte : qui la détient peut envoyer des SMS sur
vos crédits. Elle doit rester sur votre serveur ; `@labyrinthe/connect` refuse même de s'exécuter dans un navigateur.
Le widget ne parle donc jamais à Labyrinthe : il parle à deux points de terminaison de **votre** serveur, que ce
package fournit aussi.

```
 Navigateur                       Votre serveur                             API Labyrinthe
 <labyrinthe-otp>                 @labyrinthe/otp-widget/server             (lsk_live_… / lsk_test_…)
      │ POST /api/otp/send              │                                         │
      │  { phone, locale, send_id }     │                                         │
      │────────────────────────────────▶│ beforeSend() (vos contrôles anti-abus)  │
      │                                 │ client.otp.send({ phone, … }) ─────────▶│ SMS
      │                                 │◀──────────── { otp_id, expires_at, … }  │
      │◀── { sent, phone_masked, … } ───│ Set-Cookie : { otp_id, phone } chiffré  │
      │    (ni otp_id, ni code)         │   (HttpOnly, SameSite=Strict, Secure)   │
      │ POST /api/otp/verify { code }   │                                         │
      │────────────────────────────────▶│ cookie déchiffré → otp_id               │
      │                                 │ client.otp.verify({ otpId, code }) ────▶│
      │                                 │◀───── { verified, reason, attempts… }   │
      │                                 │ onVerified({ phone }) → votre session   │
      │◀── { verified: true, data } ────│ cookie effacé                           │
```

Le navigateur ne voit jamais qu'un numéro masqué, l'expiration, les essais restants et le délai de renvoi.

## Prérequis

| Partie | Nécessite |
|---|---|
| `<labyrinthe-otp>` | Un navigateur avec Custom Elements et Shadow DOM : Chrome / Edge 84+, Firefox 90+, Safari 15+ |
| `@labyrinthe/otp-widget/react` | React ≥ 18 (18 et 19, App Router de Next.js) |
| `@labyrinthe/otp-widget/server` | Node.js ≥ 18 (`engines`), ou tout runtime avec Fetch et Web Crypto (edge, Deno, Bun), et `@labyrinthe/connect` ^1.2.1 |

`@labyrinthe/connect` et `react` sont des dépendances pairs facultatives : installez-les seulement là où vous
utilisez le helper serveur ou le wrapper React.

## Installation

```bash
npm install @labyrinthe/otp-widget @labyrinthe/connect
# ou
pnpm add @labyrinthe/otp-widget @labyrinthe/connect
# ou
yarn add @labyrinthe/otp-widget @labyrinthe/connect
```

Sans bundler, chargez le script autonome depuis un CDN (fixez la version) :

```html
<script src="https://cdn.jsdelivr.net/npm/@labyrinthe/otp-widget@1.2.1/dist/labyrinthe-otp.min.js" defer></script>
<!-- ou https://unpkg.com/@labyrinthe/otp-widget@1.2.1/dist/labyrinthe-otp.min.js -->
```

Pour l'héberger vous-même, le fichier est exporté sous `@labyrinthe/otp-widget/cdn` (voir
[`examples/express/server.ts`](examples/express/server.ts)).

## Démarrage rapide

**1. Serveur.** Deux points de terminaison qui gardent la clé et `otp_id` (ici Express ; autres frameworks
[plus bas](#handlers-serveur)) :

```ts
import express from 'express';
import { Labyrinthe } from '@labyrinthe/connect';
import { createOtpHandlers } from '@labyrinthe/otp-widget/server';

const otp = createOtpHandlers({
  client: new Labyrinthe(),                        // LABYRINTHE_API_KEY=lsk_test_… (n'envoie rien, ne facture rien)
  secret: process.env.LABYRINTHE_OTP_SECRET,       // ≥ 32 caractères aléatoires : openssl rand -hex 32
  onVerified: async ({ phone, request }) => {
    // Le SEUL endroit où le numéro est digne de confiance : connectez l'utilisateur ici.
    return { redirect: '/dashboard' };             // envoyé au navigateur dans `data`
  },
});

const app = express();
app.use(express.json());
app.post('/api/otp/send', otp.express.send);
app.post('/api/otp/verify', otp.express.verify);
app.listen(3000);
```

**2. Navigateur.**

```html
<script src="https://cdn.jsdelivr.net/npm/@labyrinthe/otp-widget@1.2.1/dist/labyrinthe-otp.min.js" defer></script>

<labyrinthe-otp send-url="/api/otp/send" verify-url="/api/otp/verify"></labyrinthe-otp>
<script>
  document.querySelector('labyrinthe-otp')
    .addEventListener('labyrinthe-otp:verified', (e) => location.assign(e.detail.data.redirect));
</script>
```

Exemples complets : [`examples/express`](examples/express) (page statique + serveur Express) et
[`examples/nextjs`](examples/nextjs) (route handlers + composant client).

## Handlers serveur

`createOtpHandlers(options)` renvoie :

| Membre | Type | Pour |
|---|---|---|
| `send`, `verify` | `(request: Request) => Promise<Response>` | App Router de Next.js, Hono, Bun, Deno, runtimes edge |
| `express.send`, `express.verify` | `(req, res, next) => Promise<void>` | Express, Connect, `node:http` (avec ou sans `express.json()`) |

Créez les handlers **une seule fois** et partagez-les entre les deux routes.

### Express

```ts
app.use(express.json({ limit: '4kb' }));          // facultatif : les handlers lisent aussi le flux brut
app.post('/api/otp/send', otp.express.send);
app.post('/api/otp/verify', otp.express.verify);
```

### Next.js (App Router)

```ts
// lib/otp.ts — serveur uniquement
import { Labyrinthe } from '@labyrinthe/connect';
import { createOtpHandlers } from '@labyrinthe/otp-widget/server';

export const otp = createOtpHandlers({
  client: () => new Labyrinthe(),                  // créé au premier appel, pas au build
  secret: process.env.LABYRINTHE_OTP_SECRET,
  cookie: { path: '/api/otp' },                    // le cookie n'est envoyé qu'aux deux routes OTP
  onVerified: async ({ phone, setCookie }) => {
    const token = await createAppSession(phone);   // votre système d'authentification
    setCookie(`app_session=${token}; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=2592000`);
    return { redirect: '/dashboard' };
  },
});
```

```ts
// app/api/otp/send/route.ts
import { otp } from '../../../../lib/otp';
export const POST = otp.send;

// app/api/otp/verify/route.ts
import { otp } from '../../../../lib/otp';
export const POST = otp.verify;
```

Gardez `LABYRINTHE_API_KEY` et `LABYRINTHE_OTP_SECRET` dans `.env.local`, jamais dans une variable `NEXT_PUBLIC_…`.
Les handlers fonctionnent sur le runtime Node.js comme sur le runtime Edge.

### Hono

```ts
import { Hono } from 'hono';

const app = new Hono();
app.post('/api/otp/send', (c) => otp.send(c.req.raw));
app.post('/api/otp/verify', (c) => otp.verify(c.req.raw));
```

### Bun et Deno

```ts
const handle = (request: Request): Promise<Response> | Response => {
  const { pathname } = new URL(request.url);
  if (pathname === '/api/otp/send') return otp.send(request);
  if (pathname === '/api/otp/verify') return otp.verify(request);
  return new Response('Not found', { status: 404 });
};

Bun.serve({ port: 3000, fetch: handle });          // Bun
// Deno.serve({ port: 3000 }, handle);             // Deno
```

### Autre langage côté serveur

Le widget n'a besoin que de deux points de terminaison JSON : n'importe quel backend peut le servir (par exemple avec
le SDK PHP, Python, .NET ou Java). Le protocole (les types `ErrorBody`, `SendSuccessBody`, `VerifySuccessBody`,
`VerifyFailureBody` sont exportés) :

| Requête (du widget) | Réponse de succès (200) |
|---|---|
| `POST {send-url}` `{ "phone": "+243812345678", "locale": "fr", "send_id": "<32 hex>" }` | `{ "sent": true, "phone_masked": "+243 81•••••78", "expires_at": "…", "max_attempts": 5, "resend_after_seconds": 60, "code_length"?: 6, "code_type"?: "numeric" }` |
| `POST {verify-url}` `{ "code": "482913" }` | `{ "verified": true, "data"?: … }` ou `{ "verified": false, "reason": "invalid_code", "attempts_left": 2 }` |

Erreurs : tout statut avec `{ "error": { "code", "message", "retry_after_seconds"?, "admin_message"?, "until"? } }` et
les codes de la section [Erreurs](#erreurs). Les requêtes partent avec `Content-Type: application/json`, les cookies de
votre origine (`credentials`) et vos `requestHeaders`. De votre côté : gardez `otp_id` dans la session serveur,
dérivez l'`Idempotency-Key` de `otp/send` à partir de `send_id` (le helper utilise un HMAC avec son secret : le
navigateur ne choisit jamais la clé) et ne faites confiance au numéro qu'après `verified: true`.

## Options serveur

| Option | Défaut | Description |
|---|---|---|
| `client` | — | **Obligatoire.** Une instance `Labyrinthe`, ou une fonction qui en renvoie une (appelée au premier usage). |
| `secret` | `LABYRINTHE_OTP_SECRET` | **Obligatoire**, ≥ 32 caractères. Chiffre le cookie et dérive les clés d'idempotence. Ce n'est pas votre clé API. |
| `onVerified(ctx)` | — | **Obligatoire.** Appelée une fois le code vérifié : `ctx.phone` (international, sans `+`), `ctx.request` (`Request` Fetch ou `req` Express), `ctx.setCookie(cookie)`, `ctx.responseHeaders`. Sa valeur de retour part au navigateur dans `data` (JSON, non secret). |
| `beforeSend(ctx)` | — | Vos contrôles anti-abus (`ctx.phone`, `ctx.request`) : renvoyez `false` pour refuser (403 `forbidden`). |
| `onError(error, { action })` | — | Erreurs de Labyrinthe et inattendues (`action` : `'send'` ou `'verify'`), pour vos logs. Ne contient jamais la clé, le code ni `otp_id`. |
| `store` | — (état dans le cookie) | `{ get, set, delete }` (Redis, base de données…) : le cookie ne porte alors qu'un identifiant de session aléatoire. `memoryStore()` pour le développement. |
| `cookie` | `{ name: 'labyrinthe_otp', path: '/', sameSite: 'Strict' }` | Aussi `domain` et `secure` (par défaut : sur les requêtes HTTPS et quand `NODE_ENV=production`). |
| `defaultCountryCode` | `'243'` | Ajouté aux numéros locaux. |
| `template` | paramètres du compte | Modèle du SMS, doit contenir `{code}`. |
| `locale` | `locale` du widget, puis paramètres du compte | Langue du SMS, `'fr'` ou `'en'`. |
| `webOtpDomain` | — | Hôte de votre site (`app.example.com`) : le SMS se termine par `@app.example.com #CODE` pour la Web OTP API. Validé au démarrage. |
| `stripAccents` | `false` | Retire les accents du SMS (GSM-7, souvent moitié prix). |
| `resendAfterSeconds` | celui de l'API, puis 60 | Remplace le compte à rebours avant *Renvoyer* (l'API impose toujours le vrai délai). |
| `codeLength`, `codeType` | ceux de l'API | Remplacent la forme du code. Utiles seulement avec une API antérieure à 1.2.0. |
| `exposeTestCode` | `false` | Développement seulement : transmet au navigateur le `test_code` d'une clé `lsk_test_`. Refusé quand `NODE_ENV=production`. |
| `allowedOrigins` | l'hôte de la requête | Origines autorisées à appeler les points de terminaison (contrôles `Origin` / `Sec-Fetch-Site`). |

| Variable d'environnement | Lue par |
|---|---|
| `LABYRINTHE_API_KEY`, `LABYRINTHE_BASE_URL` | `new Labyrinthe()` (`@labyrinthe/connect`) |
| `LABYRINTHE_OTP_SECRET` | `createOtpHandlers()` sans `secret` |
| `NODE_ENV` | `production` refuse `exposeTestCode` et rend le cookie `Secure` |

`createOtpHandlers()` lève une erreur au démarrage pour un `secret` absent ou trop court, un `onVerified` absent, un
`template` sans `{code}`, un `webOtpDomain` ou un `defaultCountryCode` invalide, et `exposeTestCode` en production.

## Élément web

### Balise script ou bundler

```html
<!-- Script autonome (CDN ou auto-hébergé) : définit <labyrinthe-otp> et la variable globale LabyrintheOtp -->
<script src="https://cdn.jsdelivr.net/npm/@labyrinthe/otp-widget@1.2.1/dist/labyrinthe-otp.min.js" defer></script>
```

```ts
// Avec un bundler (Vite, webpack, Next.js…) : importer le package définit l'élément.
import '@labyrinthe/otp-widget';
// L'importer côté serveur (SSR) ne fait rien.

import { defineLabyrintheOtp } from '@labyrinthe/otp-widget';
defineLabyrintheOtp('my-otp');   // facultatif : enregistrer un autre nom de balise (idempotent)
```

```html
<labyrinthe-otp
  send-url="/api/otp/send"
  verify-url="/api/otp/verify"
  locale="en"
  country-code="243"
  theme="dark"
></labyrinthe-otp>
```

### Attributs

| Attribut | Défaut | Description |
|---|---|---|
| `send-url`, `verify-url` | `/api/otp/send`, `/api/otp/verify` | Vos points de terminaison. |
| `locale` | `fr` | `fr` ou `en` : textes du widget, et langue du SMS sauf si le serveur en impose une. |
| `country-code` | `243` | Indicatif par défaut, modifiable par l'utilisateur. |
| `phone` | — | Numéro pré-rempli. |
| `code-length`, `code-type` | `6`, `numeric` | Forme du code en attendant celle du serveur (toujours fournie avec l'API ≥ 1.2.0). `code-type` : `numeric` ou `alphanumeric`. |
| `theme` | automatique | `light` ou `dark` ; sinon suit `prefers-color-scheme`. |
| `heading` | le texte `title` | Titre au-dessus du formulaire ; `""` le masque. |
| `credentials` | `same-origin` | `include` si vos points de terminaison sont sur une autre origine (alors `cookie: { sameSite: 'None', secure: true }` et `allowedOrigins` côté serveur). |
| `no-auto-submit` | — | Ne pas envoyer dès que toutes les cases sont remplies. |
| `no-web-otp` | — | Ne pas utiliser la Web OTP API. |

### Propriétés et méthodes

| Membre | Description |
|---|---|
| `step` | Lecture seule : `'phone'`, `'code'` ou `'success'`. |
| `texts` | Textes remplacés (objet, fusionné avec ceux de la langue) ; `null` les réinitialise. |
| `requestHeaders` | En-têtes ajoutés aux appels vers vos points de terminaison (ex. jeton CSRF). |
| `reset()` | Retour à l'étape du numéro, code effacé. |
| `focus()` | Donne le focus au premier contrôle focalisable (le Shadow DOM délègue le focus). |

```js
const widget = document.querySelector('labyrinthe-otp');
widget.requestHeaders = { 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content };
```

### Événements

Chaque événement remonte et traverse le Shadow DOM (`bubbles`, `composed`) ; son `detail` est typé (`OtpSentDetail`,
`OtpVerifiedDetail`, `OtpErrorDetail`). Constantes : `EVENT_SENT`, `EVENT_VERIFIED`, `EVENT_ERROR`.

| Événement | `detail` |
|---|---|
| `labyrinthe-otp:sent` | `{ phone, phoneMasked, expiresAt: Date, maxAttempts, resendAfterSeconds, testCode? }` — premier envoi et chaque renvoi |
| `labyrinthe-otp:verified` | `{ phone, data }` — `data` est ce qu'a renvoyé votre `onVerified()` |
| `labyrinthe-otp:error` | `{ code, message, status, retryAfterSeconds?, attemptsLeft?, adminMessage?, until?: Date }` — `message` est le texte affiché, `status` vaut `null` pour les erreurs locales et réseau |

```js
widget.addEventListener('labyrinthe-otp:error', (e) => analytics.track('otp_error', { code: e.detail.code }));
widget.addEventListener('labyrinthe-otp:verified', (e) => location.assign(e.detail.data.redirect));
```

L'événement `verified` ne sert qu'à l'expérience utilisateur (redirection, message) : le serveur a déjà fait confiance
au numéro dans `onVerified()`. Ne faites jamais confiance à l'événement du navigateur.

### Web OTP API (Chrome Android)

Pour que le navigateur remplisse le code automatiquement, la dernière ligne du SMS doit être `@votre-domaine.com #CODE`,
où le domaine est exactement l'hôte de la page qui affiche le widget (page de premier niveau, HTTPS). Passez l'option
serveur `webOtpDomain: 'boutique.example.com'` : l'API ajoute cette ligne elle-même (ne la mettez pas dans votre
`template`). Le widget appelle alors `navigator.credentials.get({ otp: { transport: ['sms'] } })` tant que les cases du
code sont affichées (désactivable avec `no-web-otp`) ; la saisie, le collage et `autocomplete="one-time-code"`
continuent de fonctionner partout.

## React

```tsx
'use client';
import { useRouter } from 'next/navigation';
import { LabyrintheOtp } from '@labyrinthe/otp-widget/react';

export function VerifyPhone() {
  const router = useRouter();
  return (
    <LabyrintheOtp
      sendUrl="/api/otp/send"
      verifyUrl="/api/otp/verify"
      locale="fr"
      texts={{ send: 'Recevoir mon code' }}
      onError={(error) => console.warn(error.code)}
      onVerified={({ data }) => router.push((data as { redirect: string }).redirect)}
    />
  );
}
```

| Prop | Équivaut à |
|---|---|
| `sendUrl`, `verifyUrl`, `locale`, `countryCode`, `phone`, `codeLength`, `codeType`, `theme`, `heading`, `credentials` | les attributs ci-dessus |
| `autoSubmit` (`true` par défaut), `webOtp` (`true` par défaut) | `no-auto-submit`, `no-web-otp` quand `false` |
| `texts`, `requestHeaders` | les propriétés ci-dessus |
| `onSent`, `onVerified`, `onError` | les événements ci-dessus (reçoivent le `detail`) |
| `className`, `style`, `id` | sur l'élément hôte |

Le composant est un composant client (`'use client'`), compatible SSR, fonctionne avec React 18 et 19, et transmet une
`ref` vers l'élément `<labyrinthe-otp>` (`ref.current.reset()`). Les callbacks utilisent toujours les dernières props,
sans réabonnement.

## Styles et thèmes

Le widget s'affiche dans un Shadow DOM : personnalisez-le par propriétés CSS posées sur l'hôte et par `::part()`.

```css
labyrinthe-otp {
  --lab-otp-primary: #008374;          /* boutons, liens, anneau de focus */
  --lab-otp-primary-contrast: #fff;    /* texte sur la couleur principale */
  --lab-otp-accent: #F85A40;           /* encart du mode test */
  --lab-otp-error: #c2381f;
  --lab-otp-success: #008374;
  --lab-otp-bg: #fff;
  --lab-otp-fg: #19201e;
  --lab-otp-muted: #5b6763;
  --lab-otp-border: #d5dcd9;
  --lab-otp-field: #f8faf9;            /* fond des champs */
  --lab-otp-radius: 10px;
  --lab-otp-font: Inter, ui-sans-serif, system-ui, sans-serif;
  --lab-otp-cell-size: 44px;           /* cases du code (36px sous 360px de large) */
  --lab-otp-gap: 8px;
}
labyrinthe-otp::part(root) { border: 0; box-shadow: 0 8px 30px rgb(0 0 0 / 0.08); }
labyrinthe-otp::part(cell) { font-size: 24px; }
```

Ces valeurs sont celles du thème clair (sarcelle `#008374`, corail `#F85A40`) ; `theme="dark"`, ou le mode sombre du
système sans `theme="light"`, bascule sur une palette sombre. L'hôte est en `display: block` avec `max-width: 420px`.

Parts : `root`, `title`, `phone-form`, `country-code`, `phone-input`, `button`, `send-button`, `code-form`, `cells`,
`cell`, `verify-button`, `link`, `change-button`, `resend-button`, `test-code`, `success`, `error`.

## Textes et langues

`locale="fr"` (par défaut) ou `locale="en"`. Remplacez n'importe quel texte avec la propriété `texts` (ou la prop
React) : les clés de premier niveau et les entrées de `errors` sont fusionnées avec les textes de la langue.

```js
widget.texts = {
  send: 'Recevoir mon code',
  errors: { invalid_code: 'Raté, réessayez.' },
};
```

| Clés | Variables |
|---|---|
| `title`, `phoneLabel`, `countryCodeLabel`, `phonePlaceholder`, `phoneHint`, `send`, `sending`, `codeLabel`, `verify`, `verifying`, `changePhone`, `resend`, `resendAvailable`, `attemptsLeftOne`, `success` | — |
| `codeSentTo` | `{phone}` |
| `cellLabel` (nom accessible de chaque case) | `{index}`, `{total}` |
| `resendIn`, `retryIn` | `{time}` (`1:15`, ou `1:30:00` au-delà d'une heure) |
| `attemptsLeftOther` | `{count}` |
| `testMode` | `{code}` |
| `errors.<code>` (voir [Erreurs](#erreurs)) | `{time}` dans `resend_too_soon`, `{count}` dans `incomplete_code` |

`LOCALES` (les textes français et anglais intégrés), `resolveTexts(locale, override)` et les types `OtpWidgetTexts` /
`OtpWidgetTextsOverride` sont exportés, par exemple pour construire une troisième langue à partir des textes anglais.

## Accessibilité

- Chaque champ a un libellé ; les cases du code forment un `role="group"` avec un nom accessible par case
  (`cellLabel`).
- Les erreurs sont annoncées (`role="alert"`, de nouveau annoncées si la même erreur se répète) ; la progression (code
  envoyé, vérifié) passe par une région live polie.
- Utilisation complète au clavier : flèches, Début, Fin et Retour arrière déplacent entre les cases, le collage remplit
  toutes les cases, `enterkeyhint="done"`.
- Le focus va à la première case vide après un envoi, sur *Renvoyer* quand un nouveau code est nécessaire, sur le
  message de succès à la fin.
- Anneau de focus visible, `aria-invalid` sur un numéro refusé, `aria-busy` pendant l'attente, transitions désactivées
  avec `prefers-reduced-motion`.

## Erreurs

Les codes d'erreur sont stables, en minuscules, et affichés avec le texte localisé `errors.<code>`. Les messages de
Labyrinthe ne sont jamais transmis au navigateur, sauf le message de maintenance.

| `code` | HTTP de votre serveur | Quand | Erreur de l'API Labyrinthe |
|---|---|---|---|
| `invalid_phone` | 422 | Numéro invalide, en liste noire ou désinscrit | `INVALID_PHONE`, `RECIPIENT_NOT_SENDABLE`, validation locale |
| `resend_too_soon` | 429 | Code envoyé trop récemment : compte à rebours de `retry_after_seconds` | `OTP_RESEND_TOO_SOON` |
| `rate_limited` | 429 | Trop de demandes (numéro, compte, clé) ou envoi encore en cours | `OTP_RATE_LIMITED`, `TOO_MANY_REQUESTS`, `IDEMPOTENCY_REQUEST_IN_PROGRESS` |
| `no_pending_verification` | 400 | Aucun code en attente pour ce navigateur (cookie absent, expiré ou modifié) | `NOT_FOUND` à la vérification |
| `forbidden` | 403 | Refusé par `beforeSend()` ou par le contrôle d'origine | — |
| `invalid_request` | 400, 405, 413, 415, 422 | Requête malformée (pas `POST`, pas du JSON, trop grande, `send_id` ou code invalide) | — |
| `service_unavailable` | 503 | Labyrinthe injoignable ou mal configuré (clé, crédits, Sender ID), ou [maintenance](#maintenance-503-service_unavailable) | `FEATURE_DISABLED`, `OTP_SERVICE_DISABLED`, autres |
| `account_suspended` | 503 | Votre compte Labyrinthe est suspendu | `ACCOUNT_SUSPENDED` |
| `send_failed` | 502 | L'envoi a échoué de façon inattendue côté Labyrinthe, un code a pu partir | `IDEMPOTENCY_KEY_UNRESOLVED` |
| `server_error` | 500 | Erreur inattendue de votre serveur (ex. `onVerified()` a levé une erreur) | — |

Les refus d'un code sont répondus en `200 { verified: false, reason }` : `invalid_code` (essais restants affichés),
`expired`, `already_used`, `max_attempts_reached` (les trois derniers demandent un nouveau code). Codes propres au
widget, avec `status: null` : `network_error` (votre serveur est injoignable) et `incomplete_code`.

`onError()` reçoit toutes les erreurs de Labyrinthe sauf celles causées par l'utilisateur (`invalid_phone`,
`resend_too_soon`, `rate_limited`), plus les erreurs inattendues : journalisez le `requestId` des erreurs de
`@labyrinthe/connect`.

### Maintenance (503 `service_unavailable`)

Quand la plateforme Labyrinthe coupe temporairement l'API ou les fonctions OTP (`503 FEATURE_DISABLED` /
`OTP_SERVICE_DISABLED`), rien n'est envoyé et le helper répond :

```json
{ "error": { "code": "service_unavailable", "message": "The verification service is temporarily unavailable.",
  "admin_message": "Maintenance jusqu'à 14 h.", "until": "2026-11-06T14:00:00+00:00", "retry_after_seconds": 5400 } }
```

- `admin_message` : le message de l'administrateur de la plateforme, écrit pour les utilisateurs finaux (nettoyé,
  500 caractères au maximum), quand il y en a un — le seul texte de Labyrinthe transmis ;
- `until` (ISO 8601) et `retry_after_seconds` + un en-tête `Retry-After` : seulement quand la fin est programmée ;
- `onError()` est appelée.

Le widget affiche le message de l'administrateur (en texte brut) ou `errors.service_unavailable`, suivi de `retryIn`
(« Réessayez dans 1:30:00. ») quand le temps restant est connu. L'événement `labyrinthe-otp:error` porte `adminMessage`
et `until` (`Date`). Rien n'est relancé automatiquement ; l'utilisateur peut réessayer plus tard.

## Sécurité

- **La clé API reste sur votre serveur.** Le navigateur ne la voit jamais, ni `otp_id`, ni le code.
- `otp_id` et le numéro voyagent dans un cookie **chiffré et authentifié en AES-256-GCM** (clé dérivée de `secret` par
  HKDF-SHA-256), `HttpOnly`, `SameSite=Strict`, `Secure` en HTTPS. Le navigateur ne peut ni le lire ni le modifier.
  Avec `store`, le cookie ne porte qu'un identifiant de session aléatoire (lui aussi chiffré).
- **Faites confiance au numéro dans `onVerified()`**, côté serveur, jamais dans l'événement du navigateur.
- Les points de terminaison n'acceptent que `POST` + `application/json` (un formulaire d'un autre site ne peut pas
  l'envoyer sans pré-vol CORS), des corps de 4 ko au plus, et refusent les autres origines (`Origin` /
  `Sec-Fetch-Site` ; élargir avec `allowedOrigins`). Ajoutez un jeton CSRF avec `requestHeaders` si votre application
  en utilise un. Les réponses sont en `Cache-Control: no-store`.
- **Chaque envoi est un SMS facturé** : utilisez `beforeSend()` pour limiter par IP ou par compte, ajouter un captcha
  ou une liste d'autorisation. L'API limite aussi les envois par numéro et par compte (`resend_too_soon`,
  `rate_limited`).
- **Jamais de double SMS.** Chaque envoi porte un `send_id` aléatoire, conservé par le widget jusqu'à la réussite de
  l'envoi : après une réponse perdue, le clic suivant le réutilise. Le helper en dérive l'`Idempotency-Key` par un HMAC
  de `secret` (le navigateur ne choisit jamais la clé envoyée à Labyrinthe) : l'API rejoue le premier envoi au lieu
  d'envoyer un nouveau SMS. Le SDK relance aussi les erreurs réseau et les 5xx avec la même clé.
- **Issue inconnue (`send_failed`).** Après `409 IDEMPOTENCY_KEY_UNRESOLVED`, rien n'est relancé automatiquement ; le
  widget oublie son `send_id`, si bien qu'un nouveau code demandé par l'utilisateur part avec une nouvelle clé.
- Le code n'est jamais renvoyé au navigateur, sauf `test_code` avec une clé de test quand vous activez explicitement
  `exposeTestCode` en développement (refusé en production ; l'API ne le renvoie jamais avec une clé live).
- Le message de maintenance de l'administrateur est inséré comme texte, jamais comme HTML.

## Mode test

Avec une clé `lsk_test_…`, rien n'est envoyé ni facturé. En développement, `exposeTestCode: true` affiche le code dans
le widget (« Mode test : le code est … ») et dans l'événement `sent`, pour essayer tout le parcours sans téléphone :

```ts
createOtpHandlers({
  client: new Labyrinthe({ apiKey: 'lsk_test_…' }),
  secret: process.env.LABYRINTHE_OTP_SECRET,
  exposeTestCode: process.env.NODE_ENV === 'development',
  onVerified: () => ({ ok: true }),
});
```

## Taille

| Fichier | gzip | |
|---|---|---|
| `dist/labyrinthe-otp.min.js` | 8,1 ko minifié (7,2 ko brotli) | `<script>` autonome ; budget CI de 10 ko min+gzip (`npm run size`) |
| `dist/index.js` | 9,6 ko non minifié | ESM pour bundlers (minifié par votre build) |
| `dist/react.js` | 0,7 ko | wrapper React, sans l'élément |
| `dist/server.js` | 6,8 ko non minifié | serveur uniquement, jamais envoyé au navigateur |

Aucune dépendance à l'exécution. `sideEffects` est déclaré : les bundlers conservent la définition de l'élément et
éliminent le reste.

## Mise à jour

- **1.1.x → 1.2.0** (API 1.3.0) : passez la dépendance pair `@labyrinthe/connect` à `^1.2.0`. Aucun changement de
  code. La maintenance est répondue en `service_unavailable` avec `admin_message`, `until` et `Retry-After` ;
  l'événement d'erreur gagne `adminMessage` et `until` ; nouveau texte `retryIn` (à ajouter si vous passez un objet
  `OtpWidgetTexts` complet) ; les durées d'une heure ou plus s'affichent en `h:mm:ss`.
- **1.1.0 → 1.1.1** : nécessite `@labyrinthe/connect` ≥ 1.1.1 (les numéros étrangers courts gardent leur indicatif).
  Nouveau code d'erreur `send_failed`.
- **1.0.x → 1.1.0** (API 1.2.0) : longueur, alphabet du code et délai de renvoi viennent de l'API : retirez
  `codeLength`, `codeType` et `resendAfterSeconds` des options serveur sauf besoin de les forcer. Essais restants réels,
  nouvelles options `webOtpDomain` et `stripAccents`, nouveau code d'erreur `account_suspended`, et la requête d'envoi
  gagne `send_id` (un serveur maison doit l'accepter).

Détails : [CHANGELOG](CHANGELOG.md).

## Contribuer

Tickets et pull requests vont dans le monorepo
[Labyrinthe-Team/labyrinthe-connect-sdks](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks)
(voir [CONTRIBUTING.md](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/CONTRIBUTING.md)). Dans
`widget-otp/` :

```bash
npm ci
npm run check            # lint + types + tests (happy-dom, React, serveur) + build + check:dist + size + publint + attw

# tests d'intégration contre un backend local, avec une clé de TEST uniquement
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 npm run test:integration
```

Failles de sécurité : écrivez à connect@labyrinthe-rdc.com plutôt que d'ouvrir un ticket public.

## Licence

MIT — voir [LICENSE](LICENSE).

# @labyrinthe/otp-widget

Vérification de numéro de téléphone prête à l'emploi pour [Labyrinthe Connect](https://labyrinthe-rdc.com) : un
composant web `<labyrinthe-otp>` (et son wrapper React) et les deux endpoints serveur dont il a besoin, pour
Express et Next.js.

*English version: [README.md](README.md).*

- **Aucune clé API dans le navigateur.** Le widget n'appelle que *votre* serveur ; votre serveur appelle Labyrinthe
  avec [`@labyrinthe/connect`](../node). `otp_id` et le code n'atteignent jamais le navigateur.
- Saisie du numéro (`+243` par défaut, même normalisation que le SDK), cases séparées pour le code, collage,
  `autocomplete="one-time-code"`, Web OTP API sur Android, compte à rebours de renvoi, tentatives restantes,
  chaque erreur expliquée.
- Accessible (libellés, `role="alert"`, région live, clavier, gestion du focus), responsive, clair / sombre,
  thémable par variables CSS et `::part()`, français (par défaut) et anglais, tous les textes surchargeables.
- Zéro dépendance, Shadow DOM, **8,1 kB min+gzip** (script autonome).

> **Nouveautés 1.2.0** (API 1.3.0, `@labyrinthe/connect` ≥ 1.2.0) : une maintenance Labyrinthe (`FEATURE_DISABLED`,
> ou service OTP coupé) s'affiche comme `service_unavailable` avec le message de l'administrateur et, si la fin est
> programmée, le temps restant et un en-tête `Retry-After`. Voir [Maintenance](#maintenance).
>
> **Nouveautés 1.1.0** (API 1.2.0, `@labyrinthe/connect` ≥ 1.1.1) : longueur / alphabet du code et délai de renvoi
> fournis par l'API (plus de configuration manuelle), tentatives restantes réelles, `webOtpDomain` pour la Web OTP
> API, envois idempotents (une réponse perdue ne déclenche jamais un second SMS), erreur stable `account_suspended`.
> Voir le [CHANGELOG](CHANGELOG.md).

## Intégration en 5 minutes

```bash
npm install @labyrinthe/otp-widget @labyrinthe/connect
```

**1. Serveur (garde la clé et `otp_id`)** — Express :

```ts
import express from 'express';
import { Labyrinthe } from '@labyrinthe/connect';
import { createOtpHandlers } from '@labyrinthe/otp-widget/server';

const otp = createOtpHandlers({
  client: new Labyrinthe(),                        // LABYRINTHE_API_KEY=lsk_test_… (rien n'est envoyé ni facturé)
  secret: process.env.LABYRINTHE_OTP_SECRET!,      // ≥ 32 caractères aléatoires : openssl rand -hex 32
  onVerified: async ({ phone, request }) => {
    // Le SEUL endroit où le numéro est digne de confiance : connectez l'utilisateur ici.
    return { redirect: '/dashboard' };             // transmis au navigateur dans `data`
  },
});

const app = express();
app.use(express.json());
app.post('/api/otp/send', otp.express.send);
app.post('/api/otp/verify', otp.express.verify);
```

Next.js App Router : `export const POST = otp.send;` dans `app/api/otp/send/route.ts`, et `otp.verify` dans
`app/api/otp/verify/route.ts` (les handlers prennent une `Request` Fetch et renvoient une `Response` : Hono, Bun,
Deno et les runtimes edge s'utilisent de la même façon).

**2. Navigateur** — HTML simple :

```html
<script type="module">import '@labyrinthe/otp-widget';</script>
<!-- ou, sans bundler : <script src="https://unpkg.com/@labyrinthe/otp-widget@1/dist/labyrinthe-otp.min.js"></script> -->

<labyrinthe-otp send-url="/api/otp/send" verify-url="/api/otp/verify"></labyrinthe-otp>
<script>
  document.querySelector('labyrinthe-otp')
    .addEventListener('labyrinthe-otp:verified', (e) => location.assign(e.detail.data.redirect));
</script>
```

React / Next.js (composant client) :

```tsx
'use client';
import { LabyrintheOtp } from '@labyrinthe/otp-widget/react';

<LabyrintheOtp sendUrl="/api/otp/send" verifyUrl="/api/otp/verify"
  onVerified={({ data }) => router.push((data as { redirect: string }).redirect)} />
```

Exemples complets : [`examples/express`](examples/express) (page statique + serveur Express) et
[`examples/nextjs`](examples/nextjs) (route handlers + composant client).

## Fonctionnement

```
 Navigateur                       Votre serveur                              API Labyrinthe
 <labyrinthe-otp>                 @labyrinthe/otp-widget/server              (lsk_live_… / lsk_test_…)
      │ POST /api/otp/send {phone}      │                                         │
      │────────────────────────────────▶│ beforeSend() (vos contrôles anti-abus)  │
      │                                 │ client.otp.send({ phone }) ────────────▶│ SMS
      │                                 │◀──────────── { otp_id, expires_at, … }  │
      │◀── { sent, phone_masked, … } ───│ Set-Cookie : { otp_id, phone } chiffré  │
      │    (ni otp_id, ni code)         │   (HttpOnly, SameSite=Strict, Secure)   │
      │ POST /api/otp/verify {code}     │                                         │
      │────────────────────────────────▶│ déchiffre le cookie → otp_id            │
      │                                 │ client.otp.verify({ otpId, code }) ────▶│
      │                                 │◀─────────────── { verified, reason }    │
      │                                 │ onVerified({ phone }) → votre session   │
      │◀── { verified: true, data } ────│ cookie effacé                           │
```

## Sécurité

- La clé API reste sur votre serveur (le SDK refuse même de démarrer dans un navigateur).
- `otp_id` et le numéro voyagent dans un cookie **chiffré et authentifié en AES-256-GCM** (clé dérivée de `secret`
  par HKDF-SHA-256), `HttpOnly`, `SameSite=Strict`, `Secure` en HTTPS : le navigateur ne peut ni le lire ni le
  modifier. Vous préférez un stockage serveur ? Passez `store` (Redis, base de données… ; `memoryStore()` en
  développement) : le cookie ne contient alors qu'un identifiant de session aléatoire.
- Le code n'est jamais renvoyé au navigateur. Avec une clé `lsk_test_`, l'API renvoie un `test_code` (rien n'est
  envoyé en mode test) ; le helper ne le transmet **que** si `exposeTestCode: true`, option refusée quand
  `NODE_ENV=production`, et l'API ne le renvoie jamais avec une clé live.
- Faites confiance au numéro dans `onVerified()`, côté serveur, jamais dans l'événement du navigateur.
- Les endpoints n'acceptent que `POST` + `application/json` (un formulaire d'un autre site ne peut pas l'envoyer
  sans pré-vol CORS) et refusent les autres origines (`Origin` / `Sec-Fetch-Site` ; à élargir avec
  `allowedOrigins`). Ajoutez votre jeton CSRF avec la propriété `requestHeaders` si votre application en utilise un.
- Chaque envoi est un SMS facturé : utilisez `beforeSend()` pour limiter par IP ou par compte, ajouter un captcha
  ou une liste blanche. L'API limite aussi les envois par numéro et par compte (`resend_too_soon`, `rate_limited`).
- Les messages de l'API ne sont jamais relayés : le navigateur reçoit des codes stables (`invalid_phone`,
  `resend_too_soon`, `rate_limited`, `no_pending_verification`, `service_unavailable`, `account_suspended`…). Une
  erreur de configuration (clé, crédits, Sender ID) apparaît comme `service_unavailable`, un compte Labyrinthe
  suspendu (`ACCOUNT_SUSPENDED`) comme `account_suspended` (503) ; les deux partent dans votre `onError()`. Seule
  exception : le message de maintenance ci-dessous.
- <a id="maintenance"></a>**Maintenance (`service_unavailable`, 503).** Quand la plateforme Labyrinthe coupe
  temporairement l'API ou les fonctions OTP (`503 FEATURE_DISABLED` / `OTP_SERVICE_DISABLED`), le helper répond
  `service_unavailable` avec `admin_message` (le message de l'administrateur de la plateforme, écrit pour les
  utilisateurs, s'il y en a un), `until` (ISO 8601) et `retry_after_seconds` + un en-tête `Retry-After` quand la fin
  est programmée, et appelle `onError()`. Le widget affiche le message de l'administrateur (ou
  `errors.service_unavailable`) suivi de `retryIn` (« Réessayez dans 1:30. ») quand le temps restant est connu ;
  l'événement d'erreur porte `adminMessage` et `until` (`Date`).
- **Résultat inconnu : `send_failed` (502).** Quand l'API répond `409 IDEMPOTENCY_KEY_UNRESOLVED` (l'envoi d'origine
  avec cette clé a échoué de façon inattendue de son côté : un code a pu partir), le helper renvoie `send_failed` et
  appelle `onError()` (journalisez le `requestId` de l'erreur). Rien n'est relancé automatiquement. Le widget indique
  à l'utilisateur que l'envoi n'a pas pu être confirmé et oublie son `send_id` : si le code n'arrive pas,
  l'utilisateur en redemande un, envoyé avec une nouvelle clé d'idempotence.
- **Jamais de double SMS.** Chaque envoi porte un `send_id` aléatoire, gardé par le widget jusqu'à ce que l'envoi
  aboutisse : après une réponse perdue, le clic suivant le réutilise. Le helper en dérive l'`Idempotency-Key` par
  HMAC avec `secret` (le navigateur ne choisit jamais la clé envoyée à Labyrinthe) : l'API rejoue le premier envoi
  au lieu d'envoyer un nouveau SMS. Le SDK relance aussi les erreurs réseau et les 5xx avec la même clé.

## Options serveur (`createOtpHandlers`)

| Option | Défaut | |
|---|---|---|
| `client` | — | Une instance `Labyrinthe`, ou une fonction qui la renvoie (créée au premier appel). |
| `secret` | `LABYRINTHE_OTP_SECRET` | ≥ 32 caractères. Chiffre le cookie. Ce n'est pas la clé API. |
| `onVerified(ctx)` | — | **Obligatoire.** `ctx.phone`, `ctx.request` (`Request` Fetch ou `req` Express), `ctx.setCookie()`, `ctx.responseHeaders`. Valeur renvoyée → `data`. |
| `beforeSend(ctx)` | — | Renvoyer `false` refuse l'envoi (403 `forbidden`). |
| `store` | — (cookie seul) | `{ get, set, delete }` ; `memoryStore()` en développement. |
| `cookie` | `labyrinthe_otp`, `/`, `Strict` | `name`, `path`, `domain`, `sameSite`, `secure`. |
| `defaultCountryCode` | `243` | Ajouté aux numéros locaux. |
| `template`, `locale` | paramètres du compte | Modèle du SMS (doit contenir `{code}`) et langue (sinon celle du widget). |
| `webOtpDomain` | — | Hôte de votre site (`app.exemple.com`) : le SMS se termine par `@app.exemple.com #CODE` pour la Web OTP API. |
| `stripAccents` | `false` | Retire les accents du SMS (GSM-7, souvent deux fois moins cher). |
| `resendAfterSeconds` | fourni par l'API | Surcharge du compte à rebours avant « Renvoyer » (par défaut `resend_after_seconds` de l'API, sinon 60). |
| `codeLength`, `codeType` | fournis par l'API | Surcharges de la forme du code (par défaut `code_length` / `code_type` de l'API). Utiles seulement avec une API antérieure à 1.2.0. |
| `exposeTestCode` | `false` | Développement uniquement, clés de test uniquement. |
| `allowedOrigins` | même hôte | Origines autorisées à appeler les endpoints. |
| `onError(error, { action })` | — | Erreurs de l'API et erreurs inattendues, pour vos journaux. |

## Référence de l'élément

| Attribut | Défaut | |
|---|---|---|
| `send-url`, `verify-url` | `/api/otp/send`, `/api/otp/verify` | Vos endpoints. |
| `locale` | `fr` | `fr` ou `en` (aussi la langue du SMS, sauf si le serveur en impose une). |
| `country-code` | `243` | Indicatif par défaut, modifiable par l'utilisateur. |
| `phone` | — | Numéro prérempli. |
| `code-length`, `code-type` | `6`, `numeric` | Jusqu'à ce que le serveur indique autre chose (toujours le cas avec l'API 1.2.0). |
| `theme` | automatique | `light` ou `dark` ; sinon suit `prefers-color-scheme`. |
| `heading` | « Vérification du numéro » | `""` masque le titre. |
| `credentials` | `same-origin` | `include` pour des endpoints sur une autre origine (avec `cookie.sameSite: 'None'`). |
| `no-auto-submit`, `no-web-otp` | — | Désactivent l'envoi automatique quand les cases sont pleines / la Web OTP API. |

Propriétés : `texts` (surcharges), `requestHeaders`, `step` (`phone` · `code` · `success`), `reset()`, `focus()`.

| Événement (`bubbles`, `composed`) | `detail` |
|---|---|
| `labyrinthe-otp:sent` | `{ phone, phoneMasked, expiresAt, maxAttempts, resendAfterSeconds, testCode? }` |
| `labyrinthe-otp:verified` | `{ phone, data }` |
| `labyrinthe-otp:error` | `{ code, message, status, retryAfterSeconds?, attemptsLeft?, adminMessage?, until? }` — `code` : `invalid_phone`, `invalid_code`, `expired`, `already_used`, `max_attempts_reached`, `resend_too_soon`, `rate_limited`, `no_pending_verification`, `network_error`, `incomplete_code`, `service_unavailable`, `account_suspended`, `send_failed`, `forbidden`, `invalid_request`, `server_error` |

## Personnalisation

```css
labyrinthe-otp {
  --lab-otp-primary: #008374;   /* boutons, liens, focus */
  --lab-otp-accent: #F85A40;    /* encadré du mode test */
  --lab-otp-error: #c2381f;
  --lab-otp-bg: #fff; --lab-otp-fg: #19201e; --lab-otp-muted: #5b6763;
  --lab-otp-border: #d5dcd9; --lab-otp-field: #f8faf9;
  --lab-otp-radius: 10px; --lab-otp-font: Inter, system-ui, sans-serif;
  --lab-otp-cell-size: 44px; --lab-otp-gap: 8px;
}
labyrinthe-otp::part(root) { box-shadow: none; border: 0; }
labyrinthe-otp::part(cell) { font-size: 24px; }
```

Parts : `root`, `title`, `phone-form`, `country-code`, `phone-input`, `button`, `send-button`, `code-form`, `cells`,
`cell`, `verify-button`, `link`, `change-button`, `resend-button`, `test-code`, `success`, `error`.

```js
widget.texts = { send: 'Recevoir mon code', errors: { invalid_code: 'Raté, réessayez.' } };
```

**Web OTP API (Chrome Android) :** pour que le navigateur remplisse le code tout seul, la dernière ligne du SMS doit
être `@votre-domaine.com #CODE`, où le domaine est exactement l'hôte de la page qui affiche le widget (page de
premier niveau, HTTPS). Passez l'option serveur `webOtpDomain: 'maboutique.cd'` : l'API ajoute cette ligne elle-même
(ne la mettez pas dans votre `template`). Le widget appelle alors `navigator.credentials.get({ otp: { transport: ['sms'] } })`
pendant l'affichage des cases (désactivable avec `no-web-otp`) ; la saisie ou le collage du code restent possibles partout.


## Mode test

Avec une clé `lsk_test_…`, rien n'est envoyé ni facturé. En développement, `exposeTestCode: true` affiche le code
dans le widget (« Mode test : le code est … ») pour essayer tout le parcours sans téléphone.

```bash
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 npm run test:integration
```

## Prérequis

Navigateurs avec Custom Elements et Shadow DOM (Chrome/Edge 84+, Firefox 90+, Safari 15+). Helper serveur :
Node ≥ 18, ou tout runtime avec Fetch et Web Crypto (edge, Deno, Bun). React ≥ 18 pour le wrapper.

## Licence

MIT

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
- Zéro dépendance, Shadow DOM, **7,6 kB min+gzip** (script autonome).

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
  `resend_too_soon`, `rate_limited`, `no_pending_verification`, `service_unavailable`…). Une erreur de configuration
  (clé, crédits, Sender ID) apparaît comme `service_unavailable` et part dans votre `onError()`.

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
| `resendAfterSeconds` | `60` | Compte à rebours avant « Renvoyer ». À aligner sur le délai anti-renvoi du compte. |
| `codeLength`, `codeType` | `6`, `numeric` | À aligner sur les paramètres OTP du compte (l'API ne les renvoie pas). |
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
| `code-length`, `code-type` | `6`, `numeric` | Jusqu'à ce que le serveur indique autre chose. |
| `theme` | automatique | `light` ou `dark` ; sinon suit `prefers-color-scheme`. |
| `heading` | « Vérification du numéro » | `""` masque le titre. |
| `credentials` | `same-origin` | `include` pour des endpoints sur une autre origine (avec `cookie.sameSite: 'None'`). |
| `no-auto-submit`, `no-web-otp` | — | Désactivent l'envoi automatique quand les cases sont pleines / la Web OTP API. |

Propriétés : `texts` (surcharges), `requestHeaders`, `step` (`phone` · `code` · `success`), `reset()`, `focus()`.

| Événement (`bubbles`, `composed`) | `detail` |
|---|---|
| `labyrinthe-otp:sent` | `{ phone, phoneMasked, expiresAt, maxAttempts, resendAfterSeconds, testCode? }` |
| `labyrinthe-otp:verified` | `{ phone, data }` |
| `labyrinthe-otp:error` | `{ code, message, status, retryAfterSeconds?, attemptsLeft? }` — `code` : `invalid_phone`, `invalid_code`, `expired`, `already_used`, `max_attempts_reached`, `resend_too_soon`, `rate_limited`, `no_pending_verification`, `network_error`, `incomplete_code`, `service_unavailable`, `forbidden`, `invalid_request`, `server_error` |

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
être `@votre-domaine.com #CODE`. Utilisez un `template` serveur comme
`'Votre code : {code}\n\n@maboutique.cd #{code}'`.

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

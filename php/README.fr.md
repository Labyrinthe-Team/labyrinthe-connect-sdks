# Labyrinthe Connect — SDK PHP

Client PHP officiel de [Labyrinthe Connect](https://labyrinthe-rdc.com) : envoi de SMS et vérification de numéros
par code à usage unique (OTP), en RDC et en Afrique centrale.

*English version: [README.md](README.md). Sous Laravel, utilisez [`labyrinthe/connect-laravel`](../laravel).*

- Entièrement typé : objets de réponse immuables, enums, une classe d'exception par erreur.
- Sûr par défaut : nouvelles tentatives automatiques **uniquement** quand elles ne peuvent pas envoyer un SMS deux fois.
- Fonctionne avec Guzzle sans configuration, ou avec n'importe quel client PSR-18.
- Ne journalise ni n'affiche jamais votre clé API, les codes OTP ou les numéros complets.

## Installation

```bash
composer require labyrinthe/connect-php
```

PHP 8.1 ou plus, extensions `json` et `mbstring`.

## Démarrage rapide

```php
use Labyrinthe\Connect\Client;

$client = new Client('lsk_test_…');                 // ou Client::fromEnv()
$message = $client->sms->send(to: '0812345678', body: 'Votre commande 1042 est prête.');
echo $message->status->value;                         // "queued"
```

Les clés se créent dans l'espace client (*Développeurs > Clés API*). **Gardez-les côté serveur**, jamais dans un
navigateur ni une application mobile.

| Clé | Mode | Effet |
|---|---|---|
| `lsk_test_…` | Bac à sable | Rien n'est envoyé, aucun crédit consommé. Réponses identiques à la production. L'envoi d'OTP renvoie le code (`testCode()`). |
| `lsk_live_…` | Production | Les SMS partent réellement et consomment des crédits. |

`$client->isTestMode()` indique le mode utilisé. Tous les exemples ci-dessous fonctionnent avec une clé de test.

## Envoyer des SMS

```php
$message = $client->sms->send(
    to: '+243 81 234 5678',        // +243…, 00243…, 243…, 0812…, 812… sont acceptés
    body: 'Votre commande 1042 est prête.',
    sender: 'MABOUTIQUE',          // facultatif : un Sender ID approuvé ($client->senderIds->list())
    reference: 'commande-1042',    // facultatif : votre référence, 64 caractères max.
);

$message->id;               // "9d5c0a3e-…"
$message->recipient;        // "243812345678"
$message->status;           // MessageStatus::Queued
$message->creditsCharged;   // 1
```

L'envoi est asynchrone : `202 Accepted` signifie *mis en file et débité*, pas *remis*. Suivez le statut par webhook
(`message.status_changed`, recommandé) ou en interrogeant l'API :

```php
$message = $client->sms->get($message->id);
if ($message->status->isFinal()) {
    echo $message->status->isSuccessful() ? 'Remis' : 'Échec : '.$message->errorCode;
}
```

### Coût avant envoi

1 crédit = 1 segment SMS. Un message 100 % ASCII (GSM-7) tient en 160 caractères (153 par segment au-delà). **Tout
caractère non ASCII** (é, à, ç, emoji, guillemets typographiques…) fait passer le message en UCS-2 : 70 caractères
(67 par segment au-delà), soit un coût souvent doublé. Écrire « reunion » plutôt que « réunion » peut diviser la
facture par deux.

```php
$estimation = $client->sms->estimate('Réunion demain à 9h', ['0812345678', '0899999999']);
$estimation->encoding;       // Encoding::Ucs2
$estimation->totalCredits;   // 2
$estimation->isAffordable(); // le solde suffit ?

// stripAccents : accents et caractères typographiques remplacés par de l'ASCII → GSM-7, souvent deux fois moins cher.
$client->sms->estimate('Réunion demain à 9h', '0812345678', stripAccents: true)->encoding; // Encoding::Gsm7
$client->sms->send('0812345678', 'Réunion demain à 9h', stripAccents: true);
```

Les destinataires sont comptés comme à l'envoi (normalisés, doublons fusionnés). `stripAccents` (aussi sur
`sendMany()` et `otp->send()`) n'est jamais appliqué sans le demander ; la translittération dépend du serveur
(`Café` peut devenir `Cafe` ou `Caf'e`) : ne pas en dépendre pour un texte exact.

### Plusieurs destinataires : tout ou rien

```php
use Labyrinthe\Connect\Exception\RecipientNotSendableException;

try {
    $lot = $client->sms->sendMany(['0812345678', '0899999999'], 'Réunion demain à 9h.');
    count($lot);             // un Message par numéro distinct (les doublons sont fusionnés)
} catch (RecipientNotSendableException $e) {
    // RIEN n'a été envoyé : corrigez la liste puis réessayez.
    foreach ($e->getRejected() as $refus) {
        echo $refus->recipient.' : '.$refus->reason->value; // invalid_phone, blacklisted, opted_out
    }
}
```

Jusqu'à 1000 destinataires par appel. Tous les destinataires, le Sender ID et le solde sont vérifiés **avant** le
moindre envoi : un seul numéro refusé (ou un solde insuffisant) et aucun SMS ne part. Les numéros que le SDK sait déjà
invalides sont refusés localement (`InvalidArgumentException`), sans appel réseau.

### Idempotence (pas de double envoi)

Chaque envoi porte un en-tête `Idempotency-Key`. Si vous n'en donnez pas, le SDK génère un UUID v4 et le réutilise
pour ses propres nouvelles tentatives : une erreur réseau ne produit jamais un second SMS.

Pour vous protéger aussi de **vos** reprises (jobs relancés, double clic, plantage), passez une clé tirée de votre
événement métier. Un rejeu dans les 24 h renvoie les messages d'origine sans rien renvoyer ni débiter :

```php
$lot = $client->sms->sendMany(['0812345678'], 'Votre commande 1042 est prête.', idempotencyKey: 'commande-1042-prete');
$lot->replayed;   // true s'il s'agit d'un rejeu
```

La même clé avec un autre contenu (destinataires, texte, expéditeur, référence ou `stripAccents`) est refusée :
`IdempotencyKeyReusedException` (409). Les destinataires sont comparés après normalisation : `0812345678` puis
`243812345678` avec la même clé est un rejeu. Tant que la requête d'origine est en cours, l'API répond
`409 IDEMPOTENCY_REQUEST_IN_PROGRESS` : le SDK attend `Retry-After` (5 s) et la renvoie avec la même clé.

**Résultat inconnu.** `IdempotencyKeyUnresolvedException` (409 `IDEMPOTENCY_KEY_UNRESOLVED`, `send()`, `sendMany()`
et `otp->send()`) : la requête d'origine avec cette clé a échoué de façon inattendue côté API et son résultat est
inconnu — **un SMS a pu partir**. Le SDK ne la relance jamais, et vous ne devez pas la relancer avec la même clé.
Vérifiez d'abord (`$client->sms->list(reference: …)` pour un SMS, ou demandez à l'utilisateur s'il a reçu le code),
puis, si rien n'est parti, renvoyez avec une **nouvelle** `idempotencyKey`. Journalisez `getRequestId()` pour le support.

### Lister les messages

```php
use Labyrinthe\Connect\Enum\MessageStatus;

$page = $client->sms->list(status: MessageStatus::Failed, from: '2026-11-01', to: new DateTime(), perPage: 50);

foreach ($page->autoPaging() as $message) {   // toutes les pages, chargées à la demande
    // …
}
```

Filtres : `status`, `recipient` (tout format), `reference`, `from`, `to` (une date couvre la journée), `page`,
`perPage` (100 max.). La liste ne contient que les messages envoyés **avec la clé API appelante**.

### Solde et Sender IDs

```php
$client->balance->get()->balance;      // crédits disponibles
foreach ($client->senderIds->list() as $senderId) {
    echo $senderId->value;              // noms approuvés, utilisables dans `sender`
}
```

## OTP : vérifier un numéro

Le code est généré, envoyé par SMS et vérifié par Labyrinthe Connect. Il n'est jamais renvoyé par l'API (sauf avec
une clé de test) et jamais journalisé par le SDK.

```php
// 1. Envoyer le code. Conserver otp_id CÔTÉ SERVEUR (session, cache, base) — jamais dans un champ caché.
$otp = $client->otp->send('0812345678');
$_SESSION['otp_id'] = $otp->otpId;
$otp->codeLength;          // 6 : taille du champ de saisie
$otp->codeType;            // OtpCodeType::Numeric (pavé numérique) ou OtpCodeType::Alphanumeric
$otp->resendAfterSeconds;  // compte à rebours avant « Renvoyer le code »

// 2. L'utilisateur saisit le code reçu par SMS.

// 3. Vérifier.
$resultat = $client->otp->verify($_SESSION['otp_id'], $_POST['code']);
if ($resultat->isValid()) {
    unset($_SESSION['otp_id']);
    // connecter l'utilisateur / marquer le numéro comme vérifié
} else {
    match ($resultat->reason) {
        OtpFailureReason::InvalidCode => "Code incorrect, encore {$resultat->attemptsRemaining} essai(s).",  // une tentative consommée
        OtpFailureReason::Expired, OtpFailureReason::AlreadyUsed,
        OtpFailureReason::MaxAttemptsReached => 'Demandez un nouveau code.',
        default => 'Vérification impossible.',
    };
}
```

- Un code faux n'est **pas** une exception : `verify()` renvoie `verified: false` et un motif.
- Un nouvel envoi au même numéro invalide le code précédent.
- Longueur, validité, tentatives, Sender ID et modèle viennent des paramètres OTP du compte (espace client >
  *Vérification OTP*). Vous pouvez imposer le modèle (`template: 'Votre code MaBoutique : {code}'`, qui doit contenir
  `{code}` ; jetons facultatifs `{app_name}`, `{minutes}`) et la langue (`locale: 'fr'|'en'`).
- `attemptsRemaining` : tentatives restantes après `invalid_code`, `0` avec `max_attempts_reached`, `null` sinon.
- Limites : `OtpResendTooSoonException` (attendre `getRetryAfter()` secondes avant de renvoyer à ce numéro ; un renvoi
  est accepté tout de suite une fois le code vérifié) et `OtpRateLimitedException` (plafond horaire par numéro ou par
  compte, `getScope()`, `getLimit()`, `getRetryAfter()`). Les deux lisent l'en-tête `Retry-After`.
- **Web OTP API** (remplissage automatique sous Chrome / Android) : `webOtpDomain: 'app.exemple.com'` ajoute la
  dernière ligne `@app.exemple.com #123456` au SMS ; à lire dans le navigateur avec
  `navigator.credentials.get({ otp: { transport: ['sms'] } })`. `stripAccents: true` garde le message en GSM-7.
- **Mode test** : avec une clé `lsk_test_`, aucun SMS ne part et `$otp->testCode()` renvoie le code pour tester tout le
  parcours. Toujours `null` avec une clé live, masqué dans `var_dump()` et `json_encode()`.

**Idempotence.** `otp->send()` porte toujours une `Idempotency-Key` (un UUID v4 généré, ou la vôtre), réutilisée par
chaque relance : une erreur réseau, une 5xx ou `IDEMPOTENCY_REQUEST_IN_PROGRESS` est relancée sans second SMS, et le
rejeu renvoie le même `otpId` (`$otp->replayed === true`, et le même `testCode()` en mode test). Votre propre clé
doit désigner **un** envoi (ex. `"inscription-{$tentativeId}"`) : réutilisée pendant 24 h, elle renvoie le même code,
jamais un nouveau.

```php
$otp = $client->otp->send($telephone, idempotencyKey: "inscription-{$tentativeId}", webOtpDomain: 'app.exemple.com');
$otp->replayed;        // true si l'API a rejoué un envoi précédent (ni nouveau SMS, ni nouveau débit)
```

`otp->verify()` n'est jamais relancé après une erreur réseau ou une 5xx (une tentative a pu être consommée) :
affichez une erreur et laissez l'utilisateur ressaisir le code.

## Webhooks

Déclarez un endpoint dans l'espace client (*Développeurs > Webhooks*) et copiez son secret. Les événements arrivent
en `POST` JSON, signés (`X-Signature: sha256=<HMAC-SHA256 du corps brut>`).

```php
use Labyrinthe\Connect\Exception\InvalidWebhookPayloadException;
use Labyrinthe\Connect\Exception\SignatureVerificationException;
use Labyrinthe\Connect\Webhook\Event\BalanceLow;
use Labyrinthe\Connect\Webhook\Event\CampaignCompleted;
use Labyrinthe\Connect\Webhook\Event\MessageStatusChanged;
use Labyrinthe\Connect\Webhook\Event\OtpFailed;
use Labyrinthe\Connect\Webhook\Event\OtpVerified;
use Labyrinthe\Connect\Webhook\Webhook;

$corpsBrut = file_get_contents('php://input');        // le corps BRUT : ne jamais ré-encoder un tableau décodé

try {
    $event = Webhook::constructEvent($corpsBrut, $_SERVER['HTTP_X_SIGNATURE'] ?? '', getenv('LABYRINTHE_WEBHOOK_SECRET'));
} catch (SignatureVerificationException | InvalidWebhookPayloadException) {
    http_response_code(400);
    exit;
}

if (deja_traite($event->id())) {                       // une livraison peut se répéter : dédoublonner sur id
    http_response_code(200);
    exit;
}

match (true) {
    $event instanceof MessageStatusChanged => maj_message($event->messageId, $event->status),
    $event instanceof CampaignCompleted => prevenir_equipe($event->name, $event->countFor(MessageStatus::Delivered)),
    $event instanceof BalanceLow => acheter_credits($event->balance, $event->threshold),
    $event instanceof OtpVerified => marquer_verifie($event->otpId, $event->phone, $event->verifiedAt),
    $event instanceof OtpFailed => alerter_securite($event->otpId, $event->status, $event->reason),
    default => null,                                    // TestPing, ou UnknownEvent pour un type futur
};

http_response_code(200);                                // répondre 2xx sous 10 s ; traitements longs en file
```

- La signature est vérifiée en temps constant, puis le `timestamp` signé doit dater de moins de 5 minutes
  (4e argument ; `0` désactive le contrôle) contre le rejeu.
- Une livraison échouée est retentée jusqu'à 5 fois (30 s, 2 min, 5 min, 15 min) : le même `id` peut arriver plusieurs
  fois, et l'ordre n'est pas garanti.
- Un type d'événement ajouté plus tard arrive en `UnknownEvent` (vérifié, jamais rejeté) : répondez 2xx.
- `MessageStatusChanged` porte `reference` (passée à l'envoi), `errorCode` et `errorMessage` (en cas d'échec).
- `OtpVerified` / `OtpFailed` (`otp.verified`, `otp.failed`) sont émis une fois par vérification, jamais avec le code :
  `otpId`, `phone`, `status` (`OtpWebhookStatus::Verified`, `Failed` ou `Expired`), `reason` (`null`,
  `OtpFailureReason::MaxAttemptsReached` ou `Expired`) et `verifiedAt`.
- `Webhook::sign($corps, $secret)` construit un en-tête valide pour tester votre endpoint.

## Erreurs

Toutes les exceptions implémentent `Labyrinthe\Connect\Exception\LabyrintheException`. Les erreurs de l'API héritent
d'`ApiException` et exposent `getErrorCode()` (stable, pour votre code), `getMessage()` (en français, pour les
humains), `getStatusCode()`, `getDetails()`, `getRequestId()` (l'en-tête `X-Request-Id`, à donner au support) et
`getApiVersion()`.

| Exception | Cas |
|---|---|
| `AuthenticationException` | 401 — clé absente, invalide ou révoquée |
| `PermissionException` | 403 — IP non autorisée (`FORBIDDEN`), OTP désactivé pour le compte (`OTP_DISABLED_FOR_ACCOUNT`) |
| `AccountSuspendedException` | 403 `ACCOUNT_SUSPENDED` — hérite de `PermissionException` ; toute l'API est fermée jusqu'à réactivation |
| `NotFoundException` | 404 — message ou `otp_id` inconnu |
| `ValidationException` | 422 `VALIDATION_ERROR` — `getErrors()` / `getFieldErrors('body')` |
| `RecipientNotSendableException` | 422 — `getRejected()`, `getReason()` ; rien n'a été envoyé |
| `SenderIdNotApprovedException` | 422 — `sender` n'est pas un Sender ID approuvé |
| `InvalidPhoneException` | 422 `INVALID_PHONE` (OTP) |
| `InsufficientCreditsException` | 409 — `getAvailable()`, `getRequired()` ; rien n'a été envoyé |
| `IdempotencyKeyReusedException` | 409 — même clé, requête différente |
| `IdempotencyRequestInProgressException` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` — déjà relancée par le SDK ; `getRetryAfter()` |
| `IdempotencyKeyUnresolvedException` | 409 `IDEMPOTENCY_KEY_UNRESOLVED` — résultat inconnu (un SMS a pu partir) ; jamais relancée : vérifier, puis renvoyer avec une nouvelle clé |
| `RateLimitException` | 429 `TOO_MANY_REQUESTS` — `getRetryAfter()` |
| `OtpResendTooSoonException` | 429 — hérite de `RateLimitException` |
| `OtpRateLimitedException` | 429 — hérite de `RateLimitException`, `getScope()`, `getLimit()` |
| `ServiceUnavailableException` | 5xx, dont 503 `OTP_SERVICE_DISABLED` |
| `ApiException` | tout autre code (liste ouverte : de nouveaux codes peuvent apparaître) |
| `ConnectionException` | erreur réseau ou délai dépassé (`isTimeout()`) |
| `InvalidArgumentException` | saisie invalide détectée par le SDK, avant tout appel |

## Nouvelles tentatives et délais

Le SDK relance automatiquement (2 fois par défaut, attente exponentielle 0,5 s → 1 s → 2 s avec gigue) **uniquement
quand un rejeu ne peut pas provoquer deux fois le même effet** :

| Requête | Erreur réseau / délai | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` |
|---|---|---|---|---|
| `GET` (get, list, solde, Sender IDs), `estimate()` | relancée | relancée | relancée après `Retry-After` | — |
| `send()` / `sendMany()` (toujours avec `Idempotency-Key`) | relancée, même clé | relancée, même clé | relancée après `Retry-After` | relancée après `Retry-After`, même clé |
| `otp->send()` (toujours avec `Idempotency-Key`, depuis 1.1.0) | relancée, même clé | relancée, même clé | relancée après `Retry-After` | relancée après `Retry-After`, même clé |
| `otp->verify()` | **jamais** | **jamais** | relancée (requête refusée avant traitement) | — |

Les erreurs métier (4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, `ACCOUNT_SUSPENDED`, `IDEMPOTENCY_KEY_UNRESOLVED`)
ne sont jamais relancées.
Si l'API demande d'attendre plus que `maxRetryWait` (10 s par défaut), le SDK lève l'exception (`RateLimitException`,
`IdempotencyRequestInProgressException`) au lieu de bloquer.

**Identifiant de requête.** Chaque réponse porte `X-Request-Id`. Passez le vôtre avec `requestId:` sur n'importe quel
appel (1 à 128 caractères parmi `A-Z a-z 0-9 . _ : -`, renvoyé à chaque relance) pour relier vos journaux à ceux de
l'API ; lisez-le sur toute exception avec `getRequestId()`, ou sur la dernière réponse avec
`$client->lastResponse()?->requestId()` (`lastResponse()` donne aussi le statut, `apiVersion()`, `isReplayed()`,
`rateLimitRemaining()` et tous les en-têtes).

```php
try {
    $client->sms->send('0812345678', 'Bonjour', requestId: 'commande-1042');
} catch (ApiException $e) {
    journaliser($e->getErrorCode(), $e->getRequestId()); // "commande-1042"
}
```

## Configuration

```php
$client = new Client(
    apiKey: getenv('LABYRINTHE_API_KEY'),
    baseUrl: 'https://api.labyrinthe-rdc.com/api/public/v1', // défaut ; "http://localhost:8000" est complété
    timeout: 30.0,                 // secondes (client Guzzle par défaut)
    connectTimeout: 10.0,
    maxRetries: 2,                 // 0 désactive les relances
    maxRetryWait: 10.0,            // Retry-After maximal attendu par le SDK
    defaultCountryCode: '243',     // ajouté aux numéros locaux : 0812345678 → 243812345678
    defaultSender: 'MABOUTIQUE',   // utilisé quand send() ne reçoit pas de sender
    httpClient: $clientPsr18,      // n'importe quel client PSR-18 (réglez ses délais vous-même)
    requestFactory: $fabriquePsr17,
    streamFactory: $fabriquePsr17,
    logger: $loggerPsr3,           // journaux debug/warning, numéros masqués, ni clé, ni code, ni corps
);

$client = Client::fromEnv();       // LABYRINTHE_API_KEY, LABYRINTHE_BASE_URL facultatif
```

Numéros : `Labyrinthe\Connect\PhoneNumber::normalize('0812345678')` renvoie `"243812345678"` (ou `null`) avec les mêmes
règles que l'API ; `isValid()` et `mask()` sont aussi disponibles. Les numéros internationaux (`+33…`, `+1…`) ne sont
jamais bloqués. Sans `+`, 11 chiffres ou plus ne commençant pas par 0 sont déjà internationaux (`33612345678` reste
`33612345678`) ; un numéro étranger de 10 chiffres ou moins exige le `+`. Les requêtes portent le numéro au format
E.164 avec `+` (`PhoneNumber::toE164('0812345678')` → `"+243812345678"`) : `+2481234567` garde son indicatif. Chaque objet de réponse garde le JSON décodé
dans `raw()`.

Les dates de l'API (`2026-11-06T09:39:11+00:00` ; `Z` et microsecondes acceptés aussi) sont lues en
`DateTimeImmutable`, et `list()` envoie ses filtres `DateTimeInterface` au même format.

## Développement

```bash
composer install
composer test          # tests unitaires + contrat OpenAPI (sans réseau)
composer analyse       # PHPStan niveau max
composer lint          # PHP-CS-Fixer (vérification) ; `composer format` pour corriger

# tests d'intégration contre un backend local, avec une clé de TEST uniquement
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 composer test:integration
```

## Licence

MIT — voir [LICENSE](LICENSE).

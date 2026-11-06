# Labyrinthe Connect — SDK PHP

[![Packagist](https://img.shields.io/packagist/v/labyrinthe/connect-php.svg)](https://packagist.org/packages/labyrinthe/connect-php)
[![CI](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/php.yml/badge.svg)](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/php.yml)
[![Licence : MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)
![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.1-777bb4)

Client PHP officiel de [Labyrinthe Connect](https://labyrinthe-rdc.com), la plateforme SMS et OTP de la RD Congo et
de l'Afrique centrale : envoyer des SMS, consulter le solde et les Sender IDs, vérifier des numéros de téléphone avec
un code à usage unique (OTP) et recevoir des webhooks signés. Réponses et exceptions typées, nouvelles tentatives
automatiques **uniquement** quand elles ne peuvent pas envoyer un SMS deux fois, Guzzle par défaut ou n'importe quel
client PSR-18, et ni la clé API, ni les codes OTP, ni les numéros complets n'atteignent les journaux ou les dumps.
SDK 1.2.0, construit sur la spécification de l'API 1.3.0.

*English version: [README.md](README.md). Laravel ? Utilisez [`labyrinthe/connect-laravel`](../laravel).
WordPress ? Utilisez le [plugin](../wordpress).*

[Prérequis](#prérequis) · [Installation](#installation) · [Démarrage rapide](#démarrage-rapide) ·
[Configuration](#configuration) · [Envoyer des SMS](#envoyer-des-sms) · [Solde et Sender IDs](#solde-et-sender-ids) ·
[OTP](#otp--vérifier-un-numéro) · [Webhooks](#webhooks) · [Erreurs](#erreurs) ·
[Nouvelles tentatives et idempotence](#nouvelles-tentatives-et-idempotence) ·
[Mode test, journaux et sécurité](#mode-test-journaux-et-sécurité) · [Sans le SDK](#sans-le-sdk-http-brut) ·
[Mise à jour](#mise-à-jour)

## Prérequis

| | |
|---|---|
| PHP | 8.1 ou plus, avec les extensions `json` et `mbstring` |
| HTTP | `guzzlehttp/guzzle` ^7.5 (installé et utilisé par défaut), ou tout client PSR-18 avec des fabriques PSR-17 |
| Journaux | facultatif, tout logger PSR-3 (`psr/log` ^2 ou ^3) |
| API | API publique Labyrinthe Connect v1 (spécification 1.3.0) |

## Installation

```bash
composer require labyrinthe/connect-php
```

Créez une clé API dans l'espace client (*Développeurs > Clés API*). **Gardez-la côté serveur** : jamais dans un
navigateur, une application mobile ou un dépôt Git.

## Démarrage rapide

```php
use Labyrinthe\Connect\Client;

$client = new Client('lsk_test_…');                    // clé de test : rien n'est envoyé, rien n'est facturé
$message = $client->sms->send(to: '0812345678', body: 'Votre commande 1042 est prete.');
echo $message->status->value;                           // "queued"
```

## Configuration

```php
$client = new Client(
    apiKey: getenv('LABYRINTHE_API_KEY'),
    baseUrl: null,                 // par défaut https://api.labyrinthe-rdc.com/api/public/v1
    timeout: 30.0,
    connectTimeout: 10.0,
    maxRetries: 2,
    maxRetryWait: 10.0,
    defaultCountryCode: '243',
    defaultSender: 'MABOUTIQUE',
    httpClient: $clientPsr18,
    requestFactory: $fabriquePsr17,
    streamFactory: $fabriquePsr17,
    logger: $loggerPsr3,
);

$client = Client::fromEnv();       // LABYRINTHE_API_KEY, et LABYRINTHE_BASE_URL si elle est définie
```

| Option | Défaut | Rôle |
|---|---|---|
| `apiKey` | — (obligatoire) | `lsk_test_…` (bac à sable) ou `lsk_live_…` (production) |
| `baseUrl` | `https://api.labyrinthe-rdc.com/api/public/v1` | racine de l'API ; un hôte seul comme `http://localhost:8000` est complété par `/api/public/v1` |
| `timeout` / `connectTimeout` | `30.0` / `10.0` s | délais du client Guzzle par défaut (à régler vous-même sur votre propre client PSR-18) |
| `maxRetries` | `2` | nouvelles tentatives automatiques quand elles sont sans risque (0 à 10 ; `0` les désactive) |
| `maxRetryWait` | `10.0` s | plus longue attente (`Retry-After` ou backoff) acceptée par le SDK ; au-delà, l'exception est levée |
| `defaultCountryCode` | `243` | ajouté aux numéros locaux : `0812345678` → `243812345678` |
| `defaultSender` | `null` | Sender ID utilisé quand `send()` / `sendMany()` n'en reçoivent pas (sinon celui du compte) |
| `httpClient`, `requestFactory`, `streamFactory` | Guzzle | tout client PSR-18 et toutes fabriques PSR-17 |
| `logger` | aucun | logger PSR-3 (voir [journaux](#mode-test-journaux-et-sécurité)) |
| `userAgentSuffix` | `null` | ajouté à `User-Agent: labyrinthe-php/1.2.0 PHP/x.y` (pour les intégrations) |

| Variable d'environnement | Lue par | |
|---|---|---|
| `LABYRINTHE_API_KEY` | `Client::fromEnv()` | obligatoire |
| `LABYRINTHE_BASE_URL` | `Client::fromEnv()` | facultative, par ex. `http://localhost:8000` pour un backend local |

`Client::fromEnv()` accepte aussi `timeout`, `maxRetries`, `defaultCountryCode`, `defaultSender`, `httpClient` et
`logger`.

**Numéros de téléphone.** `+243…`, `00243…`, `243…`, `0812…` et `812…` sont acceptés ; espaces, tirets et
parenthèses sont ignorés. Les numéros internationaux (`+33…`, `+1…`) ne sont jamais bloqués. Sans `+`, 11 chiffres
ou plus ne commençant pas par 0 sont déjà internationaux (`33612345678`) ; un numéro étranger de 10 chiffres ou moins
doit garder son `+`. Utilitaires aux mêmes règles que l'API : `PhoneNumber::normalize('0812345678')` →
`"243812345678"` (ou `null`), `PhoneNumber::toE164()` → `"+243812345678"` (le format envoyé à l'API),
`PhoneNumber::isValid()`, `PhoneNumber::mask()`.

**Les dates** sont lues au format de l'API (`2026-11-06T09:39:11+00:00`) en `DateTimeImmutable`. Chaque objet de
réponse garde le JSON décodé dans `raw()`.

## Envoyer des SMS

### Un destinataire

```php
$message = $client->sms->send(
    to: '+243 81 234 5678',
    body: 'Votre commande 1042 est prete.',
    sender: 'MABOUTIQUE',          // facultatif : un Sender ID approuvé
    reference: 'order-1042',       // facultatif : votre référence, 64 caractères max., filtrable
    stripAccents: false,           // facultatif : accents → ASCII (voir plus bas)
    idempotencyKey: null,          // facultatif : générée si absente (voir Nouvelles tentatives et idempotence)
);

$message->id;               // "9d5c0a3e-…"
$message->recipient;        // "243812345678"
$message->status;           // MessageStatus::Queued
$message->segments;         // 1
$message->creditsCharged;   // 1
```

L'envoi est asynchrone : `202 Accepted` signifie *mis en file et payé*, pas *remis*. Suivez le statut avec le webhook
`message.status_changed` (recommandé) ou en interrogeant `get()`. Un texte compte de 1 à 1600 caractères.

### Plusieurs destinataires : tout ou rien

```php
use Labyrinthe\Connect\Exception\RecipientNotSendableException;

try {
    $batch = $client->sms->sendMany(['0812345678', '0899999999'], 'Reunion demain a 9h.');
    count($batch);          // un Message par numéro distinct (doublons fusionnés après normalisation)
    $batch->first();        // ?Message
} catch (RecipientNotSendableException $e) {
    // RIEN n'a été envoyé : corrigez la liste et recommencez.
    foreach ($e->getRejected() as $rejected) {
        echo $rejected->recipient.' : '.$rejected->reason->value; // invalid_phone, blacklisted, opted_out
    }
}
```

Jusqu'à 1000 destinataires par appel. Chaque destinataire, le Sender ID et le solde sont vérifiés **avant** tout
envoi : un seul numéro refusé ou un solde insuffisant, et aucun SMS ne part. Les numéros que le SDK sait déjà
invalides sont refusés localement (`InvalidArgumentException`), avant tout appel réseau.

### Coût avant l'envoi, `stripAccents`

1 crédit = 1 segment SMS. Un message en ASCII pur (GSM-7) tient en 160 caractères (153 par segment au-delà).
**Tout caractère non ASCII** (é, à, ç, emoji, guillemets typographiques…) passe en UCS-2 : 70 caractères (67 par
segment au-delà), soit un coût souvent doublé.

```php
$estimate = $client->sms->estimate('Réunion demain à 9h', ['0812345678', '0899999999']);
$estimate->encoding;           // Encoding::Ucs2
$estimate->characters;         // 19
$estimate->segmentsPerMessage; // 1
$estimate->recipientsCount;    // 2 (normalisés, doublons fusionnés, invalides ignorés)
$estimate->totalCredits;       // 2
$estimate->balanceAfter;       // ?int
$estimate->isAffordable();     // le solde suffit-il ?

$client->sms->estimate('Réunion demain à 9h', '0812345678', stripAccents: true)->encoding; // Encoding::Gsm7
```

`estimate()` n'envoie rien et est gratuit. `stripAccents: true` (sur `send()`, `sendMany()`, `estimate()` et
`otp->send()`) demande à l'API de remplacer accents et caractères typographiques par leur équivalent ASCII. Ce n'est
jamais appliqué sans demande explicite, et la translittération dépend du serveur (`Café` peut devenir `Cafe`) : ne
comptez pas dessus pour un texte exact.

### Lire un message, lister les messages

```php
use Labyrinthe\Connect\Enum\MessageStatus;

$message = $client->sms->get($message->id);
if ($message->status->isFinal()) {
    echo $message->status->isSuccessful() ? 'Remis' : 'Échec : '.$message->errorCode;
}

$page = $client->sms->list(
    status: MessageStatus::Failed,  // ou 'failed'
    recipient: '0812345678',        // tous formats
    reference: 'order-1042',        // correspondance exacte
    from: '2026-11-01',             // une date couvre toute la journée ; DateTimeInterface accepté
    to: new DateTimeImmutable(),
    page: 1,
    perPage: 50,                    // 1 à 100, 15 par défaut
);

$page->total; $page->currentPage; $page->lastPage; $page->hasMore();
$next = $page->nextPage();          // ?Page

foreach ($page->autoPaging() as $message) {   // cette page et toutes les suivantes, chargées à la demande
    // …
}
```

`get()` retrouve tout message du compte par son `id`. `list()` ne renvoie que les messages envoyés **avec la clé API
appelante**, du plus récent au plus ancien. Statuts (`MessageStatus`) : `queued`, `submitted`, `sent`, `delivered`,
`failed`, `rejected`, `expired`, `undeliverable`, et `unknown` pour une valeur ajoutée plus tard.

## Solde et Sender IDs

```php
$balance = $client->balance->get();
$balance->balance;                        // crédits disponibles
$balance->held;                           // crédits réservés par les campagnes en cours

foreach ($client->senderIds->list() as $senderId) {
    echo $senderId->value;                // nom approuvé, utilisable comme `sender`
    $senderId->isDefault;                 // Sender ID par défaut du compte ?
}
```

Seuls les Sender IDs approuvés sont listés. Demandez-en de nouveaux dans l'espace client ; un `sender` non approuvé
est refusé avec `SenderIdNotApprovedException`.

## OTP : vérifier un numéro

Labyrinthe Connect génère le code, l'envoie par SMS (facturé comme un SMS) et le vérifie (gratuit). Le code n'est
jamais renvoyé par l'API en production et jamais journalisé par le SDK.

```php
use Labyrinthe\Connect\Enum\OtpFailureReason;

// 1. Envoyer le code. Garder otp_id CÔTÉ SERVEUR (session, cache, base), jamais dans un champ de formulaire.
$otp = $client->otp->send('0812345678');
$_SESSION['otp_id'] = $otp->otpId;
$otp->expiresAt;           // DateTimeImmutable
$otp->maxAttempts;         // 5
$otp->codeLength;          // 6 : taille du champ
$otp->codeType;            // OtpCodeType::Numeric (clavier numérique) ou OtpCodeType::Alphanumeric
$otp->resendAfterSeconds;  // compte à rebours avant « Renvoyer le code » (aussi resendAvailableAt())
$otp->testCode();          // le code avec une clé lsk_test_, toujours null en production

// 2. L'utilisateur saisit le code reçu par SMS.

// 3. Le vérifier.
$result = $client->otp->verify($_SESSION['otp_id'], $_POST['code']);
if ($result->isValid()) {
    unset($_SESSION['otp_id']);
    // connecter l'utilisateur / marquer le numéro comme vérifié
} else {
    $error = match ($result->reason) {
        OtpFailureReason::InvalidCode => "Code incorrect, {$result->attemptsRemaining} essai(s) restant(s).",
        OtpFailureReason::Expired, OtpFailureReason::AlreadyUsed,
        OtpFailureReason::MaxAttemptsReached => 'Demandez un nouveau code.',
        default => 'Le code n\'a pas pu être vérifié.',
    };
}
```

| Paramètre de `otp->send()` | |
|---|---|
| `phone` | tout format accepté |
| `template` | votre message, doit contenir `{code}` ; `{app_name}`, `{minutes}` facultatifs (par défaut : le modèle OTP du compte) |
| `locale` | `'fr'` ou `'en'`, langue du modèle standard |
| `webOtpDomain` | nom d'hôte comme `'app.exemple.com'` : ajoute la dernière ligne `@app.exemple.com #123456` pour que Chrome / Android remplisse le code (`navigator.credentials.get({ otp: { transport: ['sms'] } })`) |
| `stripAccents` | garder le message en GSM-7 |
| `idempotencyKey`, `requestId` | voir [Nouvelles tentatives et idempotence](#nouvelles-tentatives-et-idempotence) |

- Un code faux n'est **pas** une exception : `verify()` renvoie `verified: false` avec un motif `reason`
  (`invalid_code`, `expired`, `already_used`, `max_attempts_reached`) ; `$result->reason?->canRetry()` indique si
  l'utilisateur peut réessayer.
- `attemptsRemaining` : essais restants après `invalid_code`, `0` avec `max_attempts_reached`, `null` sinon.
- Longueur et alphabet du code, validité, nombre d'essais, délai de renvoi, Sender ID et modèle par défaut viennent
  des paramètres OTP du compte (espace client > *Vérification OTP*) ; `codeLength`, `codeType` et
  `resendAfterSeconds` les reflètent.
- Un nouvel `send()` au même numéro invalide le code précédent. Un renvoi est accepté tout de suite après une
  vérification réussie.
- **Limites** : 10 envois / minute par clé API (`RateLimitException`) ; un délai minimal entre deux envois au même
  numéro (`OtpResendTooSoonException`, `getRetryAfter()`) ; un plafond horaire par numéro et par compte
  (`OtpRateLimitedException`, `getScope()` = `OtpRateLimitScope::Phone` ou `Account`, `getLimit()`,
  `getRetryAfter()`).
- **Mode test** : avec une clé `lsk_test_`, aucun SMS ne part et `$otp->testCode()` renvoie le code, pour tester tout
  le parcours. Il est masqué dans `var_dump()` et `json_encode()`.

Un parcours de connexion complet (deux points d'entrée, session PHP) est dans
[`examples/otp-login.php`](examples/otp-login.php).

## Webhooks

Ajoutez un point d'entrée dans l'espace client (*Développeurs > Webhooks*) et copiez son secret. Les événements sont
envoyés en `POST` JSON `{ id, event, data, timestamp }`, signés par `X-Signature: sha256=<HMAC-SHA256 du corps brut>`.

```php
use Labyrinthe\Connect\Enum\MessageStatus;
use Labyrinthe\Connect\Exception\InvalidWebhookPayloadException;
use Labyrinthe\Connect\Exception\SignatureVerificationException;
use Labyrinthe\Connect\Webhook\Event\BalanceLow;
use Labyrinthe\Connect\Webhook\Event\CampaignCompleted;
use Labyrinthe\Connect\Webhook\Event\MessageStatusChanged;
use Labyrinthe\Connect\Webhook\Event\OtpFailed;
use Labyrinthe\Connect\Webhook\Event\OtpVerified;
use Labyrinthe\Connect\Webhook\Webhook;

$rawBody = file_get_contents('php://input');          // le corps BRUT : ne jamais réencoder un tableau décodé

try {
    $event = Webhook::constructEvent($rawBody, $_SERVER['HTTP_X_SIGNATURE'] ?? '', getenv('LABYRINTHE_WEBHOOK_SECRET'));
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
    $event instanceof OtpFailed => alerte_securite($event->otpId, $event->status, $event->reason),
    default => null,                                    // TestPing, ou UnknownEvent pour un type futur
};

http_response_code(200);                                // répondre 2xx sous 10 s ; mettre le travail lent en file
```

| Événement | Classe | Propriétés principales |
|---|---|---|
| `message.status_changed` | `MessageStatusChanged` | `messageId`, `recipient`, `status`, `providerMessageId`, `campaignId`, `reference`, `errorCode`, `errorMessage` |
| `campaign.completed` | `CampaignCompleted` | `campaignId`, `name`, `recipientsCount`, `messagesByStatus`, `countFor()`, `creditsCharged`, `completedAt` |
| `balance.low` | `BalanceLow` | `balance`, `threshold` |
| `otp.verified` | `OtpVerified` | `otpId`, `phone`, `status` (`OtpWebhookStatus::Verified`), `verifiedAt` |
| `otp.failed` | `OtpFailed` | `otpId`, `phone`, `status` (`Failed` ou `Expired`), `reason` (`MaxAttemptsReached`, `Expired` ou `null`) |
| `test.ping` | `TestPing` | — |
| un type ajouté plus tard | `UnknownEvent` | `data()` ; vérifié, jamais rejeté : l'acquitter par un 2xx |

Chaque événement offre `id()`, `type()`, `eventType()` (`?WebhookEvent`), `timestamp()` et `raw()`.

- La signature est vérifiée en temps constant sur le corps brut, puis l'horodatage signé (`timestamp`) doit dater de
  moins de 5 minutes (4ᵉ argument `toleranceSeconds` ; `0` désactive le contrôle) pour bloquer les rejeux.
  `Webhook::verifySignature()` ne vérifie que la signature ; `Webhook::sign($body, $secret)` fabrique un en-tête
  valide pour tester votre point d'entrée.
- Une livraison en échec est retentée jusqu'à 5 fois : le même `id` peut arriver plusieurs fois et l'ordre n'est pas
  garanti. Conservez les `id` traités (24 h suffisent) et répondez 2xx aux doublons.
- Les événements OTP ne contiennent jamais le code.

Exemples par framework : PHP natif dans [`examples/webhook-receiver.php`](examples/webhook-receiver.php) ; Laravel a
une route et des événements prêts à l'emploi avec [`labyrinthe/connect-laravel`](../laravel). Avec Symfony, passez
`$request->getContent()` (corps brut) et `$request->headers->get('X-Signature', '')` à `Webhook::constructEvent()`.

## Erreurs

Toutes les exceptions implémentent `Labyrinthe\Connect\Exception\LabyrintheException`. Les erreurs de l'API héritent
de `ApiException` : `getErrorCode()` (stable, pour votre code), `getMessage()` (en français, pour les humains),
`getStatusCode()`, `getDetails()`, `getRequestId()` (le `X-Request-Id`, pour le support), `getApiVersion()` et
`getHeader()`.

| Exception | HTTP | Code | Que faire |
|---|---|---|---|
| `AuthenticationException` | 401 | `UNAUTHENTICATED` | vérifier la clé (absente, invalide ou révoquée) |
| `PermissionException` | 403 | `FORBIDDEN`, `OTP_DISABLED_FOR_ACCOUNT` | IP non autorisée pour cette clé, ou OTP désactivé pour le compte |
| `AccountSuspendedException` | 403 | `ACCOUNT_SUSPENDED` | toute l'API est fermée jusqu'à réactivation : contacter le support (hérite de `PermissionException`) |
| `NotFoundException` | 404 | `NOT_FOUND` | identifiant de message ou `otp_id` inconnu |
| `ValidationException` | 422 | `VALIDATION_ERROR` | corriger la saisie : `getErrors()`, `getFieldErrors('body')` |
| `RecipientNotSendableException` | 422 | `RECIPIENT_NOT_SENDABLE` | rien n'est parti : `getRejected()`, `getReason()` |
| `SenderIdNotApprovedException` | 422 | `SENDER_ID_NOT_APPROVED` | utiliser un Sender ID approuvé (`senderIds->list()`) |
| `InvalidPhoneException` | 422 | `INVALID_PHONE` | OTP : demander un autre numéro |
| `InsufficientCreditsException` | 409 | `INSUFFICIENT_CREDITS` | rien n'est parti : acheter des crédits ; `getAvailable()`, `getRequired()` (jamais en mode test) |
| `IdempotencyKeyReusedException` | 409 | `IDEMPOTENCY_KEY_REUSED` | même clé, contenu différent : utiliser une nouvelle clé |
| `IdempotencyRequestInProgressException` | 409 | `IDEMPOTENCY_REQUEST_IN_PROGRESS` | déjà relancée par le SDK ; réessayer plus tard avec la même clé (`getRetryAfter()`) |
| `IdempotencyKeyUnresolvedException` | 409 | `IDEMPOTENCY_KEY_UNRESOLVED` | résultat inconnu, un SMS a pu partir : vérifier (`sms->list(reference: …)`), puis envoyer avec une **nouvelle** clé |
| `RateLimitException` | 429 | `TOO_MANY_REQUESTS` | ralentir ; `getRetryAfter()` |
| `OtpResendTooSoonException` | 429 | `OTP_RESEND_TOO_SOON` | afficher un compte à rebours de `getRetryAfter()` secondes (hérite de `RateLimitException`) |
| `OtpRateLimitedException` | 429 | `OTP_RATE_LIMITED` | plafond horaire : `getScope()`, `getLimit()`, `getRetryAfter()` (hérite de `RateLimitException`) |
| `FeatureDisabledException` | 503 | `FEATURE_DISABLED`, `OTP_SERVICE_DISABLED` | maintenance, rien n'a été fait : [voir plus bas](#maintenance-503-feature_disabled) |
| `ServiceUnavailableException` | 5xx | tous | erreur serveur temporaire (déjà relancée quand c'est sans risque) |
| `ApiException` | tous | tout autre code (ex. `INVALID_OTP_TEMPLATE`, `HTTP_ERROR`) | la liste est ouverte : traiter un code inconnu de façon générique |
| `ConnectionException` | — | `CONNECTION_ERROR`, `TIMEOUT` | erreur réseau ou délai dépassé (`isTimeout()`) |
| `InvalidArgumentException` | — | — | saisie invalide détectée par le SDK, avant tout appel |
| `SignatureVerificationException`, `InvalidWebhookPayloadException` | — | — | webhook refusé : répondre 400 |

```php
use Labyrinthe\Connect\Exception\ApiException;
use Labyrinthe\Connect\Exception\ConnectionException;
use Labyrinthe\Connect\Exception\InsufficientCreditsException;

try {
    $client->sms->send('0812345678', 'Bonjour');
} catch (InsufficientCreditsException $e) {
    alerter_finance($e->getAvailable(), $e->getRequired());
} catch (ApiException $e) {
    journaliser($e->getErrorCode(), $e->getStatusCode(), $e->getRequestId());
} catch (ConnectionException $e) {
    journaliser($e->getErrorCode(), $e->isTimeout());
}
```

### Maintenance (503 `FEATURE_DISABLED`)

Un administrateur de la plateforme peut couper temporairement toute l'API (`api`) ou une fonction (`api.sms_send`,
`api.otp_send`, `api.otp_verify` ; `otp` pour tout le service OTP, code `OTP_SERVICE_DISABLED`). **Rien n'a été
fait** : aucun SMS envoyé, aucun crédit débité. `FeatureDisabledException` hérite de `ServiceUnavailableException` :
les `catch` existants continuent de fonctionner.

| Accesseur | |
|---|---|
| `getFeature()` | fonction demandée par l'appel (`api.sms_send`…), ou `null` |
| `getBlockedBy()` | fonction réellement coupée (elle-même ou une parente, ex. `api`), ou `null` |
| `getAdminMessage()` | message de l'administrateur (en français), ou `null` ; `getMessage()` le reprend ou donne un texte générique |
| `getUntil()` | réactivation programmée (`DateTimeImmutable`), ou `null` si inconnue |
| `getRetryAfter()` | secondes avant cette réactivation (`Retry-After`), ou `null` |

Le SDK ne la relance que si l'API envoie un `Retry-After` inférieur ou égal à `maxRetryWait`, après exactement ce
délai. Sinon, ne relancez pas en boucle : mettez le travail en file ou prévenez l'utilisateur.

```php
use Labyrinthe\Connect\Exception\FeatureDisabledException;

try {
    $client->sms->send('0812345678', 'Votre commande est expediee');
} catch (FeatureDisabledException $e) {
    $delai = $e->getRetryAfter() ?? 900;          // retour inconnu : réessayer dans 15 minutes
    $file->later($delai, new EnvoyerSms('0812345678', 'Votre commande est expediee'));
    afficher_avis($e->getAdminMessage() ?? 'L\'envoi de SMS est momentanément indisponible.');
}
```

## Nouvelles tentatives et idempotence

**Jamais de double SMS.** `send()`, `sendMany()` et `otp->send()` portent toujours une `Idempotency-Key`. Si vous
n'en donnez pas, le SDK génère un UUID v4 et le réutilise pour ses propres relances. Un rejeu dans les 24 h renvoie
la réponse d'origine sans nouvel envoi ni débit (`$batch->replayed`, `$otp->replayed` ; pour l'OTP, même `otpId` et
même `testCode()`).

Pour vous protéger aussi de **vos** relances (jobs en file, double clic, plantage), passez une clé dérivée de
l'événement métier : `idempotencyKey: 'order-1042-ready'` (255 caractères max.). Une clé = une intention d'envoi : la
même clé avec un autre contenu est refusée (`IdempotencyKeyReusedException`) ; les destinataires sont comparés après
normalisation (`0812345678` puis `243812345678` est un rejeu). Pour l'OTP, réutiliser une clé pendant 24 h renvoie le
même code, jamais un nouveau.

Les relances automatiques (2 par défaut, backoff exponentiel 0,5 s → 1 s → 2 s avec gigue) n'ont lieu **que quand un
rejeu ne peut pas produire deux fois un effet** :

| Requête | Erreur réseau / délai | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` |
|---|---|---|---|---|
| `get()`, `list()`, `balance`, `senderIds`, `estimate()` | relancée | relancée | après `Retry-After` | — |
| `send()` / `sendMany()` | relancée, même clé | relancée, même clé | après `Retry-After` | après `Retry-After`, même clé |
| `otp->send()` | relancée, même clé | relancée, même clé | après `Retry-After` | après `Retry-After`, même clé |
| `otp->verify()` | **jamais** | **jamais** | après `Retry-After` | — |

- Les erreurs métier (autres 4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, `ACCOUNT_SUSPENDED`,
  `IDEMPOTENCY_KEY_UNRESOLVED`) ne sont jamais relancées.
- Les 503 `FEATURE_DISABLED` / `OTP_SERVICE_DISABLED` ne suivent pas la colonne 5xx : sur toute opération,
  `otp->verify()` compris, elles ne sont relancées qu'après un `Retry-After`, jamais par backoff.
- Quand l'API demande d'attendre plus que `maxRetryWait` (10 s par défaut), le SDK lève l'exception au lieu de
  bloquer.
- `otp->verify()` n'est jamais relancée après une erreur réseau ou une 5xx (un essai a pu être consommé) : affichez
  une erreur et laissez l'utilisateur ressaisir le code.

## Mode test, journaux et sécurité

| Clé | Mode | Effet |
|---|---|---|
| `lsk_test_…` | Bac à sable | Rien n'est envoyé, aucun crédit n'est consommé, réponses identiques à la production. Le solde n'est pas vérifié (jamais `INSUFFICIENT_CREDITS`) et `otp->send()` renvoie le code (`testCode()`). |
| `lsk_live_…` | Production | Les SMS partent réellement et consomment des crédits. |

`$client->isTestMode()` et `$client->isLiveMode()` indiquent le mode utilisé.

**Journaux.** Passez un `logger` PSR-3 pour obtenir des journaux de debug des requêtes et réponses, des warnings à
chaque relance et des infos sur les erreurs de l'API, toujours avec le `request_id`. La clé API, les codes OTP et les
textes des messages ne sont jamais journalisés ; les numéros sont masqués. `var_dump($client)` montre une clé
masquée, `serialize($client)` est refusé, et `testCode()` est masqué dans les dumps et `json_encode()`.

**Identifiants de requête.** Chaque réponse porte `X-Request-Id`. Passez le vôtre avec `requestId:` sur n'importe
quel appel (1 à 128 caractères parmi `A-Z a-z 0-9 . _ : -`, renvoyé à chaque relance) pour relier vos journaux à
ceux de l'API. Lisez-le sur toute exception avec `getRequestId()`, ou sur la dernière réponse avec
`$client->lastResponse()?->requestId()` (`lastResponse()` donne aussi le statut, `apiVersion()`, `isReplayed()`,
`rateLimitLimit()`, `rateLimitRemaining()` et tous les en-têtes). Communiquez-le au support :
connect@labyrinthe-rdc.com.

```php
try {
    $client->sms->send('0812345678', 'Bonjour', requestId: 'order-1042');
} catch (ApiException $e) {
    journaliser($e->getErrorCode(), $e->getRequestId()); // "order-1042"
}
```

## Sans le SDK (HTTP brut)

Le SDK est une fine couche sur une API REST JSON. Le même envoi avec cURL :

```bash
curl https://api.labyrinthe-rdc.com/api/public/v1/sms \
  -H "Authorization: Bearer lsk_test_…" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 6f1c2d0e-8a4b-4c3d-9e2f-1a2b3c4d5e6f" \
  -d '{"recipient": "+243812345678", "body": "Votre commande 1042 est prete."}'
```

La réponse est un `202` avec `{ "data": [ { "id": "…", "status": "queued", … } ] }` ; les erreurs ont la forme
`{ "error": { "code", "message", "details" } }`. Chaque route, champ et erreur est décrit dans la spécification
OpenAPI [`openapi/openapi.yaml`](../openapi/openapi.yaml).

## Mise à jour

Voir le [CHANGELOG.md](CHANGELOG.md) (en anglais). Les versions 1.x sont rétrocompatibles. De 1.1 à 1.2 : les erreurs
de maintenance sont désormais des `FeatureDisabledException` (sous-classe de `ServiceUnavailableException` : les
`catch` existants fonctionnent toujours) et ne sont plus relancées par backoff.

## Contribuer

Tickets et pull requests vont dans le monorepo
[Labyrinthe-Team/labyrinthe-connect-sdks](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks) (le dépôt
`labyrinthe-connect-php` est un miroir en lecture seule). Voir [CONTRIBUTING.md](../CONTRIBUTING.md).

```bash
composer install
composer check         # PHP-CS-Fixer (sans modification) + PHPStan niveau max + tests unitaires et de contrat OpenAPI
composer format        # corriger le style

# tests d'intégration contre un backend local, avec une clé de TEST uniquement
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 composer test:integration
```

Exemples : [`examples/send-sms.php`](examples/send-sms.php), [`examples/otp-login.php`](examples/otp-login.php),
[`examples/webhook-receiver.php`](examples/webhook-receiver.php).

## Licence

MIT — voir [LICENSE](LICENSE). Failles de sécurité : écrivez à connect@labyrinthe-rdc.com plutôt que d'ouvrir un
ticket public.

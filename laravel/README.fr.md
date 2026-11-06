# Labyrinthe Connect pour Laravel

[![Packagist](https://img.shields.io/packagist/v/labyrinthe/connect-laravel.svg)](https://packagist.org/packages/labyrinthe/connect-laravel)
[![CI](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/laravel.yml/badge.svg)](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/laravel.yml)
[![Licence : MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)
![Laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012%20%7C%2013-ff2d20)

Envoyez des SMS et vérifiez des numéros de téléphone avec [Labyrinthe Connect](https://labyrinthe-rdc.com), la
plateforme SMS et OTP de la RD Congo et de l'Afrique centrale, à la manière de Laravel : une façade `Labyrinthe`, un
canal de notification `labyrinthe`, un assistant OTP avec règles de validation, des webhooks signés transformés en
événements Laravel, et `Labyrinthe::fake()` pour vos tests. Package 1.2.0, construit sur le [SDK PHP](../php) 1.2.0
(spécification de l'API 1.3.0) : mêmes exceptions typées, mêmes relances sans risque, jamais de double SMS.

*English version: [README.md](README.md).*

[Prérequis](#prérequis) · [Installation](#installation) · [Démarrage rapide](#démarrage-rapide) ·
[Configuration](#configuration) · [Envoyer des SMS](#envoyer-des-sms) · [Solde et Sender IDs](#solde-et-sender-ids) ·
[OTP](#otp--vérification-du-téléphone-et-connexion) · [Webhooks](#webhooks) · [Erreurs](#erreurs) ·
[Relances, idempotence et files](#relances-idempotence-et-files) ·
[Mode test, journaux et sécurité](#mode-test-journaux-et-sécurité) · [Tester votre application](#tester-votre-application) ·
[Sans le package](#sans-le-package-http-brut) · [Mise à jour](#mise-à-jour)

## Prérequis

| | |
|---|---|
| PHP | 8.1 ou plus (et ce qu'exige votre version de Laravel) |
| Laravel | 10, 11, 12 ou 13 |
| SDK | `labyrinthe/connect-php` ^1.2.0 (installé automatiquement) |

## Installation

```bash
composer require labyrinthe/connect-laravel
```

Le service provider et la façade `Labyrinthe` sont découverts automatiquement. Ajoutez votre clé dans `.env`
(créée dans l'espace client, *Développeurs > Clés API* ; à garder côté serveur) :

```dotenv
LABYRINTHE_API_KEY=lsk_test_…     # clé de test : rien n'est envoyé, aucun crédit n'est consommé
```

Publiez si besoin la configuration (`config/labyrinthe.php`) et les messages de validation
(`lang/vendor/labyrinthe`) :

```bash
php artisan vendor:publish --tag=labyrinthe-config
php artisan vendor:publish --tag=labyrinthe-lang
```

## Démarrage rapide

```php
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;

$message = Labyrinthe::send('0812345678', 'Votre commande 1042 est prete.');
echo $message->status->value;                           // "queued" (rien n'est envoyé avec une clé lsk_test_)
```

## Configuration

Chaque option a sa variable d'environnement ; le client est construit à la demande : une clé absente n'échoue que
lorsque l'API est utilisée.

| Variable | Clé de config | Défaut | |
|---|---|---|---|
| `LABYRINTHE_API_KEY` | `api_key` | — | `lsk_test_…` (bac à sable) ou `lsk_live_…` |
| `LABYRINTHE_BASE_URL` | `base_url` | `https://api.labyrinthe-rdc.com/api/public/v1` | `http://localhost:8000` est complété par `/api/public/v1` |
| `LABYRINTHE_TIMEOUT` | `timeout` | `30` | secondes |
| `LABYRINTHE_CONNECT_TIMEOUT` | `connect_timeout` | `10` | secondes |
| `LABYRINTHE_MAX_RETRIES` | `max_retries` | `2` | relances sans risque uniquement (jamais un second SMS ni un second OTP) |
| `LABYRINTHE_DEFAULT_COUNTRY_CODE` | `default_country_code` | `243` | ajouté aux numéros locaux (`0812345678` → `243812345678`) |
| `LABYRINTHE_SENDER` | `sender` | — | Sender ID approuvé utilisé par défaut (sinon celui du compte) |
| `LABYRINTHE_OTP_TEMPLATE` | `otp.template` | — | message OTP, doit contenir `{code}` (sinon les paramètres OTP du compte) |
| `LABYRINTHE_OTP_LOCALE` | `otp.locale` | — | `fr` ou `en` |
| `LABYRINTHE_OTP_WEB_OTP_DOMAIN` | `otp.web_otp_domain` | — | nom d'hôte pour la Web OTP API (ex. `app.exemple.com`) |
| `LABYRINTHE_OTP_STRIP_ACCENTS` | `otp.strip_accents` | `false` | message OTP en GSM-7 |
| `LABYRINTHE_WEBHOOK_SECRET` | `webhook.secret` | — | secret du point d'entrée (espace client > Développeurs > Webhooks) |
| `LABYRINTHE_WEBHOOK_PATH` | `webhook.path` | `webhooks/labyrinthe` | URI utilisée par `Route::labyrintheWebhooks()` |
| `LABYRINTHE_WEBHOOK_TOLERANCE` | `webhook.tolerance` | `300` | âge maximal d'une livraison, en secondes (anti-rejeu) |
| `LABYRINTHE_WEBHOOK_DEDUPLICATE` | `webhook.deduplicate` | `true` | ignorer les `id` d'événement déjà traités |
| `LABYRINTHE_WEBHOOK_CACHE_STORE` | `webhook.cache_store` | store par défaut | store de cache du dédoublonnage |
| `LABYRINTHE_WEBHOOK_CACHE_TTL` | `webhook.cache_ttl` | `86400` | durée de conservation des `id` traités, en secondes |
| `LABYRINTHE_LOG_CHANNEL` | `log_channel` | — | canal de log des journaux du SDK (aucun si vide) |

Le SDK attend au plus 10 secondes un `Retry-After` (`maxRetryWait`). Toutes les options du SDK PHP sont décrites dans
son [README](../php/README.fr.md#configuration). Vous pouvez injecter `Labyrinthe\Connect\Client` (singleton) ou
`Labyrinthe\Connect\Laravel\LabyrintheManager` au lieu d'utiliser la façade.

## Envoyer des SMS

### Avec la façade

```php
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;

// Raccourci : une chaîne → Message, un tableau → MessageBatch (tout ou rien)
Labyrinthe::send('0812345678', 'Votre commande 1042 est prete.', sender: 'MABOUTIQUE', reference: 'order-1042');
Labyrinthe::send(['0812345678', '0899999999'], 'Reunion demain a 9h.');

// Le service complet du SDK : sender, reference, stripAccents, idempotencyKey, requestId
$message = Labyrinthe::sms()->send(to: '0812345678', body: 'Réunion à 9h', stripAccents: true);
$batch = Labyrinthe::sms()->sendMany(['0812345678', '0899999999'], 'Reunion demain a 9h.');

$estimate = Labyrinthe::sms()->estimate('Réunion à 9h', ['0812345678']);   // encodage, segments, crédits : gratuit
$message = Labyrinthe::sms()->get($message->id);
$page = Labyrinthe::sms()->list(status: 'failed', reference: 'order-1042', perPage: 50);
foreach ($page->autoPaging() as $message) { /* … */ }
```

`sendMany()` vérifie chaque destinataire, le Sender ID et le solde **avant** l'envoi : un seul numéro refusé
(`RecipientNotSendableException`, avec `getRejected()`) et aucun SMS ne part. Jusqu'à 1000 destinataires, doublons
fusionnés, texte de 1 à 1600 caractères. Tout caractère non ASCII passe en UCS-2 (70 caractères par SMS au lieu de
160) : `stripAccents: true` garde le texte en GSM-7. L'envoi est asynchrone : suivez la remise avec l'événement
`MessageStatusChanged`.

### Canal de notification

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Labyrinthe\Connect\Laravel\Notifications\LabyrintheMessage;

class OrderShipped extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Order $order) {}

    public function via(object $notifiable): array
    {
        return ['labyrinthe'];
    }

    public function toLabyrinthe(object $notifiable): LabyrintheMessage
    {
        return LabyrintheMessage::create("Votre commande {$this->order->number} est expediee.")
            ->reference("order-{$this->order->id}")    // facultatif, 64 caractères max.
            ->sender('MABOUTIQUE')                      // facultatif, sinon LABYRINTHE_SENDER
            ->stripAccents();                           // facultatif : accents → ASCII, GSM-7
    }
}
```

| `LabyrintheMessage` | |
|---|---|
| `create($content)` / `content($text)` | le texte du SMS |
| `sender(?string)` | un Sender ID approuvé |
| `reference(?string)` | votre référence, filtrable dans l'API et renvoyée dans les webhooks |
| `stripAccents(bool = true)` | accents remplacés par de l'ASCII par l'API |
| `idempotencyKey(?string)` | remplace la clé dérivée de l'identifiant de la notification |
| `to(string\|array)` | remplace le destinataire du notifiable (plusieurs numéros : un seul envoi tout ou rien) |

`toLabyrinthe()` peut aussi renvoyer une simple chaîne. Le numéro vient de `routeNotificationForLabyrinthe()` sur le
notifiable, sinon de son attribut `phone` ; rien n'est envoyé s'il n'y en a pas.

```php
class User extends Authenticatable
{
    use Notifiable;

    public function routeNotificationForLabyrinthe(Notification $notification): string|array|null
    {
        return $this->mobile_number;          // tous formats : +243…, 0812…, 243…
    }
}

$user->notify(new OrderShipped($order));
Notification::route('labyrinthe', '0812345678')->notify(new OrderShipped($order));   // à la demande
```

## Solde et Sender IDs

```php
Labyrinthe::balance()->get()->balance;     // crédits disponibles (->held : réservés par les campagnes en cours)

foreach (Labyrinthe::senderIds()->list() as $senderId) {
    $senderId->value;                      // nom approuvé, utilisable comme sender
    $senderId->isDefault;
}

Labyrinthe::isTestMode();                  // true avec une clé lsk_test_
```

## OTP : vérification du téléphone et connexion

`Labyrinthe::otp()` envoie et vérifie les codes avec les valeurs par défaut de `labyrinthe.otp` ; les règles
`PhoneNumber` et `ValidOtp` le branchent sur la validation Laravel.

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Labyrinthe\Connect\Exception\FeatureDisabledException;
use Labyrinthe\Connect\Exception\OtpRateLimitedException;
use Labyrinthe\Connect\Exception\OtpResendTooSoonException;
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;
use Labyrinthe\Connect\Laravel\Rules\PhoneNumber;
use Labyrinthe\Connect\Laravel\Rules\ValidOtp;

class PhoneLoginController
{
    // 1. Envoyer le code et garder otp_id en SESSION (côté serveur), jamais dans le formulaire.
    public function send(Request $request)
    {
        $request->validate(['phone' => ['required', new PhoneNumber()]]);

        try {
            $otp = Labyrinthe::otp()->send($request->input('phone'));
        } catch (OtpResendTooSoonException $e) {
            return back()->withErrors(['phone' => "Patientez {$e->getRetryAfter()} secondes."]);
        } catch (OtpRateLimitedException) {
            return back()->withErrors(['phone' => 'Trop de codes demandés. Réessayez plus tard.']);
        } catch (FeatureDisabledException $e) {
            return back()->withErrors(['phone' => $e->getAdminMessage() ?? 'Les codes SMS sont momentanément indisponibles.']);
        }

        $request->session()->put(['otp_id' => $otp->otpId, 'otp_phone' => $request->input('phone')]);

        return redirect()->route('login.code')->with([
            'code_length' => $otp->codeLength,         // taille du champ
            'resend_after' => $otp->resendAfterSeconds, // compte à rebours « Renvoyer le code »
        ]);
    }

    // 2. L'utilisateur saisit le code ; 3. le vérifier.
    public function verify(Request $request)
    {
        $request->validate([
            'code' => ['bail', 'required', 'string', ValidOtp::withAttemptsRemaining($request->session()->get('otp_id'))],
        ]);

        $request->session()->forget('otp_id');
        $phone = \Labyrinthe\Connect\PhoneNumber::normalize($request->session()->pull('otp_phone'));
        Auth::login(User::firstOrCreate(['phone' => $phone]));
        $request->session()->regenerate();

        return redirect()->intended('/');
    }
}
```

| `Labyrinthe::otp()` | |
|---|---|
| `send($phone, template:, locale:, webOtpDomain:, stripAccents:, idempotencyKey:)` | `OtpSendResult` : `otpId`, `expiresAt`, `maxAttempts`, `codeLength`, `codeType`, `resendAfterSeconds`, `replayed`, `testCode()` |
| `verify($otpId, $code)` | `OtpVerifyResult` : `verified`, `reason`, `attemptsRemaining`, `isValid()` |
| `check(?$otpId, ?$code)` | simple `bool` ; un `otpId` vide ou inconnu donne `false` |

| Règle | |
|---|---|
| `new PhoneNumber(?string $defaultCountryCode = null)` | contrôle hors ligne avec les règles de l'API (8 à 15 chiffres, numéros internationaux acceptés) |
| `new ValidOtp(?string $otpId, bool $showAttemptsRemaining = false)` | appelle l'API et échoue avec un message traduit : code faux, expiré, déjà utilisé, trop d'essais, `otp_id` absent |
| `ValidOtp::withAttemptsRemaining($otpId)` | idem, le message de code faux indique les essais restants (« Le champ code est incorrect. Encore 2 tentatives. ») |

- Chaque code faux consomme un essai : placez `ValidOtp` en dernier, après `bail`. `$rule->result()` donne le
  résultat de l'API.
- Les messages existent en anglais et en français (`labyrinthe::validation.*`) ; publiez-les avec
  `--tag=labyrinthe-lang`.
- Un nouvel envoi au même numéro invalide le code précédent ; les limites sont un délai minimal de renvoi par numéro
  (`OtpResendTooSoonException`) et un plafond horaire par numéro et par compte (`OtpRateLimitedException`,
  `getScope()`, `getLimit()`), plus 10 envois / minute par clé API.
- **Web OTP** : `webOtpDomain: 'app.exemple.com'` (ou `LABYRINTHE_OTP_WEB_OTP_DOMAIN`) ajoute
  `@app.exemple.com #123456` au SMS pour que Chrome / Android remplisse le code
  (`navigator.credentials.get({ otp: { transport: ['sms'] } })`).
- **Mode test** : avec une clé `lsk_test_`, aucun SMS ne part et `$otp->testCode()` renvoie le code.
- Enregistrez le numéro normalisé : `Labyrinthe\Connect\PhoneNumber::normalize($phone)` → `"243812345678"`.

## Webhooks

Déclarez le point d'entrée, de préférence dans `routes/api.php` (sans session) :

```php
use Illuminate\Support\Facades\Route;

Route::labyrintheWebhooks();                     // POST /api/webhooks/labyrinthe, nommée labyrinthe.webhooks
// ou Route::labyrintheWebhooks('hooks/sms');
```

Saisissez ensuite l'URL dans l'espace client (*Développeurs > Webhooks*) et copiez le secret dans
`LABYRINTHE_WEBHOOK_SECRET`. La route exclut elle-même le middleware CSRF : elle fonctionne aussi depuis
`routes/web.php`. Si vous dirigez votre propre route vers `Labyrinthe\Connect\Laravel\Http\Controllers\WebhookController`,
excluez-la du CSRF.

Le contrôleur vérifie la signature sur le **corps brut** en temps constant (400 si elle est invalide ou plus ancienne
que la tolérance ; 500 tant que le secret n'est pas défini, pour que la livraison soit retentée), ignore les `id` déjà
traités (`Cache::add()` atomique sur l'`id` de l'événement), déclenche `WebhookReceived` puis l'événement typé, et
répond `200`. Si un listener lève une exception, le contrôleur répond 5xx et oublie l'`id` : la tentative suivante
sera traitée.

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Labyrinthe\Connect\Laravel\Events\MessageStatusChanged;

class UpdateMessageStatus implements ShouldQueue        // travail lent en file : l'API attend 10 s au plus
{
    public function handle(MessageStatusChanged $event): void
    {
        SmsLog::where('message_id', $event->event->messageId)->update(['status' => $event->event->status->value]);
    }
}
```

| Événement Laravel (`Labyrinthe\Connect\Laravel\Events\…`) | Webhook | Contenu (`$event->event`) |
|---|---|---|
| `MessageStatusChanged` | `message.status_changed` | `messageId`, `recipient`, `status` (enum), `providerMessageId`, `campaignId`, `reference`, `errorCode`, `errorMessage` |
| `CampaignCompleted` | `campaign.completed` | `campaignId`, `name`, `recipientsCount`, `countFor(MessageStatus)`, `creditsCharged`, `completedAt` |
| `BalanceLow` | `balance.low` | `balance`, `threshold` |
| `OtpVerified` | `otp.verified` | `otpId`, `phone`, `status` (`OtpWebhookStatus::Verified`), `verifiedAt` (jamais le code) |
| `OtpFailed` | `otp.failed` | `otpId`, `phone`, `status` (`Failed` ou `Expired`), `reason` (`MaxAttemptsReached`, `Expired` ou `null`) |
| `WebhookReceived` | tous les événements, dont `test.ping` et les types futurs | `id()`, `type()`, `timestamp()`, `raw()` |

Les livraisons sont retentées jusqu'à 5 fois et peuvent arriver dans le désordre : gardez
`LABYRINTHE_WEBHOOK_DEDUPLICATE=true` (ou dédoublonnez vous-même sur `$event->event->id()`).

## Erreurs

Toutes les exceptions viennent du SDK PHP (`Labyrinthe\Connect\Exception\…`) et implémentent `LabyrintheException` ;
les erreurs de l'API héritent de `ApiException` avec `getErrorCode()`, `getStatusCode()`, `getDetails()` et
`getRequestId()`.

| Exception | HTTP | Code | Que faire |
|---|---|---|---|
| `AuthenticationException` | 401 | `UNAUTHENTICATED` | vérifier `LABYRINTHE_API_KEY` |
| `PermissionException` / `AccountSuspendedException` | 403 | `FORBIDDEN`, `OTP_DISABLED_FOR_ACCOUNT` / `ACCOUNT_SUSPENDED` | IP non autorisée, OTP désactivé ou compte suspendu : contacter le support |
| `NotFoundException` | 404 | `NOT_FOUND` | message ou `otp_id` inconnu |
| `ValidationException` | 422 | `VALIDATION_ERROR` | `getErrors()`, `getFieldErrors('body')` |
| `RecipientNotSendableException` | 422 | `RECIPIENT_NOT_SENDABLE` | rien n'est parti : `getRejected()` |
| `SenderIdNotApprovedException` | 422 | `SENDER_ID_NOT_APPROVED` | utiliser un Sender ID approuvé |
| `InvalidPhoneException` | 422 | `INVALID_PHONE` | OTP : demander un autre numéro |
| `InsufficientCreditsException` | 409 | `INSUFFICIENT_CREDITS` | rien n'est parti : acheter des crédits (`getAvailable()`, `getRequired()`) |
| `IdempotencyKeyReusedException` | 409 | `IDEMPOTENCY_KEY_REUSED` | même clé, contenu différent (le premier SMS est parti) |
| `IdempotencyKeyUnresolvedException` | 409 | `IDEMPOTENCY_KEY_UNRESOLVED` | résultat inconnu : vérifier avec `Labyrinthe::sms()->list(reference: …)`, puis envoyer avec une **nouvelle** clé |
| `RateLimitException`, `OtpResendTooSoonException`, `OtpRateLimitedException` | 429 | `TOO_MANY_REQUESTS`, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED` | attendre `getRetryAfter()` secondes |
| `FeatureDisabledException` | 503 | `FEATURE_DISABLED`, `OTP_SERVICE_DISABLED` | maintenance, rien n'est parti : voir plus bas |
| `ServiceUnavailableException` | 5xx | tous | erreur temporaire, déjà relancée quand c'est sans risque |
| `ConnectionException` | — | `CONNECTION_ERROR`, `TIMEOUT` | erreur réseau |
| `ApiException` | tous | tout autre code | la liste est ouverte |

Le tableau complet, avec chaque accesseur, est dans le [README du SDK PHP](../php/README.fr.md#erreurs).

### Maintenance (503 `FEATURE_DISABLED`)

Un administrateur de la plateforme peut couper temporairement toute l'API (`api`), l'envoi de SMS (`api.sms_send`),
l'envoi ou la vérification des OTP (`api.otp_send`, `api.otp_verify`) ou tout le service OTP (`otp`, code
`OTP_SERVICE_DISABLED`). **Rien n'est parti, aucun crédit n'est consommé.** `FeatureDisabledException` (une
`ServiceUnavailableException`) expose `getFeature()`, `getBlockedBy()`, `getAdminMessage()` (le texte de
l'administrateur ou `null`), `getUntil()` (`DateTimeImmutable` ou `null`) et `getRetryAfter()` (secondes ou `null`).
Le SDK ne la relance qu'après un `Retry-After` de 10 secondes au plus ; sinon, pas de relance en boucle : remettez le
job à plus tard jusqu'au retour annoncé (voir ci-dessous) ou affichez le message à l'utilisateur.

## Relances, idempotence et files

- **Chaque envoi de SMS et d'OTP porte une `Idempotency-Key`** (la vôtre, ou un UUID généré et réutilisé par les
  relances du SDK) : erreurs réseau, 5xx et `IDEMPOTENCY_REQUEST_IN_PROGRESS` sont relancés sans second SMS. Un rejeu
  dans les 24 h renvoie le premier résultat (`$batch->replayed`, `$otp->replayed`, même `otpId`).
- **Les notifications** utilisent `laravel-notification-<identifiant de la notification>` comme clé. Laravel conserve
  cet identifiant entre les tentatives de la file : une notification en file relancée rejoue le premier envoi au lieu
  d'envoyer un second SMS.
- **Vos propres jobs** : passez une clé dérivée de l'événement métier, stable d'une tentative à l'autre.
- `otp->verify()` n'est jamais relancée après une erreur réseau ou une 5xx ; les erreurs métier (4xx,
  `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, `IDEMPOTENCY_KEY_UNRESOLVED`) ne sont jamais relancées. Détails :
  [README du SDK PHP](../php/README.fr.md#nouvelles-tentatives-et-idempotence).

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Labyrinthe\Connect\Exception\FeatureDisabledException;
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;

class SendShippingSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(private int $orderId, private string $phone) {}

    public function handle(): void
    {
        try {
            Labyrinthe::send($this->phone, 'Votre commande est expediee.',
                reference: "order-{$this->orderId}",
                idempotencyKey: "order-{$this->orderId}-shipped");     // même clé à chaque tentative
        } catch (FeatureDisabledException $e) {
            $this->release($e->getRetryAfter() ?? 900);              // maintenance : revenir plus tard
        }
    }
}
```

Pour une notification en file, définissez `$tries` et `$backoff` sur la classe de la notification : à chaque
tentative, elle réutilise la même clé.

## Mode test, journaux et sécurité

- Les clés `lsk_test_…` utilisent le bac à sable : rien n'est envoyé, rien n'est facturé, le solde n'est pas vérifié
  et les envois d'OTP renvoient le code (`testCode()`). Les clés `lsk_live_…` envoient réellement.
  `Labyrinthe::isTestMode()` indique le mode.
- `LABYRINTHE_LOG_CHANNEL=stack` envoie les journaux du SDK (requêtes, relances, erreurs de l'API avec leur
  `request_id`) sur ce canal. La clé API, les codes OTP et les textes des messages ne sont jamais journalisés ; les
  numéros sont masqués.
- Gardez la clé dans `.env`, jamais dans le navigateur ni une application mobile. `config:cache` fonctionne
  normalement.
- Chaque exception porte `getRequestId()` (l'en-tête `X-Request-Id`) : communiquez-le au support
  (connect@labyrinthe-rdc.com). `Labyrinthe::client()->lastResponse()?->requestId()` donne le dernier.

## Tester votre application

`Labyrinthe::fake()` remplace l'API par une implémentation en mémoire. Le vrai SDK s'exécute toujours (validation,
normalisation des numéros, erreurs typées) et rien ne sort de votre application.

```php
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;
use Labyrinthe\Connect\Laravel\Testing\SentSms;

public function test_customers_are_told_when_their_order_ships(): void
{
    Labyrinthe::fake();

    $this->post("/orders/{$order->id}/ship");

    Labyrinthe::assertSmsSentTo('0812345678', fn (SentSms $sms) => str_contains($sms->body, 'expediee'));
    Labyrinthe::assertSmsSentCount(1);
}

public function test_phone_login(): void
{
    $fake = Labyrinthe::fake();

    $this->post('/login/phone', ['phone' => '0812345678']);
    Labyrinthe::assertOtpSent('0812345678');

    $this->post('/login/code', ['code' => $fake->otpCode('0812345678')])->assertRedirect('/');
}

public function test_maintenance_is_shown(): void
{
    Labyrinthe::fake()->failNextRequestWithFeatureDisabled('api.otp_send', 'Retour a 10h.', now()->addHour());

    $this->post('/login/phone', ['phone' => '0812345678'])->assertSessionHasErrors('phone');
    Labyrinthe::assertNoOtpSent();
}
```

| Méthode | |
|---|---|
| `assertSmsSent(?callable)`, `assertSmsSentTo($phone, ?callable)`, `assertSmsNotSentTo($phone)`, `assertSmsSentCount($n)` | SMS (numéros dans tous les formats) |
| `assertOtpSent($phone ou callable)`, `assertNoOtpSent()` | OTP |
| `assertNothingSent()` | ni SMS ni OTP |
| `sent(?callable)`, `otps(?callable)`, `otpCode(?$phone)` | `SentSms` / `SentOtp` enregistrés et dernier code |
| `withBalance(int)`, `withSenderIds([...])`, `withOtpCode('123456')`, `withOtpMaxAttempts(5)`, `withOtpResendAfter(60)` | préparer le fake |
| `failNextRequest($code, $status = 422, $details = [], $message)` | le prochain appel échoue, ex. `failNextRequest('INSUFFICIENT_CREDITS', 409, ['available' => 0, 'required' => 1])` |
| `failNextRequestWithFeatureDisabled($feature = 'api', $message = null, $until = null, $blockedBy = null)` | le prochain appel échoue en 503 `FEATURE_DISABLED` (`'otp'` → `OTP_SERVICE_DISABLED` ; `$until` ajoute `Retry-After`) ; jamais relancé par le fake |

Le fake se comporte comme le bac à sable : rejeux idempotents (SMS et OTP, comparés après normalisation), doublons
fusionnés, codes à usage unique, plafond d'essais (3 par défaut) avec `attemptsRemaining`, un nouveau code invalidant
le précédent. `SentSms` enregistre `recipients`, `body`, `sender`, `reference`, `idempotencyKey`, `messageIds`,
`stripAccents` ; `SentOtp` enregistre `otpId`, `phone`, `code`, `template`, `locale`, `webOtpDomain`, `stripAccents`,
`idempotencyKey`.

## Sans le package (HTTP brut)

Le package enveloppe une API REST JSON ; avec le seul client HTTP de Laravel :

```php
Http::withToken(config('labyrinthe.api_key'))
    ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
    ->post('https://api.labyrinthe-rdc.com/api/public/v1/sms', ['recipient' => '+243812345678', 'body' => 'Bonjour'])
    ->throw()
    ->json('data.0.status');                               // "queued"
```

Relances, idempotence, erreurs et signatures de webhook sont alors à votre charge : voir la spécification OpenAPI
[`openapi/openapi.yaml`](../openapi/openapi.yaml).

## Mise à jour

Voir le [CHANGELOG.md](CHANGELOG.md) (en anglais). Les versions 1.x sont rétrocompatibles. La 1.2.0 exige
`labyrinthe/connect-php` ^1.2.0 et ajoute `FeatureDisabledException` et `failNextRequestWithFeatureDisabled()` ; aucun
changement de configuration.

## Contribuer

Tickets et pull requests vont dans le monorepo
[Labyrinthe-Team/labyrinthe-connect-sdks](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks) (le dépôt
`labyrinthe-connect-laravel` est un miroir en lecture seule). Voir [CONTRIBUTING.md](../CONTRIBUTING.md).

```bash
composer install       # utilise ../php via un dépôt Composer « path » (monorepo)
composer check         # PHP-CS-Fixer (sans modification) + Larastan niveau max + tests Orchestra Testbench
```

## Licence

MIT — voir [LICENSE](LICENSE). Failles de sécurité : écrivez à connect@labyrinthe-rdc.com plutôt que d'ouvrir un
ticket public.

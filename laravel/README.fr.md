# Labyrinthe Connect pour Laravel

Envoyez des SMS et vérifiez des numéros avec [Labyrinthe Connect](https://labyrinthe-rdc.com) depuis Laravel : canal
de notification, helper OTP et règles de validation, webhooks signés transformés en événements Laravel, et un
*fake* pour vos tests. Construit sur le [SDK PHP](../php).

*English version: [README.md](README.md).*

## Installation

```bash
composer require labyrinthe/connect-laravel
```

Le service provider et la façade `Labyrinthe` sont découverts automatiquement. Ajoutez votre clé dans `.env` :

```dotenv
LABYRINTHE_API_KEY=lsk_test_xxxxxxxxxxxxxxxx   # clé de test : rien n'est envoyé, aucun crédit consommé
LABYRINTHE_SENDER=MABOUTIQUE                   # facultatif : Sender ID approuvé utilisé par défaut
LABYRINTHE_WEBHOOK_SECRET=whsec_…              # facultatif : pour recevoir les webhooks
```

Publication facultative de la configuration (`config/labyrinthe.php`) et des traductions :

```bash
php artisan vendor:publish --tag=labyrinthe-config
php artisan vendor:publish --tag=labyrinthe-lang
```

| Variable | Défaut | |
|---|---|---|
| `LABYRINTHE_API_KEY` | — | `lsk_test_…` (bac à sable) ou `lsk_live_…` |
| `LABYRINTHE_BASE_URL` | `https://api.labyrinthe-rdc.com/api/public/v1` | `http://localhost:8000` est complété par `/api/public/v1` |
| `LABYRINTHE_TIMEOUT`, `LABYRINTHE_CONNECT_TIMEOUT` | `30`, `10` | secondes |
| `LABYRINTHE_MAX_RETRIES` | `2` | relances sûres uniquement (jamais un second SMS ni un second OTP) |
| `LABYRINTHE_DEFAULT_COUNTRY_CODE` | `243` | ajouté aux numéros locaux (`0812345678` → `243812345678`) |
| `LABYRINTHE_SENDER` | — | Sender ID par défaut |
| `LABYRINTHE_OTP_TEMPLATE`, `LABYRINTHE_OTP_LOCALE` | — | valeurs OTP par défaut (sinon les paramètres OTP du compte) |
| `LABYRINTHE_WEBHOOK_SECRET` | — | secret de l'endpoint (espace client > Développeurs > Webhooks) |
| `LABYRINTHE_WEBHOOK_PATH` | `webhooks/labyrinthe` | URI utilisée par `Route::labyrintheWebhooks()` |
| `LABYRINTHE_WEBHOOK_TOLERANCE` | `300` | âge maximal d'une livraison, en secondes |
| `LABYRINTHE_WEBHOOK_DEDUPLICATE` | `true` | ignorer les identifiants déjà traités (cache) |
| `LABYRINTHE_LOG_CHANNEL` | — | canal de log du SDK (ni clé, ni code, numéros masqués) |

## Notifications

```php
use Illuminate\Notifications\Notification;
use Labyrinthe\Connect\Laravel\Notifications\LabyrintheMessage;

class CommandeExpediee extends Notification
{
    public function __construct(private Commande $commande) {}

    public function via(object $notifiable): array
    {
        return ['labyrinthe'];
    }

    public function toLabyrinthe(object $notifiable): LabyrintheMessage
    {
        return LabyrintheMessage::create("Votre commande {$this->commande->numero} est partie.")
            ->reference("commande-{$this->commande->id}");   // facultatif ; ->sender('MABOUTIQUE') aussi
    }
}
```

Le numéro vient de `routeNotificationForLabyrinthe()` sur le notifiable, sinon de son attribut `phone` :

```php
public function routeNotificationForLabyrinthe(Notification $notification): string
{
    return $this->mobile;            // tout format : +243…, 0812…, 243…
}

$user->notify(new CommandeExpediee($commande));
Notification::route('labyrinthe', '0812345678')->notify(new CommandeExpediee($commande));   // à la volée
```

`toLabyrinthe()` peut aussi renvoyer une simple chaîne. Plusieurs numéros partent en une seule requête « tout ou rien ».

**Les notifications en file peuvent être relancées sans risque** : le canal utilise l'identifiant de la notification
comme `Idempotency-Key` ; un job relancé rejoue le premier envoi au lieu d'envoyer un second SMS (si le contenu a
changé entre-temps, l'API répond `IDEMPOTENCY_KEY_REUSED` : le premier SMS est déjà parti).

## Envoi direct

```php
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;

$message = Labyrinthe::sms()->send(to: '0812345678', body: 'Votre commande est prête.', reference: 'commande-1042');
$lot = Labyrinthe::sms()->sendMany(['0812345678', '0899999999'], 'Réunion demain à 9h.');

Labyrinthe::send('0812345678', 'Bonjour');              // raccourci (chaîne → Message, tableau → MessageBatch)
Labyrinthe::sms()->estimate('Réunion à 9h', ['0812345678'])->totalCredits;
Labyrinthe::balance()->get()->balance;
Labyrinthe::senderIds()->list();
```

Vous pouvez aussi injecter `Labyrinthe\Connect\Client` (singleton) ou `Labyrinthe\Connect\Laravel\LabyrintheManager`.
Options, erreurs et règles de relance : voir le [README du SDK PHP](../php/README.fr.md).

## Connexion par OTP

```php
use Labyrinthe\Connect\Exception\OtpResendTooSoonException;
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;
use Labyrinthe\Connect\Laravel\Rules\PhoneNumber;
use Labyrinthe\Connect\Laravel\Rules\ValidOtp;

class ConnexionTelephoneController
{
    // 1. Envoyer le code et garder otp_id en SESSION (côté serveur), jamais dans le formulaire.
    public function envoyer(Request $request)
    {
        $request->validate(['phone' => ['required', new PhoneNumber()]]);

        try {
            $otp = Labyrinthe::otp()->send($request->phone);
        } catch (OtpResendTooSoonException $e) {
            return back()->withErrors(['phone' => "Patientez {$e->getRetryAfter()} secondes."]);
        }

        $request->session()->put('otp_id', $otp->otpId);
        $request->session()->put('otp_phone', $request->phone);

        return redirect()->route('login.code');
    }

    // 2. L'utilisateur saisit le code ; 3. on le vérifie.
    public function verifier(Request $request)
    {
        $request->validate([
            'code' => ['bail', 'required', 'string', new ValidOtp($request->session()->get('otp_id'))],
        ]);

        $request->session()->forget('otp_id');
        $user = User::firstOrCreate(['phone' => \Labyrinthe\Connect\PhoneNumber::normalize($request->session()->pull('otp_phone'))]);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }
}
```

- `ValidOtp` appelle l'API et donne un message traduit (anglais et français inclus) pour chaque échec : code faux,
  expiré, déjà utilisé, trop de tentatives. Chaque code faux consomme une tentative : placez la règle en dernier, avec `bail`.
- `Labyrinthe::otp()->check($otpId, $code)` renvoie un booléen ; `verify()` renvoie le résultat complet avec son motif.
- `PhoneNumber` valide un numéro hors ligne, avec les règles de l'API (les numéros internationaux sont acceptés).
- Avec une clé de test, aucun SMS ne part et `$otp->testCode()` contient le code.

## Webhooks

Déclarez l'endpoint, de préférence dans `routes/api.php` (ni session ni CSRF) :

```php
Route::labyrintheWebhooks();                       // POST /api/webhooks/labyrinthe
```

Saisissez ensuite l'URL dans l'espace client (*Développeurs > Webhooks*) et copiez le secret dans
`LABYRINTHE_WEBHOOK_SECRET`. La route exclut elle-même le middleware CSRF : elle fonctionne aussi depuis
`routes/web.php` (Laravel 10 à 13). Si vous déclarez votre propre route vers `WebhookController`, excluez-la du CSRF
(`validateCsrfTokens(except: ['webhooks/labyrinthe'])` dans `bootstrap/app.php`, ou `$except` de `VerifyCsrfToken`
sous Laravel 10).

Le contrôleur vérifie la signature sur le corps brut (400 si invalide ou trop ancienne), ignore les identifiants déjà
traités (cache), déclenche les événements ci-dessous et répond `200` aussitôt :

```php
use Labyrinthe\Connect\Laravel\Events\MessageStatusChanged;

class MajStatutMessage implements ShouldQueue        // traitements longs en file : l'API attend 10 s au plus
{
    public function handle(MessageStatusChanged $event): void
    {
        SmsLog::where('provider_id', $event->event->messageId)->update(['status' => $event->event->status->value]);
    }
}
```

| Événement Laravel | Webhook | Contenu (`$event->event`) |
|---|---|---|
| `MessageStatusChanged` | `message.status_changed` | `messageId`, `recipient`, `status` (enum), `providerMessageId`, `campaignId` |
| `CampaignCompleted` | `campaign.completed` | `campaignId`, `name`, `recipientsCount`, `countFor(MessageStatus)`, `creditsCharged`, `completedAt` |
| `BalanceLow` | `balance.low` | `balance`, `threshold` |
| `WebhookReceived` | tous, y compris `test.ping` et les types futurs | `id()`, `type()`, `raw()` |

Si un listener lève une exception, le contrôleur répond 5xx et oublie l'identifiant : la tentative suivante sera traitée.

## Tester votre application

`Labyrinthe::fake()` remplace l'API par une implémentation en mémoire. Le vrai SDK s'exécute toujours (validation,
normalisation des numéros, erreurs typées) ; rien ne sort de l'application :

```php
public function test_le_client_est_prevenu_de_l_expedition(): void
{
    Labyrinthe::fake();

    $this->post("/commandes/{$commande->id}/expedier");

    Labyrinthe::assertSmsSentTo('0812345678', fn (SentSms $sms) => str_contains($sms->body, 'partie'));
}

public function test_connexion_par_telephone(): void
{
    $fake = Labyrinthe::fake();

    $this->post('/login/phone', ['phone' => '0812345678']);
    Labyrinthe::assertOtpSent('0812345678');

    $this->post('/login/code', ['code' => $fake->otpCode('0812345678')])->assertRedirect('/');
}
```

| Méthode | |
|---|---|
| `assertSmsSent(?callable)`, `assertSmsSentTo($tel, ?callable)`, `assertSmsNotSentTo($tel)`, `assertSmsSentCount($n)` | SMS (numéros en tout format) |
| `assertOtpSent($tel ou callable)`, `assertNoOtpSent()` | OTP |
| `assertNothingSent()` | ni SMS ni OTP |
| `sent()`, `otps()`, `otpCode(?$tel)` | inspecter ce qui a été enregistré |
| `withBalance(int)`, `withSenderIds([...])`, `withOtpCode('123456')` | préparer le fake |
| `failNextRequest('INSUFFICIENT_CREDITS', 409, ['available' => 0, 'required' => 1])` | simuler une erreur de l'API |

Le fake se comporte comme le bac à sable : rejeu idempotent, doublons fusionnés, OTP à usage unique, plafond de
tentatives, nouveau code qui invalide le précédent.

## Développement

```bash
composer install       # utilise ../php via un dépôt Composer « path » (monorepo)
composer test          # Orchestra Testbench
composer analyse       # Larastan niveau max
composer lint
```

## Licence

MIT — voir [LICENSE](LICENSE).

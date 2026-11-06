# Labyrinthe Connect — SDK .NET

[![CI](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/dotnet.yml/badge.svg)](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/dotnet.yml)
[![NuGet](https://img.shields.io/nuget/v/Labyrinthe.Connect.svg)](https://www.nuget.org/packages/Labyrinthe.Connect)
![.NET](https://img.shields.io/badge/.NET-netstandard2.0%20%7C%20net8.0-512BD4)

Client .NET officiel de [Labyrinthe Connect](https://labyrinthe-rdc.com) : envoyez des SMS et vérifiez des numéros
de téléphone par code à usage unique (OTP), en RDC et en Afrique centrale.

*English version: [README.md](README.md).*

- Tout est `async`, avec `CancellationToken` ; pagination en `IAsyncEnumerable`. Annotations nullables, records immuables.
- Cibles `netstandard2.0` (.NET Framework 4.7.2+, Mono, Unity…) et `net8.0` (compatible trimming / AOT).
- Sûr par défaut : relances automatiques **uniquement** quand elles ne peuvent pas envoyer deux fois un SMS.
- Une dépendance sous `netstandard2.0` (`System.Text.Json`), aucune sous `net8.0`.
- [`Labyrinthe.Connect.AspNetCore`](https://www.nuget.org/packages/Labyrinthe.Connect.AspNetCore) : `AddLabyrinthe()`
  avec `IHttpClientFactory` et `ILogger`, et un endpoint minimal API pour les webhooks.
- Ne journalise ni n'affiche jamais votre clé API, les codes OTP ou les numéros complets.

## Installation

```bash
dotnet add package Labyrinthe.Connect
dotnet add package Labyrinthe.Connect.AspNetCore   # intégration ASP.NET Core (facultatif)
```

## Démarrage rapide

```csharp
using Labyrinthe.Connect;

using var client = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_…" }); // ou LABYRINTHE_API_KEY
var message = await client.Sms.SendAsync("0812345678", "Votre commande 1042 est prete.");
Console.WriteLine(message.Status);                                                              // Queued
```

Créez vos clés dans l'espace client (*Développeurs > Clés API*). **Gardez-les côté serveur**, jamais dans un
navigateur ni dans une application mobile.

| Clé | Mode | Effet |
|---|---|---|
| `lsk_test_…` | Bac à sable | Rien n'est envoyé, aucun crédit n'est consommé. Mêmes réponses qu'en production. L'envoi d'OTP renvoie le code (`TestCode`). |
| `lsk_live_…` | Production | Les SMS partent réellement et consomment des crédits. |

`client.IsTestMode` indique le mode utilisé. Tous les exemples ci-dessous fonctionnent avec une clé de test. Un
`LabyrintheClient` est thread-safe : créez-en un (ou enregistrez-le avec `AddLabyrinthe`) et réutilisez-le.

## Envoyer des SMS

```csharp
var message = await client.Sms.SendAsync(new SendSmsRequest
{
    To = "+243 81 234 5678",        // +243…, 00243…, 243…, 0812…, 812…, 33612345678 sont tous acceptés
    Body = "Votre commande 1042 est prete.",
    Sender = "MABOUTIQUE",          // facultatif : un Sender ID approuvé (client.SenderIds.ListAsync())
    Reference = "commande-1042",    // facultatif : votre référence, 64 caractères au plus
});

message.Id              // "9d5c0a3e-…"
message.Recipient       // "243812345678"
message.Status          // MessageStatus.Queued
message.Segments        // 1
message.CreditsCharged  // 1
message.CreatedAt       // DateTimeOffset (UTC)
```

L'envoi est asynchrone : `202 Accepted` signifie *mis en file et payé*, pas *remis*. Suivez le statut par webhook
(`message.status_changed`, recommandé) ou en interrogeant l'API :

```csharp
var latest = await client.Sms.GetAsync(message.Id);
if (latest.Status.IsFinal())
{
    Console.WriteLine(latest.Status == MessageStatus.Delivered ? "Remis" : $"Échec : {latest.ErrorCode}");
}
```

### Coût avant l'envoi

1 crédit = 1 segment SMS. Un message en ASCII pur (GSM-7) tient en 160 caractères (153 par segment au-delà).
**Tout caractère non ASCII** (é, à, ç, emoji, guillemets typographiques…) passe en UCS-2 : 70 caractères (67 par
segment au-delà).

```csharp
var estimate = await client.Sms.EstimateAsync(new EstimateSmsRequest
{
    Body = "Réunion demain à 9h",
    Recipients = new[] { "0812345678", "0899999999" },
});
estimate.Encoding      // SmsEncoding.Ucs2
estimate.TotalCredits  // 2
estimate.IsAffordable  // le solde couvre-t-il l'envoi ?
```

`StripAccents = true` (sur `SendSmsRequest`, `SendManySmsRequest`, `EstimateSmsRequest` et `SendOtpRequest`) fait
remplacer par le serveur accents et caractères typographiques par leur équivalent ASCII : le message passe en GSM-7,
souvent deux fois moins cher. La translittération dépend du serveur : n'en dépendez pas pour un texte exact.

Numéros : sans `+`, 11 chiffres ou plus ne commençant pas par 0 sont déjà internationaux (`33612345678` reste
`33612345678`) ; sinon le numéro est local et reçoit l'indicatif par défaut (`0812345678` → `243812345678`). Pour un
numéro étranger court (10 chiffres ou moins), utilisez toujours le `+`.

### Plusieurs destinataires : tout ou rien

```csharp
try
{
    var batch = await client.Sms.SendManyAsync(new SendManySmsRequest
    {
        Recipients = new[] { "0812345678", "0899999999" },
        Body = "Reunion demain a 9h.",
    });
    // batch.Messages : un message par numéro distinct (les doublons sont fusionnés)
}
catch (RecipientNotSendableException error)
{
    // RIEN n'a été envoyé : corrigez la liste et recommencez.
    foreach (var rejected in error.Rejected)
    {
        Console.WriteLine($"{rejected.Recipient} : {rejected.Reason}");   // InvalidPhone, Blacklisted, OptedOut
    }
}
```

Jusqu'à 1000 destinataires par appel. Tous les destinataires, le Sender ID et le solde sont vérifiés **avant** le
moindre envoi : un seul numéro refusé (ou un solde insuffisant) et aucun SMS ne part. Les numéros que le SDK sait
déjà invalides sont refusés localement (`ArgumentException`, avec leur position seulement), avant tout appel réseau.

### Idempotence (jamais de double envoi)

Chaque envoi porte un `Idempotency-Key`. Sans clé fournie, le SDK génère un `Guid` et le réutilise pour ses propres
relances : une erreur réseau ne produit jamais un second SMS. Pour vous protéger aussi de **vos** relances (tâches de
fond, double clic, plantage), passez une clé dérivée de votre événement métier. Un rejeu dans les 24 h renvoie les
messages d'origine sans rien renvoyer ni facturer :

```csharp
var batch = await client.Sms.SendManyAsync(new SendManySmsRequest
{
    Recipients = new[] { "0812345678" },
    Body = "Votre commande 1042 est prete.",
    IdempotencyKey = "commande-1042-prete",
});
batch.Replayed   // true pour un rejeu
```

La même clé avec un autre contenu est refusée : `IdempotencyKeyReusedException` (409). Tant que la requête d'origine
est en cours, l'API répond `409 IDEMPOTENCY_REQUEST_IN_PROGRESS` : le SDK attend `Retry-After` et relance avec la même
clé (`IdempotencyRequestInProgressException` si c'est toujours le cas après ses relances).

`IdempotencyKeyUnresolvedException` (409 `IDEMPOTENCY_KEY_UNRESOLVED`, `SendAsync`, `SendManyAsync` et
`Otp.SendAsync`) signifie que la requête d'origine avec cette clé a échoué de façon inattendue côté API : son
résultat est inconnu et **un SMS a pu partir**. Le SDK ne la relance jamais, et vous ne devez pas la relancer
automatiquement non plus. Vérifiez d'abord : `client.Sms.ListAsync(new ListSmsOptions { Reference = "commande-1042" })`
pour un SMS envoyé avec une `Reference`, ou demandez à l'utilisateur s'il a reçu le code pour un OTP. Puis, seulement
si rien n'est parti, renvoyez avec une **nouvelle** `Idempotency-Key` (l'ancienne reste non résolue). Journalisez
`RequestId` pour le support.

```csharp
try
{
    await client.Sms.SendAsync(new SendSmsRequest { To = "0812345678", Body = "…", Reference = "commande-1042", IdempotencyKey = cle });
}
catch (IdempotencyKeyUnresolvedException e)
{
    logger.LogWarning("Résultat inconnu (requête {RequestId}) : vérifier GET /sms?reference=commande-1042 avant de renvoyer avec une nouvelle clé.", e.RequestId);
}
```

### Lister les messages

```csharp
var page = await client.Sms.ListAsync(new ListSmsOptions
{
    Status = MessageStatus.Failed,
    From = DateFilter.Day(2026, 11, 1),    // une journée entière (ou un DateOnly)
    To = DateTimeOffset.UtcNow,            // un instant précis
    PerPage = 50,
});
// page.Data, page.Total, page.HasMore, await page.GetNextPageAsync()

await foreach (var message in client.Sms.ListAutoPagingAsync(new ListSmsOptions { Reference = "commande-1042" }))
{
    // toutes les pages, chargées au fil de l'eau
}
```

Filtres : `Status`, `Recipient` (tout format), `Reference`, `From`, `To` (inclus), `Page`, `PerPage` (100 au plus).
Seulement les messages envoyés **avec la clé appelante**.

### Solde et Sender IDs

```csharp
var balance = await client.Balance.GetAsync();        // balance.Available, balance.Held
var senderIds = await client.SenderIds.ListAsync();   // noms approuvés, utilisables comme Sender
```

## OTP : vérifier un numéro

Le code est généré, envoyé par SMS et vérifié par Labyrinthe Connect. L'API ne le renvoie jamais (sauf avec une clé
de test) et le SDK ne le journalise jamais.

```csharp
// 1. Envoyer le code. Gardez OtpId CÔTÉ SERVEUR (session, cache, base), jamais dans le navigateur.
var otp = await client.Otp.SendAsync("0812345678");
HttpContext.Session.SetString("otp_id", otp.OtpId);
// otp.CodeLength (4 à 8), otp.CodeType (Numeric / Alphanumeric) : taille du champ et clavier.
// otp.ResendAfterSeconds : compte à rebours du bouton « Renvoyer le code ».

// 2. L'utilisateur saisit le code reçu par SMS.

// 3. Le vérifier.
var result = await client.Otp.VerifyAsync(HttpContext.Session.GetString("otp_id")!, typedCode);
if (result.Verified)
{
    // connecter l'utilisateur / marquer le numéro comme vérifié
}
else if (result.Reason == OtpFailureReason.InvalidCode)
{
    // code faux : une tentative consommée, il en reste result.AttemptsRemaining
}
else
{
    // Expired, AlreadyUsed, MaxAttemptsReached : proposer un nouveau code
}
```

- Un code faux n'est **pas** une exception : `VerifyAsync` renvoie `OtpVerifyResult { Verified = false, Reason = … }`.
- Un nouvel envoi au même numéro invalide le code précédent.
- Longueur, validité, tentatives, Sender ID et modèle viennent des paramètres OTP du compte. Vous pouvez imposer le
  modèle (`new SendOtpRequest { Phone = …, Template = "Votre code MaBoutique : {code}" }`, avec `{code}` obligatoire ;
  `{app_name}` et `{minutes}` facultatifs) et la langue (`Locale = OtpLocale.Fr` ou `En`).
- `WebOtpDomain = "app.exemple.com"` ajoute la ligne `@app.exemple.com #123456` au SMS pour le remplissage
  automatique ([Web OTP API](https://developer.mozilla.org/docs/Web/API/WebOTP_API), Chrome, Android).
- Limites : `OtpResendTooSoonException` et `OtpRateLimitedException` (`Scope`, `Limit`), toutes deux avec
  `RetryAfter` (en-tête `Retry-After`). Jamais relancées par le SDK.
- **Mode test** : avec une clé `lsk_test_`, aucun SMS n'est envoyé et `otp.TestCode` contient le code, pour tester
  tout le parcours. Toujours `null` avec une clé live ; jamais affiché par `ToString()`.

`Otp.SendAsync` porte toujours un `Idempotency-Key` (`SendOtpRequest.IdempotencyKey`, ou un `Guid` généré et réutilisé
par ses relances) : il est relancé sans risque après une erreur réseau, une 5xx ou un 409
`IDEMPOTENCY_REQUEST_IN_PROGRESS`, et un rejeu renvoie le même `OtpId` sans second SMS (`otp.Replayed`). `VerifyAsync`
n'est relancé que sur 429 `TOO_MANY_REQUESTS` (sinon une tentative a pu être consommée).

L'issue de chaque vérification est aussi notifiée par les webhooks `otp.verified` et `otp.failed`.

## Webhooks

Configurez un endpoint dans l'espace client (*Développeurs > Webhooks*) et copiez son secret. Les événements arrivent
en `POST` JSON, signés (`X-Signature: sha256=<HMAC-SHA256 du corps brut>`). Le SDK vérifie la signature en temps
constant (`CryptographicOperations.FixedTimeEquals`) sur le **corps brut**, puis l'horodatage signé (5 minutes par
défaut).

```csharp
try
{
    var evt = Webhook.ConstructEvent(rawBody, request.Headers["X-Signature"], secret);   // corps brut : string ou byte[]
    switch (evt)
    {
        case MessageStatusChanged changed:
            await UpdateMessageAsync(changed.Data.MessageId, changed.Data.Status);
            break;
        case BalanceLow low:
            await AlertFinanceAsync(low.Data.Balance, low.Data.Threshold);
            break;
    }
}
catch (SignatureVerificationException)
{
    // répondre 400
}
```

Chaque événement a `Id` (dédoublonnez dessus : jusqu'à 5 tentatives de livraison, ordre non garanti), `Type`,
`EventType`, `Timestamp` (`DateTimeOffset`, UTC), `Raw` et un `Data` typé :

| Type | `Type` | `Data` |
|---|---|---|
| `MessageStatusChanged` | `message.status_changed` | `MessageId`, `Recipient`, `Status`, `ProviderMessageId`, `CampaignId`, `Reference`, `ErrorCode`, `ErrorMessage` |
| `OtpVerified` | `otp.verified` | `OtpId`, `Phone`, `Status` (`Verified`), `Reason` (`null`), `VerifiedAt` |
| `OtpFailed` | `otp.failed` | `OtpId`, `Phone`, `Status` (`Failed` / `Expired`), `Reason` (`MaxAttemptsReached` / `Expired`) |
| `CampaignCompleted` | `campaign.completed` | `CampaignId`, `Name`, `RecipientsCount`, `MessagesByStatus`, `CreditsCharged`, `CompletedAt` |
| `BalanceLow` | `balance.low` | `Balance`, `Threshold` |
| `TestPing` | `test.ping` | `JsonElement` (`{}`) |
| `UnknownEvent` | un type ajouté après cette version du SDK | `JsonElement` tel quel ; accusez réception (2xx) |

Signature invalide ou horodatage expiré : `SignatureVerificationException` ; corps signé qui n'est pas une enveloppe :
`InvalidWebhookPayloadException` — répondez `400` dans les deux cas. Répondez `2xx` en moins de 10 secondes et
mettez le travail lent en file. `Webhook.Sign(body, secret)` construit un en-tête valide pour vos tests.

## ASP.NET Core

```csharp
// appsettings.json : { "Labyrinthe": { "ApiKey": "lsk_test_…", "WebhookSecret": "…" } }
builder.Services.AddLabyrinthe(builder.Configuration);              // LabyrintheClient via IHttpClientFactory
builder.Services.AddLabyrintheWebhookHandler<MonHandler>();         // ILabyrintheWebhookHandler (scoped)

var app = builder.Build();
app.MapLabyrintheWebhooks("/webhooks/labyrinthe");                  // POST : corps brut, signature, horodatage

app.MapPost("/notify", async (string to, LabyrintheClient client) =>
    (await client.Sms.SendAsync(to, "Votre commande est prete.")).Id);

sealed class MonHandler : ILabyrintheWebhookHandler
{
    public Task HandleAsync(WebhookEvent webhookEvent, CancellationToken cancellationToken) => …;
}
```

- La section `Labyrinthe` lie toutes les propriétés de `LabyrintheClientOptions` (`ApiKey`, `BaseUrl`, `Timeout`,
  `MaxRetries`, `MaxRetryWait`, `DefaultCountryCode`, `DefaultSender`) et `LabyrintheWebhookOptions`
  (`WebhookSecret`, `WebhookTolerance`, `WebhookMaxBodySize`). Les variables d'environnement fonctionnent aussi
  (`Labyrinthe__ApiKey`) ; sans clé, `LABYRINTHE_API_KEY` est lue, sans secret `LABYRINTHE_WEBHOOK_SECRET`.
- Les journaux du SDK vont dans `ILogger<LabyrintheClient>` (catégorie `Labyrinthe.Connect.LabyrintheClient`).
- `AddLabyrinthe` renvoie l'`IHttpClientBuilder` : ajoutez un proxy ou un handler, mais **aucune politique de
  relance** (le SDK relance déjà quand c'est sûr, et jamais sinon). Les redirections ne sont pas suivies.
- `MapLabyrintheWebhooks(pattern, (evt, ct) => …)` et `MapLabyrintheWebhooks(pattern, instance)` existent aussi.
  Signature fausse, horodatage expiré ou enveloppe invalide : `400` ; corps au-delà de `WebhookMaxBodySize` (1 Mio) :
  `413` ; exception dans votre handler : `500` (l'API relivrera l'événement).

Voir [`examples/WebhookMinimalApi`](examples/WebhookMinimalApi).

## Erreurs

Toutes les exceptions héritent de `LabyrintheException` et exposent `Code` (stable, pour votre code), `Message`,
`StatusCode` (statut HTTP, ou `null` sans réponse) et `Details` (`IReadOnlyDictionary<string, JsonElement>`). Les
erreurs de l'API héritent d'`ApiException` et exposent aussi `Headers`, `RequestId` (`X-Request-Id`, à communiquer au
support) et `ApiVersion`.

Les résultats (`MessageBatch`, `Estimate`, `Balance`, `OtpSendResult`, `OtpVerifyResult`, `Page<T>`) exposent aussi
`RequestId`. Pour fournir votre propre identifiant (trace, numéro de commande), passez par une vue légère du client :

```csharp
var batch = await client.WithRequestId("commande-1042").Sms.SendManyAsync(request);   // 1 à 128 car. : A-Z a-z 0-9 . _ : -
batch.RequestId   // "commande-1042"
```

| Exception | Cas |
|---|---|
| `AuthenticationException` | 401 — clé absente, invalide ou révoquée |
| `PermissionException` | 403 — IP non autorisée (`FORBIDDEN`), OTP désactivé pour le compte (`OTP_DISABLED_FOR_ACCOUNT`) |
| `AccountSuspendedException` | 403 `ACCOUNT_SUSPENDED` — hérite de `PermissionException` ; toute l'API est fermée |
| `NotFoundException` | 404 — message ou `otp_id` inconnu |
| `ValidationException` | 422 `VALIDATION_ERROR` — `FieldErrors` |
| `RecipientNotSendableException` | 422 — `Rejected`, `Reason` ; rien n'a été envoyé |
| `SenderIdNotApprovedException` | 422 — `Sender` n'est pas un Sender ID approuvé |
| `InvalidPhoneException` | 422 `INVALID_PHONE` (OTP) |
| `InsufficientCreditsException` | 409 — `Available`, `Required` ; rien n'a été envoyé |
| `IdempotencyKeyReusedException` | 409 — même clé, requête différente |
| `IdempotencyRequestInProgressException` | 409 — requête d'origine encore en cours (`RetryAfter`) ; déjà relancée |
| `IdempotencyKeyUnresolvedException` | 409 — requête d'origine en échec inattendu, résultat inconnu (un SMS a pu partir) ; jamais relancée : vérifier, puis renvoyer avec une nouvelle clé |
| `RateLimitException` | 429 `TOO_MANY_REQUESTS` — `RetryAfter` |
| `OtpResendTooSoonException`, `OtpRateLimitedException` | 429 — héritent de `RateLimitException` ; `Scope`, `Limit` |
| `ServiceUnavailableException` | 5xx, dont 503 `OTP_SERVICE_DISABLED` |
| `ApiException` | tout autre code (liste ouverte : de nouveaux codes peuvent apparaître) |
| `ApiConnectionException`, `ApiTimeoutException` | erreur réseau (`CONNECTION_ERROR`) ou délai dépassé (`TIMEOUT`) |
| `UnexpectedResponseException` | réponse illisible pour le SDK |
| `SignatureVerificationException`, `InvalidWebhookPayloadException` | vérification de webhook (répondre 400) |

Une saisie invalide détectée avant tout appel lève `ArgumentException` (les numéros n'apparaissent jamais dans le
message). Annuler votre `CancellationToken` lève `OperationCanceledException`, jamais `ApiTimeoutException`.

## Relances et délais

Le SDK relance automatiquement (par défaut 2 relances, backoff exponentiel 0,5 s → 1 s → 2 s avec jitter)
**seulement quand un rejeu ne peut pas produire deux fois un effet** :

| Requête | Erreur réseau / délai | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` |
|---|---|---|---|---|
| `GetAsync`, `ListAsync`, `Balance`, `SenderIds`, `EstimateAsync` | relancée | relancée | relancée après `Retry-After` | — |
| `SendAsync` / `SendManyAsync` / `Otp.SendAsync` (toujours avec `Idempotency-Key`) | relancée, même clé | relancée, même clé | relancée après `Retry-After` | relancée après `Retry-After`, même clé |
| `Otp.VerifyAsync` | **jamais** | **jamais** | relancée (refusée avant traitement) | **jamais** |

Les erreurs métier (4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, `IDEMPOTENCY_KEY_UNRESOLVED`) ne sont jamais relancées. Si l'API demande
d'attendre plus que `MaxRetryWait` (10 s par défaut), le SDK lève `RateLimitException` au lieu d'attendre. Les
redirections ne sont jamais suivies. `Timeout` s'applique à chaque tentative ; votre `CancellationToken` borne
l'appel entier (relances comprises).

## Configuration

```csharp
var client = new LabyrintheClient(new LabyrintheClientOptions
{
    ApiKey = Environment.GetEnvironmentVariable("LABYRINTHE_API_KEY"),   // par défaut
    BaseUrl = "https://api.labyrinthe-rdc.com/api/public/v1",          // par défaut (ou LABYRINTHE_BASE_URL) ; un hôte seul est complété
    Timeout = TimeSpan.FromSeconds(30),     // par tentative
    MaxRetries = 2,                         // 0 désactive les relances (10 au plus)
    MaxRetryWait = TimeSpan.FromSeconds(10),// plus long Retry-After accepté
    DefaultCountryCode = "243",             // ajouté aux numéros locaux : 0812345678 → 243812345678
    DefaultSender = "MABOUTIQUE",           // utilisé quand un envoi n'a pas de Sender
    Logger = LabyrintheLogger.Create((level, message) => Console.Error.WriteLine($"[{level}] {message}")),
}, httpClient: monHttpClient);              // facultatif : votre HttpClient (jamais libéré par le SDK)
```

- **Journaux** : les enregistrements (debug : chaque tentative ; information : erreurs de l'API ; warning :
  relances ; error : erreurs réseau) ne contiennent jamais la clé, un code, un corps ni un numéro complet
  (`24381*****78`). Rien n'est journalisé sans `Logger`.
- `client.ToString()` affiche `lsk_test_****` ; `OtpSendResult.ToString()` masque `TestCode` ; `Message.ToString()`
  masque le destinataire.
- `PhoneNumber.Normalize("0812345678")` renvoie `"243812345678"` (ou `null`) avec les règles de l'API ; `IsValid()`
  et `Mask()` existent aussi. Les numéros internationaux ne sont jamais bloqués. Les requêtes envoient toujours le
  numéro au format E.164 avec « + » (`+243812345678`) : un numéro étranger court garde son indicatif.
- Avec votre propre `HttpClient`, configurez son handler avec `AllowAutoRedirect = false` et laissez son `Timeout`
  au-dessus de celui du SDK.
- `User-Agent: labyrinthe-dotnet/<version> .NET/<version du runtime>`.

## Développement

```bash
dotnet build -c Release -warnaserror      # TreatWarningsAsErrors + analyseurs .NET
dotnet test -c Release                    # tests unitaires + contrat OpenAPI (HTTP simulé, sans réseau)
dotnet format --verify-no-changes
dotnet pack -c Release -o artifacts       # .nupkg + .snupkg

# tests d'intégration contre un backend local, avec une clé de TEST uniquement
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 dotnet test --filter Category=Integration
```

Le contrat de l'API est [`openapi/openapi.yaml`](../openapi/openapi.yaml) ; les tests de contrat y maintiennent le
SDK aligné. Exemples : [`examples/`](examples) (SMS en console, connexion par OTP, réception de webhooks ASP.NET Core).

## Licence

MIT — voir [LICENSE](LICENSE).

# Labyrinthe Connect — SDK .NET

[![CI](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/dotnet.yml/badge.svg)](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/dotnet.yml)
[![NuGet](https://img.shields.io/nuget/v/Labyrinthe.Connect.svg?label=Labyrinthe.Connect)](https://www.nuget.org/packages/Labyrinthe.Connect)
[![NuGet](https://img.shields.io/nuget/v/Labyrinthe.Connect.AspNetCore.svg?label=Labyrinthe.Connect.AspNetCore)](https://www.nuget.org/packages/Labyrinthe.Connect.AspNetCore)
![.NET](https://img.shields.io/badge/.NET-netstandard2.0%20%7C%20net8.0-512BD4)
![Licence](https://img.shields.io/badge/licence-MIT-green)

Client .NET officiel de [Labyrinthe Connect](https://connect.labyrinthe-rdc.com) : envoyez des SMS et vérifiez des numéros de
téléphone par code à usage unique (OTP), en RDC et en Afrique centrale.

*English version: [README.md](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/dotnet/README.md).*

| Package | Contenu |
|---|---|
| [`Labyrinthe.Connect`](https://www.nuget.org/packages/Labyrinthe.Connect) | Le client : SMS, solde, Sender IDs, OTP, vérification des webhooks. |
| [`Labyrinthe.Connect.AspNetCore`](https://www.nuget.org/packages/Labyrinthe.Connect.AspNetCore) | `services.AddLabyrinthe(configuration)` (`IHttpClientFactory`, `ILogger`) et `app.MapLabyrintheWebhooks()` (point d'entrée minimal API pour les webhooks signés). |

- `async` partout, avec `CancellationToken` ; pagination `IAsyncEnumerable` ; annotations de nullabilité ; records immuables.
- Sûr par défaut : relances automatiques **uniquement** quand elles ne peuvent pas envoyer deux fois un SMS ou un OTP.
- Exceptions typées pour chaque erreur de l'API, dont l'erreur de maintenance `FeatureDisabledException`.
- Une dépendance en `netstandard2.0` (`System.Text.Json`), aucune en `net8.0` (compatible trimming et AOT).
- Ne journalise ni n'affiche jamais votre clé API, les codes OTP ou les numéros complets.

**Sommaire :** [Prérequis](#prérequis) · [Installation](#installation) · [Démarrage rapide](#démarrage-rapide) ·
[Configuration](#configuration) · [Envoyer des SMS](#envoyer-des-sms) · [Solde et Sender IDs](#solde-et-sender-ids) ·
[OTP](#otp--vérifier-un-numéro-de-téléphone) · [Webhooks](#webhooks) · [Erreurs](#erreurs) ·
[Relances et idempotence](#relances-et-idempotence) · [Mode test, journalisation et sécurité](#mode-test-journalisation-et-sécurité) ·
[Sans le package](#sans-le-package-http-brut) · [Mise à jour](#mise-à-jour) · [Contribuer](#contribuer)

## Prérequis

| Package | Frameworks cibles | Fonctionne sur |
|---|---|---|
| `Labyrinthe.Connect` | `netstandard2.0`, `net8.0` | .NET 8+ (build `net8.0`), .NET 6 / 7, .NET Framework 4.7.2+, Mono, Unity (build `netstandard2.0`) |
| `Labyrinthe.Connect.AspNetCore` | `net8.0` | ASP.NET Core 8+ |

Testé sur .NET 8 et .NET 9 (Linux et Windows). La construction du dépôt demande le SDK .NET 8 ou plus récent.

## Installation

```bash
dotnet add package Labyrinthe.Connect
dotnet add package Labyrinthe.Connect.AspNetCore   # facultatif : intégration ASP.NET Core (inclut Labyrinthe.Connect)
```

Ou dans votre `.csproj` :

```xml
<ItemGroup>
  <PackageReference Include="Labyrinthe.Connect" Version="1.2.1" />
  <!-- ASP.NET Core : celui-ci suffit, il apporte Labyrinthe.Connect -->
  <PackageReference Include="Labyrinthe.Connect.AspNetCore" Version="1.2.1" />
</ItemGroup>
```

## Démarrage rapide

```csharp
using Labyrinthe.Connect;

using var client = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_…" }); // ou LABYRINTHE_API_KEY
var message = await client.Sms.SendAsync("0812345678", "Votre commande 1042 est prête.");
Console.WriteLine(message.Status);                                                              // Queued
```

Créez vos clés dans l'espace client (*Développeurs > Clés API*). **Gardez-les côté serveur**, jamais dans un
navigateur ni dans une application mobile.

| Clé | Mode | Effet |
|---|---|---|
| `lsk_test_…` | Bac à sable | Rien n'est envoyé, aucun crédit n'est consommé. Mêmes réponses qu'en production. L'envoi d'OTP renvoie le code (`TestCode`). |
| `lsk_live_…` | Production | Les SMS partent réellement et consomment des crédits. |

`client.IsTestMode` / `client.IsLiveMode` indiquent le mode utilisé. Tous les exemples ci-dessous fonctionnent avec
une clé de test. Un `LabyrintheClient` est thread-safe et garde un pool de connexions : créez-en un (ou
enregistrez-le avec `AddLabyrinthe`) et réutilisez-le.

## Configuration

### Options

| `LabyrintheClientOptions` | Défaut | Rôle |
|---|---|---|
| `ApiKey` | `LABYRINTHE_API_KEY` | `lsk_live_…` ou `lsk_test_…`. Obligatoire (une `ArgumentException` est levée sans clé). |
| `BaseUrl` | `LABYRINTHE_BASE_URL`, puis `https://api.connect.labyrinthe-rdc.com/api/public/v1` | Racine de l'API. Un hôte seul (`http://localhost:8000`) est complété par `/api/public/v1`. |
| `Timeout` | 30 s | Délai de **chaque tentative**. |
| `MaxRetries` | 2 | Relances automatiques quand elles sont sûres ; `0` les désactive (10 au maximum). |
| `MaxRetryWait` | 10 s | Plus long `Retry-After` que le SDK accepte d'attendre ; au-delà, l'erreur est levée. |
| `DefaultCountryCode` | `"243"` | Ajouté aux numéros locaux (`0812345678` → `243812345678`) ; 1 à 3 chiffres. |
| `DefaultSender` | `null` | Sender ID utilisé quand un envoi n'a pas de `Sender`. |
| `Logger` | `null` (aucun journal) | Un `ILabyrintheLogger` (voir [Journalisation](#journalisation)). |

```csharp
using var client = new LabyrintheClient(new LabyrintheClientOptions
{
    ApiKey = Environment.GetEnvironmentVariable("LABYRINTHE_API_KEY"),
    BaseUrl = "https://api.connect.labyrinthe-rdc.com/api/public/v1",
    Timeout = TimeSpan.FromSeconds(30),
    MaxRetries = 2,
    MaxRetryWait = TimeSpan.FromSeconds(10),
    DefaultCountryCode = "243",
    DefaultSender = "MABOUTIQUE",
    Logger = LabyrintheLogger.Create((level, message) => Console.Error.WriteLine($"[{level}] {message}")),
}, httpClient: monHttpClient);   // facultatif : votre propre HttpClient (proxy…), jamais libéré par le SDK
```

`new LabyrintheClient()` sans argument lit `LABYRINTHE_API_KEY` et `LABYRINTHE_BASE_URL`. Si vous fournissez votre
propre `HttpClient`, configurez son handler avec `AllowAutoRedirect = false` et laissez son `Timeout` au-dessus de
celui du SDK (`Timeout.InfiniteTimeSpan` convient : le SDK limite lui-même chaque tentative). Sans lui, le SDK crée
et possède le sien (`Dispose()` le ferme).

### Variables d'environnement

| Variable | Utilisée pour |
|---|---|
| `LABYRINTHE_API_KEY` | Clé API, quand `ApiKey` n'est pas renseignée |
| `LABYRINTHE_BASE_URL` | Racine de l'API, quand `BaseUrl` n'est pas renseignée (ex. `http://localhost:8000` pour un backend local) |
| `LABYRINTHE_WEBHOOK_SECRET` | Secret de webhook de `MapLabyrintheWebhooks()`, quand `WebhookSecret` n'est pas renseigné |

### ASP.NET Core et injection de dépendances

```json
// appsettings.json (gardez les vraies clés dans les user secrets ou des variables d'environnement)
{
  "Labyrinthe": {
    "ApiKey": "lsk_test_…",
    "WebhookSecret": "…",
    "Timeout": "00:00:30",
    "MaxRetries": 2,
    "MaxRetryWait": "00:00:10",
    "DefaultCountryCode": "243",
    "DefaultSender": "MABOUTIQUE",
    "WebhookTolerance": "00:05:00",
    "WebhookMaxBodySize": 1048576
  }
}
```

```csharp
using Labyrinthe.Connect;
using Labyrinthe.Connect.AspNetCore;

var builder = WebApplication.CreateBuilder(args);
builder.Services.AddLabyrinthe(builder.Configuration);   // section « Labyrinthe » (ou AddLabyrinthe(configuration, "MaSection"))

var app = builder.Build();
app.MapPost("/notify", async (string to, LabyrintheClient client, CancellationToken ct) =>
    (await client.Sms.SendAsync(to, "Votre commande est prête.", ct)).Id);
app.Run();
```

- La section lie toutes les propriétés de `LabyrintheClientOptions` ci-dessus et les `LabyrintheWebhookOptions`
  (`WebhookSecret`, `WebhookTolerance` = 5 min, `WebhookMaxBodySize` = 1 Mio). Les variables d'environnement
  fonctionnent aussi (`Labyrinthe__ApiKey`, `Labyrinthe__WebhookSecret`) ; sans clé, le client se rabat sur
  `LABYRINTHE_API_KEY`.
- Options dans le code : `builder.Services.AddLabyrinthe(options => options.ApiKey = "lsk_test_…")`.
- `LabyrintheClient` est un client typé d'`IHttpClientFactory` (client nommé `Labyrinthe.Connect`) : injectez-le où
  vous voulez. Les journaux du SDK vont dans `ILogger<LabyrintheClient>` (catégorie
  `Labyrinthe.Connect.LabyrintheClient`), sauf si vous renseignez `Logger` vous-même.
- `AddLabyrinthe` renvoie l'`IHttpClientBuilder` : ajoutez un proxy ou un `DelegatingHandler`, mais **aucune
  politique de relance ou de résilience** (`AddStandardResilienceHandler`, Polly…). Le SDK relance déjà quand c'est
  sûr, et une couche de relance supplémentaire pourrait envoyer deux fois un OTP. Les redirections ne sont pas suivies.

## Envoyer des SMS

```csharp
var message = await client.Sms.SendAsync(new SendSmsRequest
{
    To = "+243 81 234 5678",        // +243…, 00243…, 243…, 0812…, 812…, 33612345678 sont acceptés
    Body = "Votre commande 1042 est prête.",
    Sender = "MABOUTIQUE",          // facultatif : un Sender ID approuvé (client.SenderIds.ListAsync())
    Reference = "commande-1042",    // facultatif : votre référence, 64 caractères au maximum
    StripAccents = false,           // facultatif : voir « Coût avant envoi »
    IdempotencyKey = "commande-1042-prete", // facultatif : voir « Idempotence »
});

message.Id              // "9d5c0a3e-…"
message.Recipient       // "243812345678"
message.Status          // MessageStatus.Queued
message.Segments        // 1
message.CreditsCharged  // 1
message.CreatedAt       // DateTimeOffset?
```

`client.Sms.SendAsync(to, body)` est la forme courte. Le texte d'un message n'est jamais renvoyé par l'API (il peut
contenir un code).

L'envoi est asynchrone : `202 Accepted` signifie *mis en file et payé*, pas *remis*. Suivez le statut par webhook
(`message.status_changed`, recommandé) ou en interrogeant l'API :

```csharp
var latest = await client.Sms.GetAsync(message.Id);
if (latest.Status.IsFinal())   // aussi IsFailed(), IsPending()
{
    Console.WriteLine(latest.Status == MessageStatus.Delivered ? "Remis" : $"Échec : {latest.ErrorCode}");
}
```

`MessageStatus` : `Queued`, `Submitted`, `Sent`, `Delivered`, `Failed`, `Rejected`, `Expired`, `Undeliverable`, et
`Unknown` pour une valeur ajoutée après cette version du SDK. `status.ToApiString()` donne la valeur de l'API
(`"queued"`).

### Coût avant envoi

1 crédit = 1 segment SMS. Un message en ASCII pur (GSM-7) tient en 160 caractères (153 par segment au-delà). **Tout
caractère non ASCII** (é, à, ç, emoji, guillemets typographiques…) passe en UCS-2 : 70 caractères (67 par segment
au-delà).

```csharp
var estimate = await client.Sms.EstimateAsync(new EstimateSmsRequest
{
    Body = "Réunion demain à 9h",
    Recipients = new[] { "0812345678", "0899999999" },
    StripAccents = false,
});
estimate.Encoding            // SmsEncoding.Ucs2
estimate.SegmentsPerMessage  // 1
estimate.RecipientsCount     // 2 (normalisés, doublons fusionnés, numéros invalides ignorés)
estimate.TotalCredits        // 2
estimate.BalanceAfter        // int?
estimate.IsAffordable        // BalanceAfter >= 0
```

`StripAccents = true` (sur `SendSmsRequest`, `SendManySmsRequest`, `EstimateSmsRequest` et `SendOtpRequest`) demande
au serveur de remplacer accents et caractères typographiques par de l'ASCII : le message passe en GSM-7, souvent
deux fois moins cher. La translittération est faite par le serveur ; ne l'utilisez pas pour un texte exact.

**Numéros de téléphone.** `+` ou `00` conserve l'indicatif ; sans `+`, 11 chiffres ou plus ne commençant pas par 0
sont déjà internationaux (`33612345678` reste `33612345678`) ; sinon le numéro est local et reçoit
`DefaultCountryCode` (`0812345678` → `243812345678`). Pour un numéro étranger court (10 chiffres ou moins), utilisez
toujours le `+`. Le SDK envoie chaque numéro en E.164 avec `+` ; les réponses utilisent le format international sans
`+`. `PhoneNumber.Normalize("0812345678")` (`"243812345678"` ou `null`), `PhoneNumber.IsValid()` et
`PhoneNumber.Mask()` appliquent les mêmes règles en local.

### Plusieurs destinataires : tout ou rien

```csharp
try
{
    var batch = await client.Sms.SendManyAsync(new SendManySmsRequest
    {
        Recipients = new[] { "0812345678", "0899999999" },
        Body = "Réunion demain à 9h.",
        Sender = "MABOUTIQUE",
        Reference = "reunion-2026-11-07",
    });
    // batch.Messages : un message par numéro distinct (les doublons sont fusionnés)
    // batch.IdempotencyKey, batch.Replayed, batch.RequestId
}
catch (RecipientNotSendableException error)
{
    // RIEN n'est parti : corrigez la liste et réessayez.
    foreach (var rejected in error.Rejected)
    {
        Console.WriteLine($"{rejected.Recipient} : {rejected.Reason}");   // InvalidPhone, Blacklisted, OptedOut
    }
}
```

Jusqu'à 1000 destinataires par appel (`Labyrinthe.Connect.Resources.SmsResource.MaxRecipients`). Chaque destinataire, le Sender ID et le solde
sont vérifiés **avant** tout envoi : un seul numéro refusé (ou un solde insuffisant) et aucun SMS ne part. Les
numéros que le SDK sait déjà invalides sont refusés localement (`ArgumentException`, avec leur position seulement),
avant tout appel réseau.

### Lister les messages

```csharp
var page = await client.Sms.ListAsync(new ListSmsOptions
{
    Status = MessageStatus.Failed,
    Recipient = "0812345678",              // tout format
    From = DateFilter.Day(2026, 11, 1),    // une journée entière (un DateOnly se convertit implicitement en net8.0)
    To = DateTimeOffset.UtcNow,            // un instant précis
    Page = 1,
    PerPage = 50,                          // 15 par défaut, 100 au maximum
});
// page.Data, page.CurrentPage, page.LastPage, page.Total, page.HasMore
var next = await page.GetNextPageAsync();  // null sur la dernière page

await foreach (var message in client.Sms.ListAutoPagingAsync(new ListSmsOptions { Reference = "commande-1042" }))
{
    // toutes les pages, chargées à la demande
}
```

Filtres : `Status`, `Recipient`, `Reference`, `From`, `To` (inclus), `Page`, `PerPage`. La liste ne contient que les
messages envoyés **avec la clé appelante** ; `GetAsync(id)` trouve n'importe quel message du compte, par `Id`
uniquement.

## Solde et Sender IDs

```csharp
var balance = await client.Balance.GetAsync();        // balance.Available, balance.Held (crédits)
var senderIds = await client.SenderIds.ListAsync();   // IReadOnlyList<SenderId>

foreach (var senderId in senderIds)
{
    Console.WriteLine($"{senderId.Value} {senderId.Status} défaut={senderId.IsDefault}");   // utilisable comme Sender
}
```

## OTP : vérifier un numéro de téléphone

Le code est généré, envoyé par SMS et vérifié par Labyrinthe Connect. Il n'est jamais renvoyé par l'API (sauf avec
une clé de test) et jamais journalisé par le SDK.

```csharp
// 1. Envoyer le code. Gardez OtpId CÔTÉ SERVEUR (session, cache, base de données), jamais dans le navigateur.
var otp = await client.Otp.SendAsync("0812345678");
HttpContext.Session.SetString("otp_id", otp.OtpId);
// otp.ExpiresAt, otp.MaxAttempts
// otp.CodeLength (4 à 8), otp.CodeType (Numeric / Alphanumeric) : taille du champ et type de clavier.
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
    // mauvais code : une tentative consommée, il en reste result.AttemptsRemaining
}
else
{
    // Expired, AlreadyUsed, MaxAttemptsReached : demander un nouveau code
}
```

Toutes les options d'un envoi :

```csharp
var otp = await client.Otp.SendAsync(new SendOtpRequest
{
    Phone = "0812345678",
    Template = "Votre code MaBoutique : {code}",  // doit contenir {code} ; {app_name}, {minutes} facultatifs
    Locale = OtpLocale.Fr,                        // Fr ou En (modèles standard)
    WebOtpDomain = "app.example.com",             // ajoute « @app.example.com #123456 » pour la Web OTP API
    StripAccents = true,
    IdempotencyKey = "login-42-essai-1",          // facultatif : un Guid est généré sinon
});
```

- Un mauvais code n'est **pas** une exception : `VerifyAsync` renvoie `OtpVerifyResult { Verified = false, Reason = … }`.
- Un nouvel envoi au même numéro invalide le code précédent. Après une vérification réussie, un nouveau code peut
  être envoyé tout de suite.
- Longueur, validité, tentatives, Sender ID et modèle viennent par défaut des réglages OTP du compte.
- `WebOtpDomain` active le remplissage automatique avec la
  [Web OTP API](https://developer.mozilla.org/docs/Web/API/WebOTP_API) (Chrome, Android).
- Limites : `OtpResendTooSoonException` et `OtpRateLimitedException` (`Scope` = `Phone` / `Account`, `Limit`), toutes
  deux avec `RetryAfter`. Jamais relancées par le SDK. `otp/send` est aussi limité à 10 requêtes par minute et par
  clé API.
- **Mode test** : avec une clé `lsk_test_`, aucun SMS ne part et `otp.TestCode` contient le code, pour tester tout le
  parcours. Toujours `null` avec une clé live ; jamais affiché par `ToString()`.

`Otp.SendAsync` porte toujours une `Idempotency-Key` réutilisée par ses relances : un rejeu renvoie le même `OtpId`
sans second SMS (`otp.Replayed`). Chaque résultat de vérification est aussi notifié par les webhooks `otp.verified`
et `otp.failed`.

## Webhooks

Configurez un point d'entrée dans l'espace client (*Développeurs > Webhooks*) et copiez son secret. Les événements
sont envoyés en `POST` JSON (`{ id, event, data, timestamp }`) et signés : `X-Signature: sha256=<HMAC-SHA256(secret,
corps brut)>`. Le SDK vérifie la signature en temps constant (`CryptographicOperations.FixedTimeEquals`) sur le
**corps brut**, avant toute lecture JSON, puis contrôle l'horodatage signé (5 minutes par défaut, protection contre
le rejeu).

> Vérifiez toujours les **octets exacts** reçus. Un corps passé par le model binding ou `JsonSerializer` puis
> resérialisé ne correspond plus à la signature.

### ASP.NET Core : `MapLabyrintheWebhooks()`

```csharp
builder.Services.AddLabyrinthe(builder.Configuration);              // lit Labyrinthe:WebhookSecret
builder.Services.AddLabyrintheWebhookHandler<MyWebhookHandler>();   // ILabyrintheWebhookHandler scoped

var app = builder.Build();
app.MapLabyrintheWebhooks();                                         // POST /webhooks/labyrinthe

sealed class MyWebhookHandler : ILabyrintheWebhookHandler
{
    public Task HandleAsync(WebhookEvent webhookEvent, CancellationToken cancellationToken) => webhookEvent switch
    {
        MessageStatusChanged changed => UpdateMessageAsync(changed.Data.MessageId, changed.Data.Status, cancellationToken),
        OtpFailed failed => AuditAsync(failed.Data.OtpId, failed.Data.Reason, cancellationToken),
        _ => Task.CompletedTask,                                     // événements inconnus ou ignorés : en accuser réception
    };
}
```

- Le point d'entrée lit le corps brut (jusqu'à `WebhookMaxBodySize`), vérifie la signature et l'horodatage, appelle
  votre handler et répond `200 {"received":true}`. Signature invalide, horodatage expiré ou enveloppe invalide :
  `400` ; corps trop gros : `413` ; une exception dans votre handler : `500` (l'API renvoie l'événement). Un secret
  absent est une erreur de configuration (`InvalidOperationException`).
- Autres formes : `MapLabyrintheWebhooks("/hooks/sms")`, `MapLabyrintheWebhooks(pattern, (evt, ct) => …)` et
  `MapLabyrintheWebhooks(pattern, handlerInstance)`.
- Elle renvoie un `RouteHandlerBuilder` : enchaînez `.RequireHost(…)`, `.RequireRateLimiting(…)` ou
  `.AddEndpointFilter(…)`. Ne la placez pas derrière une authentification ou l'antiforgery : la signature fait
  office d'authentification.

Exemple complet : [`examples/WebhookMinimalApi`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/tree/main/dotnet/examples/WebhookMinimalApi).

### Tout framework : `Webhook.ConstructEvent()`

```csharp
app.MapPost("/webhooks/labyrinthe", async (HttpRequest request, IConfiguration configuration) =>
{
    using var buffer = new MemoryStream();
    await request.Body.CopyToAsync(buffer);                          // le corps brut, intact

    WebhookEvent evt;
    try
    {
        evt = Webhook.ConstructEvent(buffer.ToArray(), request.Headers[Webhook.SignatureHeader], configuration["Labyrinthe:WebhookSecret"]!);
    }
    catch (Exception e) when (e is SignatureVerificationException or InvalidWebhookPayloadException)
    {
        return Results.BadRequest();
    }

    if (evt is MessageStatusChanged changed)
    {
        await UpdateMessageAsync(changed.Data.MessageId, changed.Data.Status);
    }
    return Results.Ok();
});
```

`ConstructEvent(rawBody, signatureHeader, secret, tolerance)` accepte un `byte[]` ou une `string` (le corps exact,
décodé en UTF-8) ; `tolerance` vaut par défaut `Webhook.DefaultTolerance` (5 min), `TimeSpan.Zero` désactive le
contrôle (déconseillé). `Webhook.VerifySignature(rawBody, header, secret)` ne vérifie que la signature ;
`Webhook.Sign(body, secret)` construit un en-tête valide pour vos tests.

### Événements

Chaque événement a `Id` (dédoublonnez dessus : les livraisons sont retentées jusqu'à 5 fois et peuvent arriver dans
le désordre), `Type`, `EventType` (`WebhookEventType`), `Timestamp` (`DateTimeOffset`), `Raw` (`JsonElement`) et un
`Data` typé :

| Type | `Type` | `Data` |
|---|---|---|
| `MessageStatusChanged` | `message.status_changed` | `MessageId`, `Recipient`, `Status`, `ProviderMessageId`, `CampaignId`, `Reference`, `ErrorCode`, `ErrorMessage` |
| `OtpVerified` | `otp.verified` | `OtpWebhookData` : `OtpId`, `Phone`, `Status` (`Verified`), `Reason` (`null`), `VerifiedAt` |
| `OtpFailed` | `otp.failed` | `OtpWebhookData` : `Status` `Failed` (`Reason` = `MaxAttemptsReached`) ou `Expired` (`Reason` = `Expired`) |
| `CampaignCompleted` | `campaign.completed` | `CampaignId`, `Name`, `RecipientsCount`, `MessagesByStatus`, `CreditsCharged`, `CompletedAt` |
| `BalanceLow` | `balance.low` | `Balance`, `Threshold` |
| `TestPing` | `test.ping` | `JsonElement` (`{}`) |
| `UnknownEvent` | un type ajouté après cette version du SDK | `JsonElement` tel quel ; répondez 2xx |

Répondez `2xx` en moins de 10 secondes ; mettez les traitements longs en file d'attente.

## Erreurs

Toutes les exceptions héritent de `LabyrintheException` et exposent `Code` (stable, pour votre code), `Message` (en
français, pour les humains), `StatusCode` (statut HTTP, `null` sans réponse) et `Details`
(`IReadOnlyDictionary<string, JsonElement>`). Les erreurs de l'API héritent d'`ApiException` et exposent aussi
`Headers`, `RequestId` (`X-Request-Id`, à donner au support) et `ApiVersion`.

| Exception | HTTP / code | Quand — propriétés utiles |
|---|---|---|
| `AuthenticationException` | 401 `UNAUTHENTICATED` | Clé absente, invalide ou révoquée |
| `PermissionException` | 403 `FORBIDDEN`, `OTP_DISABLED_FOR_ACCOUNT` | IP non autorisée pour cette clé, OTP désactivé pour le compte |
| `AccountSuspendedException` | 403 `ACCOUNT_SUSPENDED` | Hérite de `PermissionException` ; toute l'API est fermée au compte |
| `NotFoundException` | 404 `NOT_FOUND` | Message ou `otp_id` inconnu, route inconnue |
| `ValidationException` | 422 `VALIDATION_ERROR` | `FieldErrors` (champ → messages) |
| `RecipientNotSendableException` | 422 `RECIPIENT_NOT_SENDABLE` | `Rejected`, `Reason` ; rien n'est parti |
| `SenderIdNotApprovedException` | 422 `SENDER_ID_NOT_APPROVED` | `Sender` n'est pas un Sender ID approuvé |
| `InvalidPhoneException` | 422 `INVALID_PHONE` | Numéro OTP refusé par l'API |
| `InsufficientCreditsException` | 409 `INSUFFICIENT_CREDITS` | `Available`, `Required` ; rien n'est parti (jamais en mode test) |
| `IdempotencyKeyReusedException` | 409 `IDEMPOTENCY_KEY_REUSED` | Même clé, requête différente |
| `IdempotencyRequestInProgressException` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` | `RetryAfter` ; déjà relancée par le SDK |
| `IdempotencyKeyUnresolvedException` | 409 `IDEMPOTENCY_KEY_UNRESOLVED` | Issue inconnue, un SMS a pu partir ; jamais relancée |
| `RateLimitException` | 429 `TOO_MANY_REQUESTS` | `RetryAfter` |
| `OtpResendTooSoonException`, `OtpRateLimitedException` | 429 | Héritent de `RateLimitException` ; `Scope`, `Limit` (plafond atteint) |
| `FeatureDisabledException` | 503 `FEATURE_DISABLED`, `OTP_SERVICE_DISABLED` | Hérite de `ServiceUnavailableException` ; maintenance, rien n'a été fait ; `Feature`, `BlockedBy`, `AdminMessage`, `Until`, `RetryAfter` |
| `ServiceUnavailableException` | autres 5xx | Panne temporaire de l'API |
| `ApiException` | tout autre code | La liste est ouverte : de nouveaux codes peuvent apparaître |
| `ApiConnectionException`, `ApiTimeoutException` | `CONNECTION_ERROR`, `TIMEOUT` | Erreur réseau, délai d'une tentative dépassé (`ApiTimeoutException` hérite d'`ApiConnectionException`) |
| `UnexpectedResponseException` | — | Une réponse que le SDK ne sait pas lire |
| `SignatureVerificationException`, `InvalidWebhookPayloadException` | — | Vérification d'un webhook (répondre 400) |

Une saisie invalide détectée avant tout appel lève une `ArgumentException` (les numéros n'apparaissent jamais dans le
message). Annuler votre `CancellationToken` lève une `OperationCanceledException`, jamais une `ApiTimeoutException`.
Attrapez les types les plus précis en premier :

```csharp
try
{
    await client.Sms.SendAsync("0812345678", "Bonjour");
}
catch (InsufficientCreditsException error)
{
    await AlertFinanceAsync(error.Available, error.Required);
}
catch (ValidationException error)
{
    foreach (var (field, messages) in error.FieldErrors) logger.LogWarning("{Field} : {Messages}", field, string.Join(" ", messages));
}
catch (ApiException error)
{
    logger.LogError("Erreur Labyrinthe Connect {Code} (HTTP {Status}, requête {RequestId})", error.Code, error.StatusCode, error.RequestId);
}
catch (ApiConnectionException error)
{
    logger.LogError("Labyrinthe Connect injoignable : {Code}", error.Code);   // CONNECTION_ERROR ou TIMEOUT
}
```

### Maintenance (503 `FEATURE_DISABLED`)

Un administrateur de la plateforme peut couper temporairement toute l'API (`BlockedBy` = `"api"`, toutes les routes,
même avant l'authentification) ou une fonction : `api.sms_send` (`Sms.SendAsync` / `SendManyAsync`), `api.otp_send`
(`Otp.SendAsync`), `api.otp_verify` (`Otp.VerifyAsync`). Le service OTP entier coupé répond par le code historique
`OTP_SERVICE_DISABLED` sur `Otp.SendAsync`, avec les mêmes détails (`Feature` = `BlockedBy` = `"otp"`). Rien n'est
envoyé, aucun crédit n'est consommé. Le SDK ne relance qu'après `Retry-After` (réactivation automatique programmée),
s'il ne dépasse pas `MaxRetryWait`, jamais par backoff aveugle. Ne relancez pas en boucle : mettez le travail en file
d'attente et prévenez l'utilisateur.

```csharp
try
{
    await client.Sms.SendAsync("0812345678", "Bonjour");
}
catch (FeatureDisabledException error)
{
    // error.Feature : "api.sms_send" ; error.BlockedBy : "api" quand toute l'API est coupée.
    var text = error.AdminMessage ?? "L'envoi de SMS est en maintenance.";
    var retryAt = error.Until ?? DateTimeOffset.UtcNow.Add(error.RetryAfter ?? TimeSpan.FromMinutes(15));
    await outbox.ScheduleAsync(retryAt);                                  // votre file d'attente
    logger.LogWarning("Labyrinthe Connect coupé ({BlockedBy}) : {Message}", error.BlockedBy, text);
}
```

`Feature`, `BlockedBy`, `AdminMessage`, `Until` et `RetryAfter` valent `null` quand l'API ne les envoie pas. Attrapez
`FeatureDisabledException` avant `ServiceUnavailableException`.

## Relances et idempotence

### Relances automatiques

Le SDK relance automatiquement (par défaut : 2 relances, backoff exponentiel 0,5 s → 1 s → 2 s avec jitter, plafonné
à 8 s) **uniquement quand un rejeu ne peut pas produire deux fois un effet** :

| Requête | Erreur réseau / délai | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` | 503 `FEATURE_DISABLED` |
|---|---|---|---|---|---|
| `GetAsync`, `ListAsync`, `Balance`, `SenderIds`, `EstimateAsync` | relancée | relancée | après `Retry-After` | — | seulement après `Retry-After` |
| `Sms.SendAsync` / `SendManyAsync` / `Otp.SendAsync` (toujours avec une `Idempotency-Key`) | relancée, même clé | relancée, même clé | après `Retry-After` | après `Retry-After`, même clé | seulement après `Retry-After`, même clé |
| `Otp.VerifyAsync` | **jamais** | **jamais** | après `Retry-After` (refusée avant traitement) | — | seulement après `Retry-After` (refusée avant traitement) |

Les erreurs métier (autres 4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, `IDEMPOTENCY_KEY_UNRESOLVED`) ne sont
jamais relancées. Quand l'API demande d'attendre plus que `MaxRetryWait`, le SDK lève l'erreur (`RateLimitException`,
`FeatureDisabledException`…) au lieu d'attendre. Les redirections ne sont jamais suivies.

### Idempotence (pas de double envoi)

Chaque envoi de SMS et d'OTP porte une `Idempotency-Key`. Si vous n'en donnez pas, le SDK génère un `Guid` et le
réutilise pour ses propres relances : une erreur réseau ne produit jamais de second SMS. Pour vous protéger aussi de
**vos** relances (tâches de fond, double clic, plantage), passez une clé dérivée de votre événement métier. Un rejeu
dans les 24 heures renvoie le résultat d'origine sans nouvel envoi ni débit :

```csharp
var batch = await client.Sms.SendManyAsync(new SendManySmsRequest
{
    Recipients = new[] { "0812345678" },
    Body = "Votre commande 1042 est prête.",
    IdempotencyKey = "commande-1042-prete",   // 255 caractères au maximum
});
batch.Replayed   // true s'il s'agissait d'un rejeu
```

- La même clé avec un autre contenu : `IdempotencyKeyReusedException` (409). Les destinataires sont comparés après
  normalisation (`0812345678` puis `243812345678` est un rejeu). `/sms` et `/otp/send` ont des espaces de clés séparés.
- Requête d'origine encore en cours : l'API répond `409 IDEMPOTENCY_REQUEST_IN_PROGRESS` ; le SDK attend
  `Retry-After` et relance avec la même clé (`IdempotencyRequestInProgressException` si elle l'est toujours).
- `IdempotencyKeyUnresolvedException` (409 `IDEMPOTENCY_KEY_UNRESOLVED`) : la requête d'origine a échoué de façon
  inattendue côté API, son issue est inconnue et **un SMS a peut-être déjà été envoyé**. Ni le SDK ni vous ne devez la
  relancer automatiquement. Vérifiez d'abord (`ListAsync` avec la `Reference` envoyée, ou demandez à l'utilisateur
  si le code est arrivé), puis, seulement si rien n'est parti, renvoyez avec une **nouvelle** clé.

```csharp
try
{
    await client.Sms.SendAsync(new SendSmsRequest { To = "0812345678", Body = "…", Reference = "commande-1042", IdempotencyKey = key });
}
catch (IdempotencyKeyUnresolvedException e)
{
    var sent = await client.Sms.ListAsync(new ListSmsOptions { Reference = "commande-1042" });
    logger.LogWarning("Issue inconnue (requête {RequestId}), {Count} message(s) trouvé(s)", e.RequestId, sent.Total);
}
```

## Mode test, journalisation et sécurité

### Mode test

Une clé `lsk_test_…` utilise un fournisseur simulé : **rien n'est envoyé, aucun crédit n'est consommé**, les
réponses ont la forme de la production. Deux différences : le solde n'est pas vérifié (pas
d'`InsufficientCreditsException` en mode test) et `Otp.SendAsync` renvoie le code dans `TestCode`. Utilisez les clés
de test en développement, en CI et dans les exemples ; vérifiez `client.IsTestMode` au démarrage si vous voulez
refuser une clé live hors production.

### Journalisation

```csharp
Logger = LabyrintheLogger.Create((level, message) => Console.Error.WriteLine($"[{level}] {message}"), LabyrintheLogLevel.Debug)
```

Niveaux : `Debug` (chaque tentative et réponse), `Information` (erreurs de l'API renvoyées à votre code, minimum par
défaut), `Warning` (relances automatiques), `Error` (erreurs réseau et délais dépassés). Implémentez
`ILabyrintheLogger` pour brancher une autre bibliothèque ; avec `Labyrinthe.Connect.AspNetCore`, les journaux vont
dans `ILogger<LabyrintheClient>` (mettez `"Labyrinthe.Connect": "Debug"` sous `Logging:LogLevel` pour voir chaque
tentative). Les entrées contiennent l'identifiant de requête et jamais la clé, un code, le texte d'un message ni un
numéro complet (`24381*****78`).

### Sécurité

- `client.ToString()` et `options.ToString()` affichent `lsk_test_****` ; `OtpSendResult.ToString()` masque
  `TestCode` ; `Message.ToString()` et `OtpWebhookData.ToString()` masquent le numéro.
- Les clés restent côté serveur ; stockez-les dans les user secrets, des variables d'environnement ou un coffre,
  jamais dans un `appsettings.json` versionné.
- Webhooks : signature vérifiée en temps constant sur le corps brut, tolérance d'horodatage, dédoublonnage sur `Id`.

### Identifiants de requête

Chaque réponse porte `X-Request-Id`. Il est exposé par `ApiException.RequestId` et par les résultats
(`MessageBatch`, `Estimate`, `Balance`, `OtpSendResult`, `OtpVerifyResult`, `Page<T>` ; hors égalité des records).
Pour envoyer votre propre identifiant (trace, numéro de commande), passez par une vue légère du client :

```csharp
var batch = await client.WithRequestId("commande-1042").Sms.SendManyAsync(request);   // 1 à 128 caractères : A-Z a-z 0-9 . _ : -
batch.RequestId   // "commande-1042"
```

La vue partage les connexions de `client` (inutile de la libérer) et envoie l'identifiant à chaque relance.

### Délais et annulation

`Timeout` limite **chaque tentative** ; une tentative qui le dépasse lève `ApiTimeoutException` (et est relancée
quand c'est sûr). Chaque méthode accepte un `CancellationToken` qui borne **l'appel entier**, relances et attentes
comprises :

```csharp
using var cts = new CancellationTokenSource(TimeSpan.FromSeconds(10));
var balance = await client.Balance.GetAsync(cts.Token);   // OperationCanceledException après 10 s
```

Dans ASP.NET Core, passez `HttpContext.RequestAborted` (ou le paramètre `CancellationToken` d'un handler minimal
API). Annuler un envoi après le départ de la requête ne l'annule pas côté serveur : renvoyez avec la **même**
`IdempotencyKey` pour connaître l'issue sans double envoi.

## Sans le package (HTTP brut)

L'API est du JSON sur HTTPS ; le SDK est une commodité. Avec `HttpClient` seulement :

```csharp
using System.Net.Http.Headers;
using System.Net.Http.Json;

using var http = new HttpClient { BaseAddress = new Uri("https://api.connect.labyrinthe-rdc.com/api/public/v1/") };
http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", Environment.GetEnvironmentVariable("LABYRINTHE_API_KEY"));

using var request = new HttpRequestMessage(HttpMethod.Post, "sms")
{
    Content = JsonContent.Create(new { recipient = "+243812345678", body = "Votre commande 1042 est prête.", reference = "commande-1042" }),
};
request.Headers.Add("Idempotency-Key", Guid.NewGuid().ToString());   // à conserver pour relancer sans risque

using var response = await http.SendAsync(request);
var json = await response.Content.ReadAsStringAsync();
// 202 : { "data": [ { "id": "…", "recipient": "243812345678", "status": "queued", … } ] }
// erreur : { "error": { "code": "…", "message": "…", "details": { } } }, avec X-Request-Id
```

Signature de webhook sans le package : calculez `"sha256=" + Convert.ToHexString(HMACSHA256.HashData(secretBytes,
rawBody)).ToLowerInvariant()`, comparez-la à `X-Signature` avec `CryptographicOperations.FixedTimeEquals`, puis
vérifiez que le `timestamp` de l'enveloppe (secondes Unix) est récent. Les relances, l'idempotence, la lecture des
erreurs et le format des numéros sont alors à votre charge.

Référence : la spécification OpenAPI [`openapi/openapi.yaml`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/openapi/openapi.yaml)
(1.3.1), aussi servie par l'API sur `/api/public/openapi.yaml`.

### Limites connues de l'API

- Les points d'entrée de webhook se gèrent uniquement dans l'espace client (pas d'API).
- `INSUFFICIENT_CREDITS` ne se reproduit pas avec une clé de test.
- `EstimateAsync` compte les numéros en liste noire ou désinscrits que `SendManyAsync` refuserait.
- `ListAsync` renvoie les messages de la clé appelante ; `GetAsync` trouve n'importe quel message du compte.

## Mise à jour

Le SDK suit SemVer ; les versions 1.x sont rétrocompatibles. Voir le
[CHANGELOG](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/dotnet/CHANGELOG.md) (en anglais).

- **1.2.0 → 1.2.1** (API 1.3.1) : aucun changement de code. L'URL par défaut de l'API est désormais
  `https://api.connect.labyrinthe-rdc.com/api/public/v1` (celle de la 1.2.0 ne répondait pas). Mettez à jour, ou indiquez cette URL
  explicitement si vous restez en 1.2.0.
- **1.1.x → 1.2.0** (API 1.3.0) : nouvelle `FeatureDisabledException` (503 `FEATURE_DISABLED`).
  `OTP_SERVICE_DISABLED` est désormais levée en `FeatureDisabledException` (toujours une
  `ServiceUnavailableException` : vos blocs `catch` existants fonctionnent encore). Les deux ne sont relancées
  qu'après `Retry-After`, plus avec le backoff des 5xx.
- **1.1.0 → 1.1.1** : les numéros partent en E.164 avec `+` ; nouvelle `IdempotencyKeyUnresolvedException`.
- **1.0.x → 1.1.0** (API 1.2.0) : `RequestId` sur les erreurs et les résultats, `WithRequestId()`, envois d'OTP
  idempotents, `StripAccents`, `WebOtpDomain`, événements `otp.verified` / `otp.failed`. Sans `+`, 11 chiffres ou
  plus ne commençant pas par 0 sont désormais lus comme internationaux.

## Contribuer

Les issues et pull requests vont dans le [monorepo](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks) ;
lisez d'abord [CONTRIBUTING.md](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/CONTRIBUTING.md).
Depuis `dotnet/` :

```bash
dotnet format --verify-no-changes
dotnet build -c Release -warnaserror      # TreatWarningsAsErrors + analyseurs .NET
dotnet test -c Release                    # tests unitaires + contrat OpenAPI (HTTP simulé, sans réseau)
dotnet pack src/Labyrinthe.Connect -c Release -o artifacts   # .nupkg + .snupkg

# tests d'intégration contre un backend local, avec une clé de TEST uniquement
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 dotnet test --filter Category=Integration
```

Exemples (construits avec la solution) :
[`SendSms`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/tree/main/dotnet/examples/SendSms) (console),
[`OtpLogin`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/tree/main/dotnet/examples/OtpLogin) (parcours OTP),
[`WebhookMinimalApi`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/tree/main/dotnet/examples/WebhookMinimalApi)
(ASP.NET Core). Failles de sécurité : connect@labyrinthe-rdc.com plutôt qu'une issue publique.

## Licence

MIT — voir [LICENSE](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/dotnet/LICENSE).

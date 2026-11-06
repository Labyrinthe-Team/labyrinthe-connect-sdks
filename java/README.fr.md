# Labyrinthe Connect — SDK Java

[![CI](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/java.yml/badge.svg)](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/java.yml)
[![Maven Central](https://img.shields.io/maven-central/v/cd.labyrinthe/labyrinthe-connect.svg)](https://central.sonatype.com/artifact/cd.labyrinthe/labyrinthe-connect)
![Java](https://img.shields.io/badge/java-11%20%E2%86%92%2021-007396)

Client Java officiel de [Labyrinthe Connect](https://labyrinthe-rdc.com) : envoyez des SMS et vérifiez des
numéros de téléphone par code à usage unique (OTP), en RDC et en Afrique centrale.

*English version: [README.md](README.md).*

- Java 11+, basé sur `java.net.http.HttpClient`. **Aucune dépendance d'exécution** (pas même une bibliothèque JSON).
- API synchrone **et** asynchrone (`CompletableFuture`), client thread-safe, modèles immuables, `Optional` pour
  les champs facultatifs, `Instant` pour les dates, énumérations avec une valeur `UNKNOWN` pour les valeurs futures.
- Sûr par défaut : relances automatiques **uniquement** quand elles ne peuvent pas envoyer deux fois un SMS.
- Ne journalise et n'affiche jamais votre clé API, les codes OTP ni les numéros complets.
- Starter Spring Boot 3 facultatif : bean client configuré par `labyrinthe.*` et point d'entrée de webhook signé
  qui publie des événements Spring.

## Installation

Maven :

```xml
<dependency>
  <groupId>cd.labyrinthe</groupId>
  <artifactId>labyrinthe-connect</artifactId>
  <version>1.2.0</version>
</dependency>
```

Gradle :

```kotlin
implementation("cd.labyrinthe:labyrinthe-connect:1.2.0")
// Spring Boot 3 (Java 17+) :
implementation("cd.labyrinthe:labyrinthe-connect-spring-boot-starter:1.2.0")
```

## Démarrage rapide

```java
import cd.labyrinthe.connect.LabyrintheClient;
import cd.labyrinthe.connect.Message;

LabyrintheClient client = LabyrintheClient.builder().apiKey("lsk_test_…").build(); // ou LabyrintheClient.fromEnv()
Message message = client.sms().send("0812345678", "Votre commande 1042 est prête.");
System.out.println(message.status());                                              // queued
```

Créez vos clés dans l'espace client (*Développeurs > Clés API*). **Gardez-les côté serveur**, jamais dans un
navigateur ni une application mobile.

| Clé | Mode | Effet |
|---|---|---|
| `lsk_test_…` | Bac à sable | Rien n'est envoyé, aucun crédit n'est consommé. Mêmes réponses qu'en production. L'envoi d'OTP renvoie le code (`testCode()`). |
| `lsk_live_…` | Production | Les SMS partent réellement et consomment des crédits. |

`client.isTestMode()` indique le mode utilisé. Tous les exemples ci-dessous fonctionnent avec une clé de test.

Le client est thread-safe et garde un pool de connexions `HttpClient` : créez-en **un seul** par application et
partagez-le.

### Synchrone et asynchrone

```java
Balance balance = client.balance().get();                          // bloquant

client.async().sms().send("0812345678", "Bonjour")                 // CompletableFuture<Message>
    .thenAccept(message -> System.out.println(message.id()));
```

`client.async()` expose les mêmes services, avec les mêmes règles (relances, idempotence, erreurs). Les futurs
échouent avec une `LabyrintheException` (enveloppée dans une `CompletionException` par `join()`, dans une
`ExecutionException` par `get()`) ; un argument invalide donne aussi un futur en échec.

## Envoyer des SMS

```java
Message message = client.sms().send(SendSmsParams.builder()
    .to("+243 81 234 5678")        // +243…, 00243…, 243…, 0812…, 812… sont acceptés
    .body("Votre commande 1042 est prête.")
    .sender("MABOUTIQUE")          // facultatif : un Sender ID approuvé (client.senderIds().list())
    .reference("commande-1042")    // facultatif : votre référence, 64 caractères au plus
    .build());

message.id();               // "9d5c0a3e-…"
message.recipient();        // "243812345678"
message.status();           // MessageStatus.QUEUED
message.segments();         // 1
message.creditsCharged();   // 1
message.createdAt();        // Instant
```

L'envoi est asynchrone : `202 Accepted` signifie *mis en file et payé*, pas *remis*. Suivez le statut par webhook
(`message.status_changed`, recommandé) ou en interrogeant l'API :

```java
Message latest = client.sms().get(message.id());
if (latest.status().isFinal()) {
  System.out.println(latest.status() == MessageStatus.DELIVERED
      ? "Remis" : "Échec : " + latest.errorCode().orElse("?"));
}
```

### Coût avant l'envoi

1 crédit = 1 segment SMS. Un message en ASCII pur (GSM-7) tient en 160 caractères (153 par segment au-delà).
**Tout caractère non ASCII** (é, à, ç, emoji, guillemets typographiques…) passe en UCS-2 : 70 caractères (67 par
segment au-delà).

```java
Estimate estimate = client.sms().estimate(EstimateSmsParams.builder()
    .body("Réunion demain à 9h")
    .recipients(List.of("0812345678", "0899999999"))
    .build());
estimate.encoding();       // Encoding.UCS2
estimate.totalCredits();   // 2
estimate.isAffordable();   // le solde suffit-il ?
```

`stripAccents(true)` (sur `SendSmsParams`, `SendManySmsParams`, `EstimateSmsParams` et `SendOtpParams`) demande à
l'API de remplacer accents et caractères typographiques par leur équivalent ASCII : le message passe en GSM-7,
souvent deux fois moins cher. La translittération dépend du serveur (`Café` peut devenir `Cafe` ou `Caf'e`) : ne
pas s'en servir pour un texte exact.

### Plusieurs destinataires : tout ou rien

```java
try {
  MessageBatch batch = client.sms().sendMany(SendManySmsParams.builder()
      .recipients(List.of("0812345678", "0899999999"))
      .body("Réunion demain à 9h.")
      .build());
  batch.messages();          // un message par numéro distinct (les doublons sont fusionnés)
} catch (RecipientNotSendableException e) {
  // RIEN n'a été envoyé : corrigez la liste et recommencez.
  for (RejectedRecipient rejected : e.rejected()) {
    System.out.println(rejected.recipient() + " : " + rejected.reason());  // INVALID_PHONE, BLACKLISTED, OPTED_OUT
  }
}
```

Jusqu'à 1000 destinataires par appel. Tous les destinataires, le Sender ID et le solde sont vérifiés **avant** le
moindre envoi : un seul numéro refusé (ou un solde insuffisant) et aucun SMS ne part. Les numéros que le SDK sait
déjà invalides sont refusés localement (`InvalidArgumentException`, avec leur position seulement), avant tout appel.

### Idempotence (jamais de double envoi)

Chaque envoi porte un en-tête `Idempotency-Key`. Sans clé fournie, le SDK génère un UUID aléatoire et le réutilise
pour ses propres relances : une erreur réseau ne produit jamais un second SMS. Pour vous protéger aussi de **vos**
relances (files de tâches, double clic, plantage), passez une clé dérivée de votre événement métier. Un rejeu dans
les 24 h renvoie les messages d'origine sans rien renvoyer ni facturer :

```java
MessageBatch batch = client.sms().sendMany(SendManySmsParams.builder()
    .addRecipient("0812345678")
    .body("Votre commande 1042 est prête.")
    .idempotencyKey("commande-1042-prete")
    .build());
batch.replayed();   // true pour un rejeu
```

La même clé avec un autre contenu est refusée par `IdempotencyKeyReusedException` (409). Les destinataires sont
comparés après normalisation (`0812345678` puis `243812345678` avec la même clé = rejeu). Tant que la requête
d'origine est en cours, l'API répond 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` : le SDK attend `Retry-After` et relance
avec la même clé, puis lève `IdempotencyRequestInProgressException` (`retryAfter()`).

**`IdempotencyKeyUnresolvedException` (409 `IDEMPOTENCY_KEY_UNRESOLVED`, `sms().send()`, `sendMany()` et
`otp().send()`).** La requête d'origine avec cette clé a échoué de façon inattendue côté API et son résultat est
inconnu : un SMS a **peut-être** été envoyé. Le SDK ne la relance jamais, et vous ne devez pas la relancer
automatiquement non plus (ni avec la même clé : l'API répondra toujours cette erreur ; ni avec une nouvelle clé : vous
risqueriez un double envoi). Conduite à tenir :

1. vérifier si le message est parti : `client.sms().list(ListSmsParams.builder().reference("commande-1042").build())`
   si vous avez passé une `reference`, ou demander à l'utilisateur si le code est arrivé (OTP) ;
2. seulement si rien n'est parti, renvoyer avec une **nouvelle** `Idempotency-Key`.

```java
try {
  client.sms().send(params);
} catch (IdempotencyKeyUnresolvedException e) {
  log.warn("Résultat inconnu (requête {}) : vérifier avant de renvoyer", e.requestId().orElse("?"));
}
```

### Lister les messages

```java
Page<Message> page = client.sms().list(ListSmsParams.builder()
    .status(MessageStatus.FAILED)
    .from(LocalDate.of(2026, 11, 1))     // une LocalDate couvre la journée entière
    .to(Instant.now())
    .perPage(50)
    .build());
page.total(); page.data(); page.hasMore(); page.nextPage();

for (Message m : client.sms().list(ListSmsParams.builder().reference("commande-1042").build()).autoPagingIterable()) {
  // toutes les pages, chargées à la demande (ou : autoPagingStream())
}
```

Filtres : `status`, `recipient` (tout format), `reference`, `from`, `to` (`LocalDate`, `Instant` ou
`OffsetDateTime`), `page`, `perPage` (100 au plus). Seuls les messages envoyés **avec la clé appelante**.

### Solde et Sender IDs

```java
Balance balance = client.balance().get();              // balance.balance(), balance.held()
List<SenderId> senderIds = client.senderIds().list();  // noms approuvés, utilisables comme sender
```

## OTP : vérifier un numéro de téléphone

Le code est généré, envoyé par SMS et vérifié par Labyrinthe Connect. L'API ne le renvoie jamais (sauf avec une
clé de test) et le SDK ne le journalise jamais.

```java
// 1. Envoyer le code. Garder otpId CÔTÉ SERVEUR (session, cache, base), jamais dans le navigateur.
OtpSendResult otp = client.otp().send("0812345678");
session.setAttribute("otpId", otp.otpId());
otp.codeLength();           // ex. 6 : taille du champ de saisie
otp.codeType();             // OtpCodeType.NUMERIC (clavier numérique) ou ALPHANUMERIC
otp.resendAfterSeconds();   // ex. 60 : compte à rebours « Renvoyer le code »

// 2. L'utilisateur saisit le code reçu par SMS.

// 3. Le vérifier.
OtpVerifyResult result = client.otp().verify((String) session.getAttribute("otpId"), codeSaisi);
if (result.isVerified()) {
  session.removeAttribute("otpId");                 // connecter l'utilisateur / marquer le numéro vérifié
} else if (result.reason().orElseThrow() == OtpFailureReason.INVALID_CODE) {
  // code faux : une tentative consommée, il en reste result.attemptsRemaining() (OptionalInt)
} else {
  // EXPIRED, ALREADY_USED, MAX_ATTEMPTS_REACHED : redemander un code
}
```

- Un code faux n'est **pas** une exception : `verify()` renvoie un résultat `isVerified() == false` avec un `reason()`.
- Un nouvel `send()` au même numéro invalide le code précédent.
- Longueur, validité, tentatives, Sender ID et modèle viennent des paramètres OTP du compte. Vous pouvez imposer le
  modèle (`SendOtpParams.builder().template("Votre code MaBoutique : {code}")`, doit contenir `{code}` ; jetons
  facultatifs `{app_name}`, `{minutes}`), la langue (`.locale(OtpLocale.FR)`), ajouter la ligne
  [Web OTP API](https://developer.mozilla.org/docs/Web/API/WebOTP_API) pour le remplissage automatique
  (`.webOtpDomain("app.exemple.com")`) et `.stripAccents(true)`.
- Limites : `OtpResendTooSoonException` et `OtpRateLimitedException` (`scope()`, `limit()`), toutes deux avec
  `retryAfter()`. Après une vérification réussie, un nouveau code peut être envoyé tout de suite.
- **Mode test** : avec une clé `lsk_test_`, aucun SMS ne part et `otp.testCode()` contient le code, pour tester tout
  le parcours. Toujours vide avec une clé live ; exclu de `toString()`, `equals()` et `hashCode()`.

`otp().send()` porte toujours une `Idempotency-Key` (un UUID, ou la vôtre avec `.idempotencyKey("login-42")`) : il est
relancé sans risque après une erreur réseau, un délai dépassé, une 5xx, un 429 `TOO_MANY_REQUESTS` ou un 409
`IDEMPOTENCY_REQUEST_IN_PROGRESS` ; une relance renvoie le même OTP (`otp.replayed()`), jamais un second SMS.
`OTP_RESEND_TOO_SOON` et `OTP_RATE_LIMITED` ne sont jamais relancés. `otp().verify()` n'est relancé que sur 429
`TOO_MANY_REQUESTS` (sinon une tentative pourrait être consommée deux fois) et sur 503 `FEATURE_DISABLED` après
`Retry-After` (tous deux refusés avant traitement).

## Webhooks

Configurez un point d'entrée dans l'espace client (*Développeurs > Webhooks*) et copiez son secret. Les événements
sont envoyés en `POST` JSON, signés (`X-Signature: sha256=<HMAC-SHA256 du corps brut>`). Le SDK vérifie la
signature en temps constant (`MessageDigest.isEqual`) sur le **corps brut**, puis l'horodatage signé (5 minutes
par défaut).

```java
WebhookEvent event;
try {
  event = Webhooks.constructEvent(rawBody, request.getHeader("X-Signature"), secret);  // byte[] ou String, jamais du JSON re-sérialisé
} catch (SignatureVerificationException | InvalidWebhookPayloadException e) {
  // répondre 400
}
if (event instanceof MessageStatusChanged) {
  MessageStatusChanged changed = (MessageStatusChanged) event;
  updateMessage(changed.data().messageId(), changed.data().status());
}
// ou : switch (event.eventType()) { case MESSAGE_STATUS_CHANGED: … }
```

Chaque événement a un `id()` (dédoublonnez dessus : jusqu'à 5 tentatives, ordre non garanti), `type()`,
`eventType()`, `timestamp()` (`Instant`), `raw()` et un `data()` typé :

| Classe | `type()` | `data()` |
|---|---|---|
| `MessageStatusChanged` | `message.status_changed` | `messageId`, `recipient`, `status`, `providerMessageId`, `campaignId`, `reference`, `errorCode`, `errorMessage` |
| `CampaignCompleted` | `campaign.completed` | `campaignId`, `name`, `recipientsCount`, `messagesByStatus`, `creditsCharged`, `completedAt` |
| `BalanceLow` | `balance.low` | `balance`, `threshold` |
| `OtpVerified` | `otp.verified` | `OtpEventData` : `otpId`, `phone`, `status` (`VERIFIED`), `reason` (vide), `verifiedAt` |
| `OtpFailed` | `otp.failed` | `OtpEventData` : `status` `FAILED` (`MAX_ATTEMPTS_REACHED`) ou `EXPIRED` (`EXPIRED`) |
| `TestPing` | `test.ping` | `Map` (vide) |
| `UnknownEvent` | type ajouté après cette version du SDK | `Map`, tel quel ; répondre 2xx |

Répondez `2xx` en moins de 10 secondes ; mettez le travail long en file. `Webhooks.sign(body, secret)` construit un
en-tête valide pour vos tests. Récepteur complet avec le serveur HTTP du JDK :
[`WebhookServer.java`](examples/src/main/java/cd/labyrinthe/connect/examples/WebhookServer.java).

## Spring Boot

```xml
<dependency>
  <groupId>cd.labyrinthe</groupId>
  <artifactId>labyrinthe-connect-spring-boot-starter</artifactId>
  <version>1.2.0</version>
</dependency>
```

```properties
labyrinthe.api-key=${LABYRINTHE_API_KEY}
labyrinthe.webhook.secret=${LABYRINTHE_WEBHOOK_SECRET}
# facultatif : labyrinthe.base-url, timeout=30s, max-retries=2, max-retry-wait=10s, default-country-code=243,
#              default-sender, webhook.path=/webhooks/labyrinthe, webhook.tolerance=5m, webhook.enabled=true
```

```java
@Service
class Commandes {
  private final LabyrintheClient labyrinthe;               // bean auto-configuré
  Commandes(LabyrintheClient labyrinthe) { this.labyrinthe = labyrinthe; }

  @EventListener                                            // les webhooks vérifiés sont des événements Spring
  void onStatus(MessageStatusChanged event) { … }
}
```

- Le bean `LabyrintheClient` est créé si `labyrinthe.api-key` (ou `LABYRINTHE_API_KEY`) est défini et que vous
  n'avez pas déclaré le vôtre. Des beans `LabyrintheClientBuilderCustomizer` peuvent ajuster le builder.
- Dans une application Spring MVC, `POST /webhooks/labyrinthe` est enregistré si `labyrinthe.webhook.secret` est
  défini. Réponse `200 {"received":true}`, ou `400` (signature fausse, horodatage expiré, corps invalide). Les
  événements sont publiés par `ApplicationEventPublisher.publishEvent(event)` : écoutez `WebhookEvent` ou une
  sous-classe. Un écouteur qui lève une exception donne une `500`, et la livraison est retentée.
- Avec Spring Security, excluez le point d'entrée du CSRF (`csrf.ignoringRequestMatchers("/webhooks/labyrinthe")`)
  et autorisez-le : la signature tient lieu d'authentification.
- Nécessite Spring Boot 3 et Java 17+. Exemple :
  [`SpringBootExample.java`](examples/src/spring/java/cd/labyrinthe/connect/examples/spring/SpringBootExample.java).

## Erreurs

Toutes les exceptions sont non vérifiées et héritent de `LabyrintheException`, avec `code()` (stable, pour votre
code), `getMessage()`, `statusCode()` (statut HTTP, `0` sans réponse) et `details()`. Les erreurs de l'API héritent
d'`ApiException` et exposent aussi `headers()`, `requestId()` (`X-Request-Id`, pour le support) et `apiVersion()`.

| Exception | Quand |
|---|---|
| `AuthenticationException` | 401 — clé absente, invalide ou révoquée |
| `PermissionException` | 403 — IP non autorisée (`FORBIDDEN`), OTP désactivé pour le compte (`OTP_DISABLED_FOR_ACCOUNT`) |
| `AccountSuspendedException` | 403 `ACCOUNT_SUSPENDED` — hérite de `PermissionException` ; toute l'API est fermée |
| `NotFoundException` | 404 — message ou `otpId` inconnu |
| `ValidationException` | 422 `VALIDATION_ERROR` — `fieldErrors()` |
| `RecipientNotSendableException` | 422 — `rejected()`, `reason()` ; rien n'a été envoyé |
| `SenderIdNotApprovedException` | 422 — `sender` n'est pas un Sender ID approuvé |
| `InvalidPhoneException` | 422 `INVALID_PHONE` (OTP) |
| `InsufficientCreditsException` | 409 — `available()`, `required()` ; rien n'a été envoyé |
| `IdempotencyKeyReusedException` | 409 — même clé, requête différente |
| `IdempotencyRequestInProgressException` | 409 — requête d'origine encore en cours ; `retryAfter()`, relancer avec la même clé |
| `IdempotencyKeyUnresolvedException` | 409 `IDEMPOTENCY_KEY_UNRESOLVED` — la requête d'origine a échoué, résultat inconnu (un SMS a pu partir) ; jamais relancée : vérifier, puis renvoyer avec une **nouvelle** clé |
| `RateLimitException` | 429 `TOO_MANY_REQUESTS` — `retryAfter()` |
| `OtpResendTooSoonException`, `OtpRateLimitedException` | 429 — héritent de `RateLimitException` (`retryAfter()`) ; `scope()`, `limit()` |
| `ServiceUnavailableException` | 5xx |
| `FeatureDisabledException` | 503 `FEATURE_DISABLED` (ou `OTP_SERVICE_DISABLED` sur `otp().send`) — hérite de `ServiceUnavailableException` ; coupé par la plateforme, rien n'a été fait ; `feature()`, `blockedBy()`, `adminMessage()`, `until()`, `retryAfter()` |
| `ApiException` | tout autre code (liste ouverte : de nouveaux codes peuvent apparaître) |
| `ApiConnectionException`, `ApiTimeoutException` | erreur réseau (`CONNECTION_ERROR`) ou délai dépassé (`TIMEOUT`) |
| `InvalidArgumentException` | donnée invalide détectée par le SDK, avant tout appel (`INVALID_ARGUMENT`) |
| `UnexpectedResponseException` | réponse illisible pour le SDK |
| `SignatureVerificationException`, `InvalidWebhookPayloadException` | vérification de webhook (répondre 400) |

### Maintenance (503 `FEATURE_DISABLED`)

Un administrateur de la plateforme peut couper temporairement toute l'API (`blockedBy()` = `api`, toutes les routes,
avant même la vérification de la clé) ou une fonction (`api.sms_send`, `api.otp_send`, `api.otp_verify`). Rien n'est
fait (aucun SMS, aucun débit). Le SDK ne relance que si une réactivation est programmée dans la limite de
`maxRetryWait` (`Retry-After`), après exactement ce délai ; sinon il lève l'exception aussitôt. Ne relancez pas en
boucle : mettez l'appel en file d'attente et prévenez l'utilisateur.

```java
try {
  client.sms().send("0812345678", "Bonjour");
} catch (FeatureDisabledException e) {
  // e.feature() = "api.sms_send", e.blockedBy() = "api" quand toute l'API est coupée
  String texte = e.adminMessage().orElse("L'envoi de SMS est momentanément indisponible.");
  Duration attente = e.retryAfter().orElse(Duration.ofMinutes(15)); // until() donne la date, si connue
  fileAttente.relancerPlusTard(commande, attente, texte);
}
```

## Relances et délais

Le SDK relance automatiquement (par défaut 2 relances, backoff exponentiel 0,5 s → 1 s → 2 s avec jitter)
**seulement quand un rejeu ne peut pas produire deux fois un effet** :

| Requête | Erreur réseau / délai | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` | 503 `FEATURE_DISABLED` |
|---|---|---|---|---|---|
| `get`, `list`, `balance().get`, `senderIds().list`, `estimate` | relancée | relancée | relancée après `Retry-After` | — | seulement après `Retry-After` |
| `send` / `sendMany` / `otp().send` (toujours avec `Idempotency-Key`) | relancée, même clé | relancée, même clé | relancée après `Retry-After` | relancée après `Retry-After`, même clé | seulement après `Retry-After`, même clé |
| `otp().verify` | **jamais** | **jamais** | relancée (refusée avant traitement) | — | seulement après `Retry-After` (refusée avant traitement) |

Les erreurs métier (4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, 409 `IDEMPOTENCY_KEY_UNRESOLVED`) ne sont
jamais relancées. Les 503 `FEATURE_DISABLED` et `OTP_SERVICE_DISABLED` ne sont jamais relancées avec un backoff à
l'aveugle : seulement après `Retry-After` (envoyé quand une réactivation est programmée). Si l'API demande
d'attendre plus que `maxRetryWait` (10 s par défaut), le SDK lève l'exception (`RateLimitException`,
`FeatureDisabledException`…) au lieu d'attendre. Les
redirections ne sont jamais suivies (un `HttpClient` personnalisé qui les suit est refusé).

Chaque méthode accepte un `RequestOptions` : un délai pour un appel, votre propre `X-Request-Id` (envoyé à chaque
relance, repris par l'API dans ses journaux et ses réponses) et un rappel qui lit les en-têtes de la réponse :

```java
client.sms().send(params, RequestOptions.builder()
    .timeout(Duration.ofSeconds(5))
    .requestId("commande-1042")                           // 1 à 128 caractères parmi A-Z a-z 0-9 . _ : -
    .onResponse(info -> log.info("requête {} (API v{}), {} requêtes restantes",
        info.requestId().orElse("?"), info.apiVersion().orElse("?"), info.rateLimitRemaining()))
    .build());
```

## Configuration

```java
LabyrintheClient client = LabyrintheClient.builder()
    .apiKey(System.getenv("LABYRINTHE_API_KEY"))   // par défaut : LABYRINTHE_API_KEY
    .baseUrl("http://localhost:8000")              // par défaut : LABYRINTHE_BASE_URL, puis la production ; un hôte seul est complété
    .timeout(Duration.ofSeconds(30))               // par tentative, aussi délai de connexion
    .maxRetries(2)                                  // 0 désactive les relances (10 au plus)
    .maxRetryWait(Duration.ofSeconds(10))          // plus long Retry-After accepté
    .defaultCountryCode("243")                     // ajouté aux numéros locaux : 0812345678 → 243812345678
    .defaultSender("MABOUTIQUE")                   // utilisé quand l'envoi n'en précise pas
    .httpClient(monHttpClient)                     // votre propre client (ne doit pas suivre les redirections)
    .build();
```

- **Journalisation** : `System.Logger` nommé `cd.labyrinthe.connect` (vers `java.util.logging` par défaut, ou vers
  SLF4J/Log4j avec leur pont `System.Logger`, par ex. `org.slf4j:slf4j-jdk-platform-logging`). Requêtes et réponses
  en `DEBUG`, relances automatiques en `WARNING` — jamais la clé, un code, un corps ni un numéro complet
  (`24381*****78`). Les erreurs sont levées, pas journalisées.
- `toString()` affiche `lsk_test_****` ; les modèles masquent les numéros dans `toString()`.
- `PhoneNumbers.normalize("0812345678")` renvoie `Optional[243812345678]` avec les mêmes règles que l'API : sans
  « + », 11 chiffres ou plus ne commençant pas par 0 sont déjà internationaux (`33612345678` reste `33612345678`) ;
  utilisez le « + » pour un numéro étranger court. `isValid()` et `mask()` existent aussi. Les numéros internationaux ne sont jamais bloqués.
  Les requêtes transmettent toujours le numéro au format E.164 avec le « + » (`+243812345678`) : un numéro
  étranger court comme `+2481234567` garde son indicatif.
- `User-Agent: labyrinthe-java/<version> Java/<version>` ; `Version.VERSION` donne la version du SDK.

## Développement

```bash
./mvnw verify                 # tests unitaires + contrat OpenAPI, -Xlint:all -Werror, Spotless, SpotBugs, Javadoc
./mvnw spotless:apply         # formatage (google-java-format, JDK 17+)

# tests d'intégration contre un backend local, avec une clé de TEST uniquement
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 ./mvnw verify -Pintegration
```

## Licence

MIT — voir [LICENSE](LICENSE).

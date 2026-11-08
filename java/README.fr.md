# Labyrinthe Connect — SDK Java

[![CI](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/java.yml/badge.svg)](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/java.yml)
[![Maven Central](https://img.shields.io/maven-central/v/com.labyrinthe-rdc/labyrinthe-connect.svg?label=labyrinthe-connect)](https://central.sonatype.com/artifact/com.labyrinthe-rdc/labyrinthe-connect)
[![Maven Central](https://img.shields.io/maven-central/v/com.labyrinthe-rdc/labyrinthe-connect-spring-boot-starter.svg?label=spring-boot-starter)](https://central.sonatype.com/artifact/com.labyrinthe-rdc/labyrinthe-connect-spring-boot-starter)
![Java](https://img.shields.io/badge/java-11%20%E2%86%92%2021-007396)
![Licence](https://img.shields.io/badge/licence-MIT-green)

Client Java officiel de [Labyrinthe Connect](https://labyrinthe-rdc.com) : envoyez des SMS et vérifiez des numéros
de téléphone par code à usage unique (OTP), en RDC et en Afrique centrale.

*English version: [README.md](README.md).*

| Artefact | Contenu |
|---|---|
| `com.labyrinthe-rdc:labyrinthe-connect` | Le client (Java 11+) : SMS, solde, Sender IDs, OTP, vérification des webhooks. |
| `com.labyrinthe-rdc:labyrinthe-connect-spring-boot-starter` | Auto-configuration Spring Boot 3 (Java 17+) : bean client depuis les propriétés `labyrinthe.*` et point d'entrée de webhook signé qui publie des événements Spring. |

- Construit sur `java.net.http.HttpClient`. **Aucune dépendance à l'exécution** (pas même une bibliothèque JSON).
- API synchrone **et** asynchrone (`CompletableFuture`), client thread-safe, modèles immuables, `Optional` pour les
  champs facultatifs, `Instant` pour les dates, enums avec une valeur `UNKNOWN` pour les futures valeurs de l'API.
- Sûr par défaut : relances automatiques **uniquement** quand elles ne peuvent pas envoyer deux fois un SMS ou un OTP.
- Exceptions typées non vérifiées pour chaque erreur de l'API, dont l'erreur de maintenance `FeatureDisabledException`.
- Ne journalise ni n'affiche jamais votre clé API, les codes OTP ou les numéros complets.

**Sommaire :** [Prérequis](#prérequis) · [Installation](#installation) · [Démarrage rapide](#démarrage-rapide) ·
[Configuration](#configuration) · [Envoyer des SMS](#envoyer-des-sms) · [Solde et Sender IDs](#solde-et-sender-ids) ·
[OTP](#otp--vérifier-un-numéro-de-téléphone) · [Webhooks](#webhooks) · [Erreurs](#erreurs) ·
[Relances et idempotence](#relances-et-idempotence) · [Mode test, journalisation et sécurité](#mode-test-journalisation-et-sécurité) ·
[Sans le package](#sans-le-package-http-brut) · [Mise à jour](#mise-à-jour) · [Contribuer](#contribuer)

## Prérequis

| Artefact | Java | Framework |
|---|---|---|
| `labyrinthe-connect` | 11+ (testé sur 11, 17 et 21) | aucun |
| `labyrinthe-connect-spring-boot-starter` | 17+ | Spring Boot 3 (construit avec la 3.5) ; le point d'entrée de webhook demande Spring MVC (`spring-boot-starter-web`) |

## Installation

Maven :

```xml
<dependency>
  <groupId>com.labyrinthe-rdc</groupId>
  <artifactId>labyrinthe-connect</artifactId>
  <version>1.2.0</version>
</dependency>

<!-- Spring Boot 3 (Java 17+), à la place de la dépendance ci-dessus : il apporte labyrinthe-connect -->
<dependency>
  <groupId>com.labyrinthe-rdc</groupId>
  <artifactId>labyrinthe-connect-spring-boot-starter</artifactId>
  <version>1.2.0</version>
</dependency>
```

Gradle (DSL Kotlin) :

```kotlin
dependencies {
    implementation("com.labyrinthe-rdc:labyrinthe-connect:1.2.0")
    // Spring Boot 3 (Java 17+) :
    implementation("com.labyrinthe-rdc:labyrinthe-connect-spring-boot-starter:1.2.0")
}
```

Gradle (DSL Groovy) :

```groovy
dependencies {
    implementation 'com.labyrinthe-rdc:labyrinthe-connect:1.2.0'
    // Spring Boot 3 (Java 17+) :
    implementation 'com.labyrinthe-rdc:labyrinthe-connect-spring-boot-starter:1.2.0'
}
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
navigateur ni dans une application mobile.

| Clé | Mode | Effet |
|---|---|---|
| `lsk_test_…` | Bac à sable | Rien n'est envoyé, aucun crédit n'est consommé. Mêmes réponses qu'en production. L'envoi d'OTP renvoie le code (`testCode()`). |
| `lsk_live_…` | Production | Les SMS partent réellement et consomment des crédits. |

`client.isTestMode()` / `client.isLiveMode()` indiquent le mode utilisé. Tous les exemples ci-dessous fonctionnent
avec une clé de test. Le client est thread-safe et garde un pool de connexions `HttpClient` : créez-en **un** par
application et partagez-le.

### Synchrone et asynchrone

```java
Balance balance = client.balance().get();                          // bloquant

client.async().sms().send("0812345678", "Bonjour")                 // CompletableFuture<Message>
    .thenAccept(message -> System.out.println(message.id()));
```

`client.async()` expose les mêmes services (`sms()`, `balance()`, `senderIds()`, `otp()`) avec les mêmes règles
(relances, idempotence, erreurs). Les futures échouent avec une `LabyrintheException` (enveloppée dans une
`CompletionException` par `join()`, dans une `ExecutionException` par `get()`) ; des arguments invalides donnent aussi
une future en échec.

## Configuration

### Options du builder

| `LabyrintheClient.builder()` | Défaut | Rôle |
|---|---|---|
| `apiKey(String)` | `LABYRINTHE_API_KEY` | `lsk_live_…` ou `lsk_test_…`. Obligatoire (`InvalidArgumentException` sans clé). |
| `baseUrl(String)` | `LABYRINTHE_BASE_URL`, puis `https://api.labyrinthe-rdc.com/api/public/v1` | Racine de l'API. Un hôte seul (`http://localhost:8000`) est complété par `/api/public/v1`. |
| `timeout(Duration)` | 30 s | Délai de **chaque tentative**, aussi délai de connexion. |
| `maxRetries(int)` | 2 | Relances automatiques quand elles sont sûres ; `0` les désactive (10 au maximum). |
| `maxRetryWait(Duration)` | 10 s | Plus long `Retry-After` que le SDK accepte d'attendre ; au-delà, l'erreur est levée. |
| `defaultCountryCode(String)` | `"243"` | Ajouté aux numéros locaux (`0812345678` → `243812345678`) ; 1 à 3 chiffres, sans `+`. |
| `defaultSender(String)` | aucun | Sender ID utilisé quand un envoi n'en a pas. |
| `httpClient(HttpClient)` | un client créé par le SDK | Votre propre client (proxy, TLS, executor…). Il ne doit pas suivre les redirections (`HttpClient.Redirect.NEVER`, le défaut de `HttpClient.newBuilder()`) ; sinon `build()` le refuse. |

```java
LabyrintheClient client = LabyrintheClient.builder()
    .apiKey(System.getenv("LABYRINTHE_API_KEY"))
    .baseUrl("https://api.labyrinthe-rdc.com/api/public/v1")
    .timeout(Duration.ofSeconds(30))
    .maxRetries(2)
    .maxRetryWait(Duration.ofSeconds(10))
    .defaultCountryCode("243")
    .defaultSender("MABOUTIQUE")
    .httpClient(HttpClient.newBuilder().proxy(ProxySelector.of(new InetSocketAddress("proxy.local", 3128))).build())
    .build();
```

`LabyrintheClient.fromEnv()` équivaut à `LabyrintheClient.builder().build()` : clé et racine lues dans
l'environnement, options par défaut. Les constantes `LabyrintheClient.DEFAULT_BASE_URL`, `DEFAULT_TIMEOUT`,
`DEFAULT_MAX_RETRIES` et `DEFAULT_MAX_RETRY_WAIT` donnent les valeurs par défaut.

### Variables d'environnement

| Variable | Utilisée pour |
|---|---|
| `LABYRINTHE_API_KEY` | Clé API, quand `apiKey()` n'est pas appelé (active aussi le bean client Spring Boot) |
| `LABYRINTHE_BASE_URL` | Racine de l'API, quand `baseUrl()` n'est pas appelé (ex. `http://localhost:8000` pour un backend local) |
| `LABYRINTHE_WEBHOOK_SECRET` | Nom conventionnel du secret de webhook ; Spring Boot le lie à `labyrinthe.webhook.secret` |

### Spring Boot

Avec le starter dans le classpath, configurez `application.yml` (ou les clés équivalentes d'`application.properties`) :

```yaml
labyrinthe:
  api-key: ${LABYRINTHE_API_KEY}            # nécessaire au bean client (ou la variable d'environnement seule)
  base-url: https://api.labyrinthe-rdc.com/api/public/v1   # défaut : LABYRINTHE_BASE_URL, puis la production
  timeout: 30s
  max-retries: 2
  max-retry-wait: 10s
  default-country-code: "243"
  default-sender: MABOUTIQUE
  webhook:
    secret: ${LABYRINTHE_WEBHOOK_SECRET}    # le point d'entrée n'est enregistré que s'il est renseigné
    path: /webhooks/labyrinthe
    tolerance: 5m                           # 0 désactive le contrôle d'horodatage (déconseillé)
    enabled: true
```

```java
@Service
class Orders {
  private final LabyrintheClient labyrinthe;   // bean auto-configuré
  Orders(LabyrintheClient labyrinthe) { this.labyrinthe = labyrinthe; }

  String notifyReady(String phone) {
    return labyrinthe.sms().send(phone, "Votre commande est prête.").id();
  }
}
```

- `LabyrintheAutoConfiguration` crée le bean `LabyrintheClient` quand `labyrinthe.api-key` (ou `LABYRINTHE_API_KEY`)
  est renseignée et que vous n'avez pas déclaré votre propre bean `LabyrintheClient`. Toutes les clés ci-dessus sont
  facultatives et ont la valeur par défaut indiquée ; les IDE les complètent (métadonnées de configuration incluses).
- Des beans `LabyrintheClientBuilderCustomizer` ajustent le builder après les propriétés (appliqués dans l'ordre) :

```java
@Bean
LabyrintheClientBuilderCustomizer labyrintheProxy() {
  return builder -> builder.httpClient(HttpClient.newBuilder().proxy(ProxySelector.of(proxyAddress)).build());
}
```

- Le point d'entrée de webhook est décrit dans [Webhooks](#point-dentrée-spring-boot). Application d'exemple :
  [`SpringBootExample.java`](examples/src/spring/java/cd/labyrinthe/connect/examples/spring/SpringBootExample.java).

## Envoyer des SMS

```java
Message message = client.sms().send(SendSmsParams.builder()
    .to("+243 81 234 5678")         // +243…, 00243…, 243…, 0812…, 812…, 33612345678 sont acceptés
    .body("Votre commande 1042 est prête.")
    .sender("MABOUTIQUE")           // facultatif : un Sender ID approuvé (client.senderIds().list())
    .reference("commande-1042")     // facultatif : votre référence, 64 caractères au maximum
    .stripAccents(false)            // facultatif : voir « Coût avant envoi »
    .idempotencyKey("commande-1042-prete") // facultatif : voir « Idempotence »
    .build());

message.id();               // "9d5c0a3e-…"
message.recipient();        // "243812345678"
message.status();           // MessageStatus.QUEUED
message.segments();         // 1
message.creditsCharged();   // 1
message.createdAt();        // Instant
message.reference();        // Optional[commande-1042]
```

`client.sms().send(to, body)` est la forme courte. Le texte d'un message n'est jamais renvoyé par l'API (il peut
contenir un code).

L'envoi est asynchrone : `202 Accepted` signifie *mis en file et payé*, pas *remis*. Suivez le statut par webhook
(`message.status_changed`, recommandé) ou en interrogeant l'API :

```java
Message latest = client.sms().get(message.id());
if (latest.status().isFinal()) {           // aussi isFailed(), isPending()
  System.out.println(latest.status() == MessageStatus.DELIVERED
      ? "Remis" : "Échec : " + latest.errorCode().orElse("?"));
}
```

`MessageStatus` : `QUEUED`, `SUBMITTED`, `SENT`, `DELIVERED`, `FAILED`, `REJECTED`, `EXPIRED`, `UNDELIVERABLE`, et
`UNKNOWN` pour une valeur ajoutée après cette version du SDK. `status.value()` (et `toString()`) donne la valeur de
l'API (`"queued"`).

### Coût avant envoi

1 crédit = 1 segment SMS. Un message en ASCII pur (GSM-7) tient en 160 caractères (153 par segment au-delà). **Tout
caractère non ASCII** (é, à, ç, emoji, guillemets typographiques…) passe en UCS-2 : 70 caractères (67 par segment
au-delà).

```java
Estimate estimate = client.sms().estimate(EstimateSmsParams.builder()
    .body("Réunion demain à 9h")
    .recipients(List.of("0812345678", "0899999999"))
    .stripAccents(false)
    .build());
estimate.encoding();             // Encoding.UCS2
estimate.segmentsPerMessage();   // 1
estimate.recipientsCount();      // 2 (normalisés, doublons fusionnés, numéros invalides ignorés)
estimate.totalCredits();         // 2
estimate.balanceAfter();         // OptionalLong
estimate.isAffordable();         // le solde suffit-il ?
```

`stripAccents(true)` (sur `SendSmsParams`, `SendManySmsParams`, `EstimateSmsParams` et `SendOtpParams`) demande à
l'API de remplacer accents et caractères typographiques par de l'ASCII : le message passe en GSM-7, souvent deux
fois moins cher. La translittération est faite par le serveur : ne l'utilisez pas pour un texte exact.

**Numéros de téléphone.** `+` ou `00` conserve l'indicatif ; sans `+`, 11 chiffres ou plus ne commençant pas par 0
sont déjà internationaux (`33612345678` reste `33612345678`) ; sinon le numéro est local et reçoit l'indicatif par
défaut (`0812345678` → `243812345678`). Pour un numéro étranger court (10 chiffres ou moins), utilisez toujours le
`+`. Le SDK envoie chaque numéro en E.164 avec `+` ; les réponses utilisent le format international sans `+`.
`PhoneNumbers.normalize("0812345678")` (`Optional[243812345678]`), `PhoneNumbers.isValid()` et
`PhoneNumbers.mask()` appliquent les mêmes règles en local.

### Plusieurs destinataires : tout ou rien

```java
try {
  MessageBatch batch = client.sms().sendMany(SendManySmsParams.builder()
      .recipients(List.of("0812345678", "0899999999"))   // ou .addRecipient(…)
      .body("Réunion demain à 9h.")
      .sender("MABOUTIQUE")
      .reference("reunion-2026-11-07")
      .build());
  batch.messages();          // un message par numéro distinct (doublons fusionnés) ; MessageBatch est Iterable
  batch.idempotencyKey(); batch.replayed(); batch.requestId();
} catch (RecipientNotSendableException e) {
  // RIEN n'est parti : corrigez la liste et réessayez.
  for (RejectedRecipient rejected : e.rejected()) {
    System.out.println(rejected.recipient() + " : " + rejected.reason());   // INVALID_PHONE, BLACKLISTED, OPTED_OUT
  }
}
```

Jusqu'à 1000 destinataires par appel (`Limits.MAX_RECIPIENTS`). Chaque destinataire, le Sender ID et le solde sont
vérifiés **avant** tout envoi : un seul numéro refusé (ou un solde insuffisant) et aucun SMS ne part. Les numéros
que le SDK sait déjà invalides sont refusés localement (`InvalidArgumentException`, avec leur position seulement),
avant tout appel réseau.

### Lister les messages

```java
Page<Message> page = client.sms().list(ListSmsParams.builder()
    .status(MessageStatus.FAILED)
    .recipient("0812345678")             // tout format
    .from(LocalDate.of(2026, 11, 1))     // un LocalDate couvre la journée entière
    .to(Instant.now())                   // ou un OffsetDateTime
    .page(1)
    .perPage(50)                         // 15 par défaut, 100 au maximum
    .build());
page.data(); page.currentPage(); page.lastPage(); page.total(); page.hasMore();
Optional<Page<Message>> next = page.nextPage();

for (Message m : client.sms().list(ListSmsParams.builder().reference("commande-1042").build()).autoPagingIterable()) {
  // toutes les pages, chargées à la demande (aussi : autoPagingStream())
}

client.async().sms().list(ListSmsParams.defaults())
    .thenCompose(first -> first.autoPagingForEach(m -> handle(m)));   // ou autoPagingToList()
```

Filtres : `status`, `recipient`, `reference`, `from`, `to` (inclus), `page`, `perPage`. La liste ne contient que les
messages envoyés **avec la clé appelante** ; `get(id)` trouve n'importe quel message du compte, par `id` uniquement.

## Solde et Sender IDs

```java
Balance balance = client.balance().get();               // balance.balance(), balance.held() (crédits)
List<SenderId> senderIds = client.senderIds().list();

for (SenderId senderId : senderIds) {
  System.out.println(senderId.value() + " " + senderId.status() + " défaut=" + senderId.isDefault());  // utilisable comme sender
}
```

## OTP : vérifier un numéro de téléphone

Le code est généré, envoyé par SMS et vérifié par Labyrinthe Connect. Il n'est jamais renvoyé par l'API (sauf avec
une clé de test) et jamais journalisé par le SDK.

```java
// 1. Envoyer le code. Gardez otpId CÔTÉ SERVEUR (session, cache, base de données), jamais dans le navigateur.
OtpSendResult otp = client.otp().send("0812345678");
session.setAttribute("otpId", otp.otpId());
otp.expiresAt(); otp.maxAttempts();
otp.codeLength();           // ex. 6 : taille du champ de saisie
otp.codeType();             // OtpCodeType.NUMERIC (pavé numérique) ou ALPHANUMERIC
otp.resendAfterSeconds();   // ex. 60 : compte à rebours de « Renvoyer le code » (aussi resendAfter() : Duration)

// 2. L'utilisateur saisit le code reçu par SMS.

// 3. Le vérifier.
OtpVerifyResult result = client.otp().verify((String) session.getAttribute("otpId"), typedCode);
if (result.isVerified()) {
  session.removeAttribute("otpId");                 // connecter l'utilisateur / marquer le numéro comme vérifié
} else if (result.reason().orElseThrow() == OtpFailureReason.INVALID_CODE) {
  // mauvais code : une tentative consommée, il en reste result.attemptsRemaining() (OptionalInt)
} else {
  // EXPIRED, ALREADY_USED, MAX_ATTEMPTS_REACHED : demander un nouveau code
}
```

Toutes les options d'un envoi :

```java
OtpSendResult otp = client.otp().send(SendOtpParams.builder()
    .phone("0812345678")
    .template("Votre code MaBoutique : {code}")   // doit contenir {code} ; {app_name}, {minutes} facultatifs
    .locale(OtpLocale.FR)                         // FR ou EN (modèles standard)
    .webOtpDomain("app.example.com")              // ajoute « @app.example.com #123456 » pour la Web OTP API
    .stripAccents(true)
    .idempotencyKey("login-42-essai-1")           // facultatif : un UUID aléatoire sinon
    .build());
```

- Un mauvais code n'est **pas** une exception : `verify()` renvoie un résultat avec `isVerified() == false` et une
  `reason()`.
- Un nouvel `send()` au même numéro invalide le code précédent. Après une vérification réussie, un nouveau code peut
  être envoyé tout de suite.
- Longueur, validité, tentatives, Sender ID et modèle viennent par défaut des réglages OTP du compte.
- `webOtpDomain` active le remplissage automatique avec la
  [Web OTP API](https://developer.mozilla.org/docs/Web/API/WebOTP_API) (Chrome, Android).
- Limites : `OtpResendTooSoonException` et `OtpRateLimitedException` (`scope()` = `PHONE` / `ACCOUNT`, `limit()`),
  toutes deux avec `retryAfter()`. Jamais relancées par le SDK. `otp/send` est aussi limité à 10 requêtes par minute
  et par clé API.
- **Mode test** : avec une clé `lsk_test_`, aucun SMS ne part et `otp.testCode()` contient le code, pour tester tout
  le parcours. Toujours vide avec une clé live ; exclu de `toString()`, `equals()` et `hashCode()`.

`otp().send()` porte toujours une `Idempotency-Key` réutilisée par ses relances : un rejeu renvoie le même OTP
(`otp.replayed()`), jamais un second SMS. Chaque résultat de vérification est aussi notifié par les webhooks
`otp.verified` et `otp.failed`.

## Webhooks

Configurez un point d'entrée dans l'espace client (*Développeurs > Webhooks*) et copiez son secret. Les événements
sont envoyés en `POST` JSON (`{ id, event, data, timestamp }`) et signés : `X-Signature: sha256=<HMAC-SHA256(secret,
corps brut)>`. Le SDK vérifie la signature en temps constant (`MessageDigest.isEqual`) sur le **corps brut**, avant
toute lecture JSON, puis contrôle l'horodatage signé (5 minutes par défaut, protection contre le rejeu).

> Vérifiez toujours les **octets exacts** reçus (`byte[]`, ou le corps en `String` décodé en UTF-8). Un corps
> désérialisé en objet puis resérialisé ne correspond plus à la signature.

### Point d'entrée Spring Boot

Avec le starter et Spring MVC, `POST /webhooks/labyrinthe` (`labyrinthe.webhook.path`) est enregistré dès que
`labyrinthe.webhook.secret` est renseigné. Il lit le corps brut, le vérifie et publie chaque événement comme
événement d'application Spring : écoutez `WebhookEvent` ou une sous-classe.

```java
@Component
class LabyrintheEvents {
  @EventListener
  void onStatus(MessageStatusChanged event) {
    orders.updateSmsStatus(event.data().messageId(), event.data().status());
  }

  @EventListener
  void onOtpFailed(OtpFailed event) {          // aussi OtpVerified, BalanceLow, CampaignCompleted, TestPing, UnknownEvent
    audit.otpFailed(event.data().otpId(), event.data().reason());
  }
}
```

- Il répond `200 {"received":true}`, ou `400 {"error":"<code>"}` pour une signature invalide, un horodatage expiré ou
  un corps invalide. Un listener qui lève une exception donne un `500`, et la livraison est retentée : dédoublonnez
  sur `event.id()`.
- Répondez en moins de 10 secondes : confiez les traitements longs à des listeners `@Async` ou à une file d'attente.
- `labyrinthe.webhook.enabled=false` désactive le point d'entrée ; déclarer votre propre bean
  `LabyrintheWebhookController` le remplace.
- Avec Spring Security, exemptez le point d'entrée de CSRF et autorisez-le : la signature fait office
  d'authentification.

```java
@Bean
SecurityFilterChain security(HttpSecurity http) throws Exception {
  return http
      .csrf(csrf -> csrf.ignoringRequestMatchers("/webhooks/labyrinthe"))
      .authorizeHttpRequests(auth -> auth
          .requestMatchers(HttpMethod.POST, "/webhooks/labyrinthe").permitAll()
          .anyRequest().authenticated())
      .build();
}
```

### Votre propre contrôleur, ou tout framework

`Webhooks.constructEvent()` fonctionne partout où vous pouvez lire le corps brut. Un contrôleur Spring à vous (avec
`labyrinthe.webhook.enabled=false`) :

```java
@RestController
class SmsWebhookController {
  private final String secret;

  SmsWebhookController(@Value("${labyrinthe.webhook.secret}") String secret) { this.secret = secret; }

  @PostMapping("/hooks/sms")
  ResponseEntity<Void> receive(@RequestBody byte[] body,
                               @RequestHeader(name = Webhooks.SIGNATURE_HEADER, required = false) String signature) {
    WebhookEvent event;
    try {
      event = Webhooks.constructEvent(body, signature, secret);   // byte[] ou String, jamais du JSON resérialisé
    } catch (SignatureVerificationException | InvalidWebhookPayloadException e) {
      return ResponseEntity.badRequest().build();
    }
    if (event instanceof MessageStatusChanged) {
      MessageStatusChanged changed = (MessageStatusChanged) event;
      updateMessage(changed.data().messageId(), changed.data().status());
    }
    // ou : switch (event.eventType()) { case MESSAGE_STATUS_CHANGED: … }
    return ResponseEntity.ok().build();
  }
}
```

`Webhooks.constructEvent(body, header, secret, tolerance)` fixe une autre tolérance (`Webhooks.DEFAULT_TOLERANCE`
vaut 5 minutes ; `Duration.ZERO` désactive le contrôle, déconseillé). `Webhooks.verifySignature(body, header, secret)`
ne vérifie que la signature ; `Webhooks.sign(body, secret)` construit un en-tête valide pour vos tests. Un récepteur
complet avec le serveur HTTP du JDK : [`WebhookServer.java`](examples/src/main/java/cd/labyrinthe/connect/examples/WebhookServer.java).

### Événements

Chaque événement a `id()` (dédoublonnez dessus : les livraisons sont retentées jusqu'à 5 fois et peuvent arriver dans
le désordre), `type()`, `eventType()` (`WebhookEventType`), `timestamp()` (`Instant`), `raw()` et un `data()` typé :

| Classe | `type()` | `data()` |
|---|---|---|
| `MessageStatusChanged` | `message.status_changed` | `messageId`, `recipient`, `status`, `providerMessageId`, `campaignId`, `reference`, `errorCode`, `errorMessage` |
| `OtpVerified` | `otp.verified` | `OtpEventData` : `otpId`, `phone`, `status` (`VERIFIED`), `reason` (vide), `verifiedAt` |
| `OtpFailed` | `otp.failed` | `OtpEventData` : `status` `FAILED` (`reason` = `MAX_ATTEMPTS_REACHED`) ou `EXPIRED` (`reason` = `EXPIRED`) |
| `CampaignCompleted` | `campaign.completed` | `campaignId`, `name`, `recipientsCount`, `messagesByStatus`, `creditsCharged`, `completedAt` |
| `BalanceLow` | `balance.low` | `balance`, `threshold` |
| `TestPing` | `test.ping` | `Map` (vide) |
| `UnknownEvent` | un type ajouté après cette version du SDK | `Map`, tel quel ; répondez 2xx |

La hiérarchie est fermée (constructeurs package-private). Répondez `2xx` en moins de 10 secondes ; mettez les
traitements longs en file d'attente.

## Erreurs

Toutes les exceptions sont non vérifiées et héritent de `LabyrintheException`, avec `code()` (stable, pour votre
code), `getMessage()` (en français, pour les humains), `statusCode()` (statut HTTP, `0` sans réponse) et `details()`.
Les erreurs de l'API héritent d'`ApiException` et exposent aussi `headers()`, `requestId()` (`X-Request-Id`, à donner
au support) et `apiVersion()`.

| Exception | HTTP / code | Quand — accesseurs utiles |
|---|---|---|
| `AuthenticationException` | 401 `UNAUTHENTICATED` | Clé absente, invalide ou révoquée |
| `PermissionException` | 403 `FORBIDDEN`, `OTP_DISABLED_FOR_ACCOUNT` | IP non autorisée pour cette clé, OTP désactivé pour le compte |
| `AccountSuspendedException` | 403 `ACCOUNT_SUSPENDED` | Hérite de `PermissionException` ; toute l'API est fermée au compte |
| `NotFoundException` | 404 `NOT_FOUND` | Message ou `otpId` inconnu, route inconnue |
| `ValidationException` | 422 `VALIDATION_ERROR` | `fieldErrors()` (champ → messages) |
| `RecipientNotSendableException` | 422 `RECIPIENT_NOT_SENDABLE` | `rejected()`, `reason()` ; rien n'est parti |
| `SenderIdNotApprovedException` | 422 `SENDER_ID_NOT_APPROVED` | `sender` n'est pas un Sender ID approuvé |
| `InvalidPhoneException` | 422 `INVALID_PHONE` | Numéro OTP refusé par l'API |
| `InsufficientCreditsException` | 409 `INSUFFICIENT_CREDITS` | `available()`, `required()` (`OptionalLong`) ; rien n'est parti (jamais en mode test) |
| `IdempotencyKeyReusedException` | 409 `IDEMPOTENCY_KEY_REUSED` | Même clé, requête différente |
| `IdempotencyRequestInProgressException` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` | `retryAfter()` ; déjà relancée par le SDK |
| `IdempotencyKeyUnresolvedException` | 409 `IDEMPOTENCY_KEY_UNRESOLVED` | Issue inconnue, un SMS a pu partir ; jamais relancée |
| `RateLimitException` | 429 `TOO_MANY_REQUESTS` | `retryAfter()` |
| `OtpResendTooSoonException`, `OtpRateLimitedException` | 429 | Héritent de `RateLimitException` ; `scope()`, `limit()` (plafond atteint) |
| `FeatureDisabledException` | 503 `FEATURE_DISABLED`, `OTP_SERVICE_DISABLED` | Hérite de `ServiceUnavailableException` ; maintenance, rien n'a été fait ; `feature()`, `blockedBy()`, `adminMessage()`, `until()`, `retryAfter()` |
| `ServiceUnavailableException` | autres 5xx | Panne temporaire de l'API |
| `ApiException` | tout autre code | La liste est ouverte : de nouveaux codes peuvent apparaître |
| `ApiConnectionException`, `ApiTimeoutException` | `CONNECTION_ERROR`, `TIMEOUT` | Erreur réseau, délai d'une tentative dépassé (`ApiTimeoutException` hérite d'`ApiConnectionException`) |
| `InvalidArgumentException` | `INVALID_ARGUMENT` | Saisie invalide détectée par le SDK, avant tout appel |
| `UnexpectedResponseException` | — | Une réponse que le SDK ne sait pas lire |
| `SignatureVerificationException`, `InvalidWebhookPayloadException` | — | Vérification d'un webhook (répondre 400) |

Attrapez les types les plus précis en premier :

```java
try {
  client.sms().send("0812345678", "Bonjour");
} catch (InsufficientCreditsException e) {
  alertFinance(e.available().orElse(0), e.required().orElse(0));
} catch (ValidationException e) {
  e.fieldErrors().forEach((field, messages) -> log.warn("{} : {}", field, String.join(" ", messages)));
} catch (ApiException e) {
  log.error("Erreur Labyrinthe Connect {} (HTTP {}, requête {})", e.code(), e.statusCode(), e.requestId().orElse("?"));
} catch (ApiConnectionException e) {
  log.error("Labyrinthe Connect injoignable : {}", e.code());   // CONNECTION_ERROR ou TIMEOUT
}
```

### Maintenance (503 `FEATURE_DISABLED`)

Un administrateur de la plateforme peut couper temporairement toute l'API (`blockedBy()` = `api`, toutes les routes,
même avant la vérification de la clé) ou une fonction : `api.sms_send` (`sms().send` / `sendMany`), `api.otp_send`
(`otp().send`), `api.otp_verify` (`otp().verify`). Le service OTP entier coupé répond par le code historique
`OTP_SERVICE_DISABLED` sur `otp().send()`, avec les mêmes détails (`feature()` = `blockedBy()` = `otp`). Rien n'est
fait (aucun SMS, aucun débit). Le SDK ne relance que si une réactivation est programmée dans la limite de
`maxRetryWait` (`Retry-After`), en attendant exactement ce délai ; sinon il lève l'exception aussitôt. Ne relancez
pas en boucle : mettez l'appel en file d'attente et prévenez l'utilisateur.

```java
try {
  client.sms().send("0812345678", "Bonjour");
} catch (FeatureDisabledException e) {
  // e.feature() = Optional[api.sms_send], e.blockedBy() = Optional[api] quand toute l'API est coupée
  String text = e.adminMessage().orElse("L'envoi de SMS est temporairement indisponible.");
  Instant retryAt = e.until().orElseGet(() -> Instant.now().plus(e.retryAfter().orElse(Duration.ofMinutes(15))));
  outbox.retryAt(order, retryAt, text);   // votre file d'attente
}
```

Chaque accesseur renvoie un `Optional` vide quand l'API n'envoie pas la valeur. Attrapez `FeatureDisabledException`
avant `ServiceUnavailableException`.

## Relances et idempotence

### Relances automatiques

Le SDK relance automatiquement (par défaut : 2 relances, backoff exponentiel 0,5 s → 1 s → 2 s avec jitter, plafonné
à 8 s) **uniquement quand un rejeu ne peut pas produire deux fois un effet** :

| Requête | Erreur réseau / délai | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` | 503 `FEATURE_DISABLED` |
|---|---|---|---|---|---|
| `get`, `list`, `balance().get`, `senderIds().list`, `estimate` | relancée | relancée | après `Retry-After` | — | seulement après `Retry-After` |
| `send` / `sendMany` / `otp().send` (toujours avec une `Idempotency-Key`) | relancée, même clé | relancée, même clé | après `Retry-After` | après `Retry-After`, même clé | seulement après `Retry-After`, même clé |
| `otp().verify` | **jamais** | **jamais** | après `Retry-After` (refusée avant traitement) | — | seulement après `Retry-After` (refusée avant traitement) |

Les erreurs métier (autres 4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, 409 `IDEMPOTENCY_KEY_UNRESOLVED`) ne sont
jamais relancées. Quand l'API demande d'attendre plus que `maxRetryWait`, le SDK lève l'exception
(`RateLimitException`, `FeatureDisabledException`…) au lieu d'attendre. Les redirections ne sont jamais suivies.

### Idempotence (pas de double envoi)

Chaque envoi de SMS et d'OTP porte une `Idempotency-Key`. Si vous n'en donnez pas, le SDK génère un UUID aléatoire et
le réutilise pour ses propres relances : une erreur réseau ne produit jamais de second SMS. Pour vous protéger aussi
de **vos** relances (files de tâches, double clic, plantage), passez une clé dérivée de votre événement métier. Un
rejeu dans les 24 heures renvoie le résultat d'origine sans nouvel envoi ni débit :

```java
MessageBatch batch = client.sms().sendMany(SendManySmsParams.builder()
    .addRecipient("0812345678")
    .body("Votre commande 1042 est prête.")
    .idempotencyKey("commande-1042-prete")   // 255 caractères au maximum
    .build());
batch.replayed();   // true s'il s'agissait d'un rejeu
```

- La même clé avec un autre contenu : `IdempotencyKeyReusedException` (409). Les destinataires sont comparés après
  normalisation (`0812345678` puis `243812345678` est un rejeu). `/sms` et `/otp/send` ont des espaces de clés séparés.
- Requête d'origine encore en cours : l'API répond 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` ; le SDK attend
  `Retry-After` et relance avec la même clé, puis lève `IdempotencyRequestInProgressException` (`retryAfter()`).
- `IdempotencyKeyUnresolvedException` (409 `IDEMPOTENCY_KEY_UNRESOLVED`) : la requête d'origine a échoué de façon
  inattendue côté API et son issue est inconnue : un SMS **a peut-être** été envoyé. Ni le SDK ni vous ne devez la
  relancer automatiquement (la même clé renvoie toujours cette erreur ; une nouvelle clé pourrait envoyer deux fois).
  Vérifiez d'abord (`sms().list()` avec la `reference` envoyée, ou demandez à l'utilisateur si le code est arrivé),
  puis, seulement si rien n'est parti, renvoyez avec une **nouvelle** clé.

```java
try {
  client.sms().send(params);
} catch (IdempotencyKeyUnresolvedException e) {
  Page<Message> sent = client.sms().list(ListSmsParams.builder().reference("commande-1042").build());
  log.warn("Issue inconnue (requête {}), {} message(s) trouvé(s)", e.requestId().orElse("?"), sent.total());
}
```

## Mode test, journalisation et sécurité

### Mode test

Une clé `lsk_test_…` utilise un fournisseur simulé : **rien n'est envoyé, aucun crédit n'est consommé**, les
réponses ont la forme de la production. Deux différences : le solde n'est pas vérifié (pas
d'`InsufficientCreditsException` en mode test) et `otp().send()` renvoie le code dans `testCode()`. Utilisez les clés
de test en développement, en CI et dans les exemples ; vérifiez `client.isTestMode()` au démarrage si vous voulez
refuser une clé live hors production.

### Journalisation

Le SDK journalise via `System.Logger`, nommé `cd.labyrinthe.connect` (dirigé vers `java.util.logging` par défaut, ou
vers SLF4J / Log4j avec leur pont `System.Logger`, ex. `org.slf4j:slf4j-jdk-platform-logging`). Requêtes et réponses
sont journalisées en `DEBUG`, les relances automatiques en `WARNING` — jamais la clé, un code, un corps ni un numéro
complet (`24381*****78`). Les erreurs sont levées, pas journalisées. Avec Spring Boot et le pont SLF4J :
`logging.level.cd.labyrinthe.connect=DEBUG`.

### Sécurité

- `client.toString()` affiche `lsk_test_****` ; les modèles masquent les numéros dans `toString()` ; `OtpSendResult`
  exclut `testCode()` de `toString()` ; `LabyrintheProperties.toString()` masque la clé et le secret de webhook ; les
  exceptions sont sérialisables sans leurs `details()`.
- Les clés restent côté serveur ; injectez-les depuis des variables d'environnement ou un coffre, jamais depuis un
  fichier versionné.
- Webhooks : signature vérifiée en temps constant sur le corps brut, tolérance d'horodatage, dédoublonnage sur `id()`.

### Identifiants de requête, délais par appel et en-têtes de réponse

Chaque réponse porte `X-Request-Id` : `ApiException.requestId()` et `MessageBatch.requestId()` l'exposent. Chaque
méthode accepte aussi un `RequestOptions` : un délai pour un appel, votre propre `X-Request-Id` (envoyé à chaque
relance, renvoyé par l'API) et un callback qui lit les en-têtes de réponse (`ResponseInfo`) :

```java
client.sms().send(params, RequestOptions.builder()
    .timeout(Duration.ofSeconds(5))                       // par tentative, pour cet appel seulement
    .requestId("commande-1042")                           // 1 à 128 caractères parmi A-Z a-z 0-9 . _ : -
    .onResponse(info -> log.info("requête {} (API v{}), {} requêtes restantes, rejeu={}",
        info.requestId().orElse("?"), info.apiVersion().orElse("?"),
        info.rateLimitRemaining().orElse(-1), info.replayed()))
    .build());

client.balance().get(RequestOptions.requestId("health-check-42"));   // raccourcis : RequestOptions.timeout(…), none()
```

### Délais

`timeout` (builder, ou `RequestOptions` par appel) limite **chaque tentative** (la valeur du builder est aussi le
délai de connexion) ; une tentative qui le dépasse lève `ApiTimeoutException` (et est relancée quand c'est sûr). La
durée totale d'un appel vaut au plus `(maxRetries + 1)` tentatives plus les attentes entre elles. Avec l'API
asynchrone, `future.orTimeout(…)` borne votre attente mais n'arrête pas un envoi déjà parti : renvoyez avec la
**même** `idempotencyKey` pour connaître son issue sans double envoi.

## Sans le package (HTTP brut)

L'API est du JSON sur HTTPS ; le SDK est une commodité. Avec `java.net.http.HttpClient` seulement :

```java
HttpClient http = HttpClient.newHttpClient();
HttpRequest request = HttpRequest.newBuilder(URI.create("https://api.labyrinthe-rdc.com/api/public/v1/sms"))
    .timeout(Duration.ofSeconds(30))
    .header("Authorization", "Bearer " + System.getenv("LABYRINTHE_API_KEY"))
    .header("Content-Type", "application/json")
    .header("Accept", "application/json")
    .header("Idempotency-Key", UUID.randomUUID().toString())   // à conserver pour relancer sans risque
    .POST(HttpRequest.BodyPublishers.ofString(
        "{\"recipient\":\"+243812345678\",\"body\":\"Votre commande 1042 est prete.\",\"reference\":\"commande-1042\"}"))
    .build();

HttpResponse<String> response = http.send(request, HttpResponse.BodyHandlers.ofString());
// 202 : {"data":[{"id":"…","recipient":"243812345678","status":"queued",…}]}
// erreur : {"error":{"code":"…","message":"…","details":{}}}, avec X-Request-Id
```

Signature de webhook sans le package : `Mac mac = Mac.getInstance("HmacSHA256"); mac.init(new
SecretKeySpec(secret.getBytes(UTF_8), "HmacSHA256"));` puis comparez `"sha256=" + hex(mac.doFinal(rawBody))` à
`X-Signature` avec `MessageDigest.isEqual`, et vérifiez que le `timestamp` de l'enveloppe (secondes Unix) est récent.
Les relances, l'idempotence, la lecture des erreurs et le format des numéros sont alors à votre charge.

Référence : la spécification OpenAPI [`openapi/openapi.yaml`](../openapi/openapi.yaml) (1.3.0), aussi servie par
l'API sur `/api/public/openapi.yaml`.

### Limites connues de l'API

- Les points d'entrée de webhook se gèrent uniquement dans l'espace client (pas d'API).
- `INSUFFICIENT_CREDITS` ne se reproduit pas avec une clé de test.
- `estimate()` compte les numéros en liste noire ou désinscrits que `sendMany()` refuserait.
- `list()` renvoie les messages de la clé appelante ; `get()` trouve n'importe quel message du compte.

## Mise à jour

Le SDK suit SemVer. Voir le [CHANGELOG](CHANGELOG.md) (en anglais).

- **1.1.x → 1.2.0** (API 1.3.0) : nouvelle `FeatureDisabledException` (503 `FEATURE_DISABLED`).
  `OTP_SERVICE_DISABLED` est désormais aussi une `FeatureDisabledException` (toujours une
  `ServiceUnavailableException` : vos blocs `catch` existants fonctionnent encore). **Changement de comportement** :
  `OTP_SERVICE_DISABLED` n'est plus relancé avec le backoff des 5xx ; les deux codes ne sont relancés qu'après un
  `Retry-After` inférieur à `maxRetryWait`.
- **1.1.0 → 1.1.1** : les numéros partent en E.164 avec `+` ; nouvelle `IdempotencyKeyUnresolvedException`.
- **1.0.x → 1.1.0** (API 1.2.0) : `RequestOptions` / `ResponseInfo`, `otp().send()` idempotent et relancé,
  `stripAccents`, `webOtpDomain`, événements `otp.verified` / `otp.failed`. Sans `+`, 11 chiffres ou plus ne
  commençant pas par 0 sont désormais lus comme internationaux.

## Contribuer

Les issues et pull requests vont dans le [monorepo](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks) ;
lisez d'abord [CONTRIBUTING.md](../CONTRIBUTING.md). Depuis `java/` :

```bash
./mvnw verify                 # tests unitaires + contrat OpenAPI, -Xlint:all -Werror, Spotless, SpotBugs, Javadoc
./mvnw spotless:apply         # formatage (google-java-format, JDK 21+)
./mvnw -q -pl examples -am verify -DskipTests   # construire seulement les exemples

# tests d'intégration contre un backend local, avec une clé de TEST uniquement
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 ./mvnw verify -Pintegration
```

Modules : `core/` (`labyrinthe-connect`, Java 11), `spring-boot-starter/` (Java 17), `examples/` (compilés à chaque
build, jamais publiés) : [`SendSms`](examples/src/main/java/cd/labyrinthe/connect/examples/SendSms.java),
[`OtpLogin`](examples/src/main/java/cd/labyrinthe/connect/examples/OtpLogin.java),
[`WebhookServer`](examples/src/main/java/cd/labyrinthe/connect/examples/WebhookServer.java) et
[`SpringBootExample`](examples/src/spring/java/cd/labyrinthe/connect/examples/spring/SpringBootExample.java). Sur un
JDK 11, seuls `core` et les exemples Java simples sont construits. Failles de sécurité : connect@labyrinthe-rdc.com
plutôt qu'une issue publique.

## Licence

MIT — voir [LICENSE](LICENSE).

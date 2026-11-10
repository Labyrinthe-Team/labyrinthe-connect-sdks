# Labyrinthe Connect — Java SDK

[![CI](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/java.yml/badge.svg)](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/java.yml)
[![Maven Central](https://img.shields.io/maven-central/v/com.labyrinthe-rdc/labyrinthe-connect.svg?label=labyrinthe-connect)](https://central.sonatype.com/artifact/com.labyrinthe-rdc/labyrinthe-connect)
[![Maven Central](https://img.shields.io/maven-central/v/com.labyrinthe-rdc/labyrinthe-connect-spring-boot-starter.svg?label=spring-boot-starter)](https://central.sonatype.com/artifact/com.labyrinthe-rdc/labyrinthe-connect-spring-boot-starter)
![Java](https://img.shields.io/badge/java-11%20%E2%86%92%2021-007396)
![License](https://img.shields.io/badge/license-MIT-green)

Official Java client for [Labyrinthe Connect](https://connect.labyrinthe-rdc.com): send SMS and verify phone numbers
with one-time codes (OTP), in the DR Congo and Central Africa.

*Version française : [README.fr.md](README.fr.md).*

| Artifact | Content |
|---|---|
| `com.labyrinthe-rdc:labyrinthe-connect` | The client (Java 11+): SMS, balance, Sender IDs, OTP, webhook verification. |
| `com.labyrinthe-rdc:labyrinthe-connect-spring-boot-starter` | Spring Boot 3 auto-configuration (Java 17+): client bean from `labyrinthe.*` properties and a signed webhook endpoint publishing Spring events. |

- Built on `java.net.http.HttpClient`. **No runtime dependency** (not even a JSON library).
- Synchronous **and** asynchronous (`CompletableFuture`) API, thread-safe client, immutable models, `Optional` for
  nullable fields, `Instant` for dates, enums with an `UNKNOWN` value for future API values.
- Safe by default: automatic retries **only** when they cannot send an SMS or an OTP twice.
- Unchecked, typed exceptions for every API error, including the maintenance error `FeatureDisabledException`.
- Never logs or prints your API key, OTP codes or full phone numbers.

**Contents:** [Requirements](#requirements) · [Installation](#installation) · [Quick start](#quick-start) ·
[Configuration](#configuration) · [Sending SMS](#sending-sms) · [Balance and Sender IDs](#balance-and-sender-ids) ·
[OTP](#otp-verify-a-phone-number) · [Webhooks](#webhooks) · [Errors](#errors) ·
[Retries and idempotency](#retries-and-idempotency) · [Test mode, logging and security](#test-mode-logging-and-security) ·
[Without the package](#without-the-package-raw-http) · [Upgrading](#upgrading) · [Contributing](#contributing)

## Requirements

| Artifact | Java | Framework |
|---|---|---|
| `labyrinthe-connect` | 11+ (tested on 11, 17 and 21) | none |
| `labyrinthe-connect-spring-boot-starter` | 17+ | Spring Boot 3 (built against 3.5); the webhook endpoint needs Spring MVC (`spring-boot-starter-web`) |

## Installation

Maven:

```xml
<dependency>
  <groupId>com.labyrinthe-rdc</groupId>
  <artifactId>labyrinthe-connect</artifactId>
  <version>1.2.1</version>
</dependency>

<!-- Spring Boot 3 (Java 17+), instead of the line above: it brings labyrinthe-connect -->
<dependency>
  <groupId>com.labyrinthe-rdc</groupId>
  <artifactId>labyrinthe-connect-spring-boot-starter</artifactId>
  <version>1.2.1</version>
</dependency>
```

Gradle (Kotlin DSL):

```kotlin
dependencies {
    implementation("com.labyrinthe-rdc:labyrinthe-connect:1.2.1")
    // Spring Boot 3 (Java 17+):
    implementation("com.labyrinthe-rdc:labyrinthe-connect-spring-boot-starter:1.2.1")
}
```

Gradle (Groovy DSL):

```groovy
dependencies {
    implementation 'com.labyrinthe-rdc:labyrinthe-connect:1.2.1'
    // Spring Boot 3 (Java 17+):
    implementation 'com.labyrinthe-rdc:labyrinthe-connect-spring-boot-starter:1.2.1'
}
```

## Quick start

```java
import cd.labyrinthe.connect.LabyrintheClient;
import cd.labyrinthe.connect.Message;

LabyrintheClient client = LabyrintheClient.builder().apiKey("lsk_test_…").build(); // or LabyrintheClient.fromEnv()
Message message = client.sms().send("0812345678", "Your order 1042 is ready.");
System.out.println(message.status());                                              // queued
```

Create your keys in the client area (*Developers > API keys*). **Keep them server-side**, never in a browser or a
mobile app.

| Key | Mode | Effect |
|---|---|---|
| `lsk_test_…` | Sandbox | Nothing is sent, no credit is used. Same responses as production. OTP sends return the code (`testCode()`). |
| `lsk_live_…` | Production | SMS are really sent and credits are used. |

`client.isTestMode()` / `client.isLiveMode()` tell you which one you are using. Every example below works with a test
key. The client is thread-safe and holds an `HttpClient` connection pool: create **one** per application and share it.

### Synchronous and asynchronous

```java
Balance balance = client.balance().get();                          // blocking

client.async().sms().send("0812345678", "Hello")                   // CompletableFuture<Message>
    .thenAccept(message -> System.out.println(message.id()));
```

`client.async()` exposes the same services (`sms()`, `balance()`, `senderIds()`, `otp()`) with the same rules
(retries, idempotency, errors). Futures fail with a `LabyrintheException` (wrapped in a `CompletionException` by
`join()`, in an `ExecutionException` by `get()`); invalid arguments also give a failed future.

## Configuration

### Builder options

| `LabyrintheClient.builder()` | Default | Meaning |
|---|---|---|
| `apiKey(String)` | `LABYRINTHE_API_KEY` | `lsk_live_…` or `lsk_test_…`. Required (`InvalidArgumentException` without one). |
| `baseUrl(String)` | `LABYRINTHE_BASE_URL`, then `https://api.connect.labyrinthe-rdc.com/api/public/v1` | API root. A bare host (`http://localhost:8000`) is completed with `/api/public/v1`. |
| `timeout(Duration)` | 30 s | Timeout of **each attempt**, also the connect timeout. |
| `maxRetries(int)` | 2 | Automatic retries when they are safe; `0` disables them (max 10). |
| `maxRetryWait(Duration)` | 10 s | Longest `Retry-After` the SDK accepts to wait; beyond it, the error is thrown. |
| `defaultCountryCode(String)` | `"243"` | Added to local numbers (`0812345678` → `243812345678`); 1 to 3 digits, without `+`. |
| `defaultSender(String)` | none | Sender ID used when a send has none. |
| `httpClient(HttpClient)` | a client created by the SDK | Your own client (proxy, TLS, executor…). It must not follow redirects (`HttpClient.Redirect.NEVER`, the default of `HttpClient.newBuilder()`); otherwise `build()` refuses it. |

```java
LabyrintheClient client = LabyrintheClient.builder()
    .apiKey(System.getenv("LABYRINTHE_API_KEY"))
    .baseUrl("https://api.connect.labyrinthe-rdc.com/api/public/v1")
    .timeout(Duration.ofSeconds(30))
    .maxRetries(2)
    .maxRetryWait(Duration.ofSeconds(10))
    .defaultCountryCode("243")
    .defaultSender("MYSHOP")
    .httpClient(HttpClient.newBuilder().proxy(ProxySelector.of(new InetSocketAddress("proxy.local", 3128))).build())
    .build();
```

`LabyrintheClient.fromEnv()` is the same as `LabyrintheClient.builder().build()`: key and root from the environment,
default options. The constants `LabyrintheClient.DEFAULT_BASE_URL`, `DEFAULT_TIMEOUT`, `DEFAULT_MAX_RETRIES` and
`DEFAULT_MAX_RETRY_WAIT` hold the defaults.

### Environment variables

| Variable | Used for |
|---|---|
| `LABYRINTHE_API_KEY` | API key, when `apiKey()` is not called (also enables the Spring Boot client bean) |
| `LABYRINTHE_BASE_URL` | API root, when `baseUrl()` is not called (e.g. `http://localhost:8000` for a local backend) |
| `LABYRINTHE_WEBHOOK_SECRET` | Conventional name for the webhook secret; Spring Boot binds it to `labyrinthe.webhook.secret` |

### Spring Boot

With the starter on the classpath, configure `application.yml` (or the equivalent `application.properties` keys):

```yaml
labyrinthe:
  api-key: ${LABYRINTHE_API_KEY}            # required for the client bean (or the env variable alone)
  base-url: https://api.connect.labyrinthe-rdc.com/api/public/v1   # default: LABYRINTHE_BASE_URL, then production
  timeout: 30s
  max-retries: 2
  max-retry-wait: 10s
  default-country-code: "243"
  default-sender: MYSHOP
  webhook:
    secret: ${LABYRINTHE_WEBHOOK_SECRET}    # the endpoint is registered only when it is set
    path: /webhooks/labyrinthe
    tolerance: 5m                           # 0 disables the timestamp check (not recommended)
    enabled: true
```

```java
@Service
class Orders {
  private final LabyrintheClient labyrinthe;   // auto-configured bean
  Orders(LabyrintheClient labyrinthe) { this.labyrinthe = labyrinthe; }

  String notifyReady(String phone) {
    return labyrinthe.sms().send(phone, "Your order is ready.").id();
  }
}
```

- `LabyrintheAutoConfiguration` creates the `LabyrintheClient` bean when `labyrinthe.api-key` (or
  `LABYRINTHE_API_KEY`) is set and you did not declare your own `LabyrintheClient` bean. Every key above is optional
  and has the default shown; IDEs complete them (configuration metadata is included).
- `LabyrintheClientBuilderCustomizer` beans adjust the builder after the properties (applied in order):

```java
@Bean
LabyrintheClientBuilderCustomizer labyrintheProxy() {
  return builder -> builder.httpClient(HttpClient.newBuilder().proxy(ProxySelector.of(proxyAddress)).build());
}
```

- The webhook endpoint is described in [Webhooks](#spring-boot-endpoint). Example application:
  [`SpringBootExample.java`](examples/src/spring/java/cd/labyrinthe/connect/examples/spring/SpringBootExample.java).

## Sending SMS

```java
Message message = client.sms().send(SendSmsParams.builder()
    .to("+243 81 234 5678")         // +243…, 00243…, 243…, 0812…, 812…, 33612345678 are all accepted
    .body("Your order 1042 is ready.")
    .sender("MYSHOP")               // optional: an approved Sender ID (client.senderIds().list())
    .reference("order-1042")        // optional: your own reference, max 64 characters
    .stripAccents(false)            // optional: see "Cost before sending"
    .idempotencyKey("order-1042-ready") // optional: see "Idempotency"
    .build());

message.id();               // "9d5c0a3e-…"
message.recipient();        // "243812345678"
message.status();           // MessageStatus.QUEUED
message.segments();         // 1
message.creditsCharged();   // 1
message.createdAt();        // Instant
message.reference();        // Optional[order-1042]
```

`client.sms().send(to, body)` is the short form. The text of a message is never returned by the API (it may contain
a code).

Sending is asynchronous: `202 Accepted` means *queued and paid for*, not *delivered*. Follow the status with
webhooks (`message.status_changed`, recommended) or by polling:

```java
Message latest = client.sms().get(message.id());
if (latest.status().isFinal()) {           // also isFailed(), isPending()
  System.out.println(latest.status() == MessageStatus.DELIVERED
      ? "Delivered" : "Failed: " + latest.errorCode().orElse("?"));
}
```

`MessageStatus`: `QUEUED`, `SUBMITTED`, `SENT`, `DELIVERED`, `FAILED`, `REJECTED`, `EXPIRED`, `UNDELIVERABLE`, and
`UNKNOWN` for a value added after this SDK version. `status.value()` (and `toString()`) gives the API value
(`"queued"`).

### Cost before sending

1 credit = 1 SMS segment. A pure ASCII message (GSM-7) holds 160 characters (153 per segment beyond). **Any
non-ASCII character** (é, à, ç, emoji, curly quotes…) switches to UCS-2: 70 characters (67 per segment beyond).

```java
Estimate estimate = client.sms().estimate(EstimateSmsParams.builder()
    .body("Réunion demain à 9h")
    .recipients(List.of("0812345678", "0899999999"))
    .stripAccents(false)
    .build());
estimate.encoding();             // Encoding.UCS2
estimate.segmentsPerMessage();   // 1
estimate.recipientsCount();      // 2 (normalised, duplicates merged, invalid numbers ignored)
estimate.totalCredits();         // 2
estimate.balanceAfter();         // OptionalLong
estimate.isAffordable();         // does the balance cover it?
```

`stripAccents(true)` (on `SendSmsParams`, `SendManySmsParams`, `EstimateSmsParams` and `SendOtpParams`) asks the
API to replace accents and typographic characters by ASCII: the message becomes GSM-7, often half the price. The
transliteration is done by the server: do not use it for an exact text.

**Phone numbers.** `+` or `00` keeps the country code; without `+`, 11 digits or more not starting with 0 are already
international (`33612345678` stays `33612345678`); otherwise the number is local and gets the default country code
(`0812345678` → `243812345678`). For a short foreign number (10 digits or fewer), always use `+`. The SDK sends every
number in E.164 with `+`; responses use international digits without `+`. `PhoneNumbers.normalize("0812345678")`
(`Optional[243812345678]`), `PhoneNumbers.isValid()` and `PhoneNumbers.mask()` apply the same rules locally.

### Several recipients: all or nothing

```java
try {
  MessageBatch batch = client.sms().sendMany(SendManySmsParams.builder()
      .recipients(List.of("0812345678", "0899999999"))   // or .addRecipient(…)
      .body("Meeting tomorrow at 9am.")
      .sender("MYSHOP")
      .reference("meeting-2026-11-07")
      .build());
  batch.messages();          // one message per distinct number (duplicates are merged); MessageBatch is Iterable
  batch.idempotencyKey(); batch.replayed(); batch.requestId();
} catch (RecipientNotSendableException e) {
  // NOTHING was sent: fix the list and try again.
  for (RejectedRecipient rejected : e.rejected()) {
    System.out.println(rejected.recipient() + ": " + rejected.reason());   // INVALID_PHONE, BLACKLISTED, OPTED_OUT
  }
}
```

Up to 1000 recipients per call (`Limits.MAX_RECIPIENTS`). Every recipient, the Sender ID and the balance are
checked **before** anything is sent: one refused number (or an insufficient balance) means no SMS at all. Numbers
the SDK can already tell are invalid are refused locally (`InvalidArgumentException`, with their index only),
before any network call.

### Listing messages

```java
Page<Message> page = client.sms().list(ListSmsParams.builder()
    .status(MessageStatus.FAILED)
    .recipient("0812345678")             // any format
    .from(LocalDate.of(2026, 11, 1))     // a LocalDate covers the whole day
    .to(Instant.now())                   // or an OffsetDateTime
    .page(1)
    .perPage(50)                         // default 15, max 100
    .build());
page.data(); page.currentPage(); page.lastPage(); page.total(); page.hasMore();
Optional<Page<Message>> next = page.nextPage();

for (Message m : client.sms().list(ListSmsParams.builder().reference("order-1042").build()).autoPagingIterable()) {
  // every page, fetched lazily (also: autoPagingStream())
}

client.async().sms().list(ListSmsParams.defaults())
    .thenCompose(first -> first.autoPagingForEach(m -> handle(m)));   // or autoPagingToList()
```

Filters: `status`, `recipient`, `reference`, `from`, `to` (inclusive), `page`, `perPage`. The list only holds the
messages sent **with the calling key**; `get(id)` finds any message of the account, by `id` only.

## Balance and Sender IDs

```java
Balance balance = client.balance().get();               // balance.balance(), balance.held() (credits)
List<SenderId> senderIds = client.senderIds().list();

for (SenderId senderId : senderIds) {
  System.out.println(senderId.value() + " " + senderId.status() + " default=" + senderId.isDefault());  // usable as sender
}
```

## OTP: verify a phone number

The code is generated, sent by SMS and checked by Labyrinthe Connect. It is never returned by the API (except
with a test key) and never logged by the SDK.

```java
// 1. Send the code. Keep otpId SERVER-SIDE (session, cache, database), never in the browser.
OtpSendResult otp = client.otp().send("0812345678");
session.setAttribute("otpId", otp.otpId());
otp.expiresAt(); otp.maxAttempts();
otp.codeLength();           // e.g. 6: size of the input field
otp.codeType();             // OtpCodeType.NUMERIC (numeric keypad) or ALPHANUMERIC
otp.resendAfterSeconds();   // e.g. 60: "Resend the code" countdown (also resendAfter(): Duration)

// 2. The user types the code received by SMS.

// 3. Verify it.
OtpVerifyResult result = client.otp().verify((String) session.getAttribute("otpId"), typedCode);
if (result.isVerified()) {
  session.removeAttribute("otpId");                 // log the user in / mark the number as verified
} else if (result.reason().orElseThrow() == OtpFailureReason.INVALID_CODE) {
  // wrong code: one attempt consumed, result.attemptsRemaining() left (OptionalInt)
} else {
  // EXPIRED, ALREADY_USED, MAX_ATTEMPTS_REACHED: ask for a new code
}
```

All the options of a send:

```java
OtpSendResult otp = client.otp().send(SendOtpParams.builder()
    .phone("0812345678")
    .template("Your MyShop code: {code}")   // must contain {code}; optional {app_name}, {minutes}
    .locale(OtpLocale.EN)                   // FR or EN (standard templates)
    .webOtpDomain("app.example.com")        // adds "@app.example.com #123456" for the Web OTP API
    .stripAccents(true)
    .idempotencyKey("login-42-attempt-1")   // optional: a random UUID otherwise
    .build());
```

- A wrong code is **not** an exception: `verify()` returns a result with `isVerified() == false` and a `reason()`.
- A new `send()` to the same number invalidates the previous code. After a successful verification, a new code
  can be sent at once.
- Length, validity, attempts, Sender ID and template default to the account's OTP settings.
- `webOtpDomain` enables autofill with the [Web OTP API](https://developer.mozilla.org/docs/Web/API/WebOTP_API)
  (Chrome, Android).
- Limits: `OtpResendTooSoonException` and `OtpRateLimitedException` (`scope()` = `PHONE` / `ACCOUNT`, `limit()`),
  both with `retryAfter()`. Never retried by the SDK. `otp/send` is also limited to 10 requests per minute per API key.
- **Test mode**: with a `lsk_test_` key no SMS is sent and `otp.testCode()` holds the code, so you can test the
  whole flow. Always empty with a live key; excluded from `toString()`, `equals()` and `hashCode()`.

`otp().send()` always carries an `Idempotency-Key` reused by its retries: a replay returns the same OTP
(`otp.replayed()`), never a second SMS. Every verification outcome is also notified by the `otp.verified` and
`otp.failed` webhooks.

## Webhooks

Configure an endpoint in the client area (*Developers > Webhooks*) and copy its secret. Events are `POST`ed as
JSON (`{ id, event, data, timestamp }`) and signed: `X-Signature: sha256=<HMAC-SHA256(secret, raw body)>`. The SDK
verifies the signature in constant time (`MessageDigest.isEqual`) on the **raw body**, before any JSON parsing,
then checks the signed timestamp (5 minutes by default, replay protection).

> Always verify the **exact bytes** received (`byte[]`, or the body as a `String` decoded in UTF-8). A body
> deserialised into an object and serialised again no longer matches the signature.

### Spring Boot endpoint

With the starter and Spring MVC, `POST /webhooks/labyrinthe` (`labyrinthe.webhook.path`) is registered as soon as
`labyrinthe.webhook.secret` is set. It reads the raw body, verifies it and publishes each event as a Spring
application event: listen to `WebhookEvent` or to a subclass.

```java
@Component
class LabyrintheEvents {
  @EventListener
  void onStatus(MessageStatusChanged event) {
    orders.updateSmsStatus(event.data().messageId(), event.data().status());
  }

  @EventListener
  void onOtpFailed(OtpFailed event) {          // also OtpVerified, BalanceLow, CampaignCompleted, TestPing, UnknownEvent
    audit.otpFailed(event.data().otpId(), event.data().reason());
  }
}
```

- It answers `200 {"received":true}`, or `400 {"error":"<code>"}` for a bad signature, an expired timestamp or an
  invalid body. A listener that throws gives a `500`, so the delivery is retried: deduplicate on `event.id()`.
- Answer within 10 seconds: hand slow work over to `@Async` listeners or a queue.
- `labyrinthe.webhook.enabled=false` disables the endpoint; declaring your own `LabyrintheWebhookController` bean
  replaces it.
- With Spring Security, exclude the endpoint from CSRF and permit it: the signature is the authentication.

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

### Your own controller, or any framework

`Webhooks.constructEvent()` works anywhere you can read the raw body. A Spring controller of your own (with
`labyrinthe.webhook.enabled=false`):

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
      event = Webhooks.constructEvent(body, signature, secret);   // byte[] or String, never re-serialised JSON
    } catch (SignatureVerificationException | InvalidWebhookPayloadException e) {
      return ResponseEntity.badRequest().build();
    }
    if (event instanceof MessageStatusChanged) {
      MessageStatusChanged changed = (MessageStatusChanged) event;
      updateMessage(changed.data().messageId(), changed.data().status());
    }
    // or: switch (event.eventType()) { case MESSAGE_STATUS_CHANGED: … }
    return ResponseEntity.ok().build();
  }
}
```

`Webhooks.constructEvent(body, header, secret, tolerance)` sets another tolerance (`Webhooks.DEFAULT_TOLERANCE` is
5 minutes; `Duration.ZERO` disables the check, not recommended). `Webhooks.verifySignature(body, header, secret)`
only checks the signature; `Webhooks.sign(body, secret)` builds a valid header for your tests. A complete receiver
with the JDK HTTP server: [`WebhookServer.java`](examples/src/main/java/cd/labyrinthe/connect/examples/WebhookServer.java).

### Events

Every event has `id()` (deduplicate on it: deliveries are retried up to 5 times and may arrive out of order),
`type()`, `eventType()` (`WebhookEventType`), `timestamp()` (`Instant`), `raw()` and a typed `data()`:

| Class | `type()` | `data()` |
|---|---|---|
| `MessageStatusChanged` | `message.status_changed` | `messageId`, `recipient`, `status`, `providerMessageId`, `campaignId`, `reference`, `errorCode`, `errorMessage` |
| `OtpVerified` | `otp.verified` | `OtpEventData`: `otpId`, `phone`, `status` (`VERIFIED`), `reason` (empty), `verifiedAt` |
| `OtpFailed` | `otp.failed` | `OtpEventData`: `status` `FAILED` (`reason` = `MAX_ATTEMPTS_REACHED`) or `EXPIRED` (`reason` = `EXPIRED`) |
| `CampaignCompleted` | `campaign.completed` | `campaignId`, `name`, `recipientsCount`, `messagesByStatus`, `creditsCharged`, `completedAt` |
| `BalanceLow` | `balance.low` | `balance`, `threshold` |
| `TestPing` | `test.ping` | `Map` (empty) |
| `UnknownEvent` | a type added after this SDK version | `Map`, as sent; acknowledge it with a 2xx |

The hierarchy is closed (package-private constructors). Answer `2xx` within 10 seconds; queue slow work.

## Errors

Every exception is unchecked and extends `LabyrintheException`, with `code()` (stable, for your code),
`getMessage()` (French, for humans), `statusCode()` (HTTP status, `0` without a response) and `details()`. API errors
extend `ApiException` and also expose `headers()`, `requestId()` (`X-Request-Id`, give it to support) and
`apiVersion()`.

| Exception | HTTP / code | When — useful accessors |
|---|---|---|
| `AuthenticationException` | 401 `UNAUTHENTICATED` | Missing, invalid or revoked key |
| `PermissionException` | 403 `FORBIDDEN`, `OTP_DISABLED_FOR_ACCOUNT` | IP not allowed for this key, OTP disabled for the account |
| `AccountSuspendedException` | 403 `ACCOUNT_SUSPENDED` | Extends `PermissionException`; the whole API is closed to the account |
| `NotFoundException` | 404 `NOT_FOUND` | Unknown message or `otpId`, unknown route |
| `ValidationException` | 422 `VALIDATION_ERROR` | `fieldErrors()` (field → messages) |
| `RecipientNotSendableException` | 422 `RECIPIENT_NOT_SENDABLE` | `rejected()`, `reason()`; nothing was sent |
| `SenderIdNotApprovedException` | 422 `SENDER_ID_NOT_APPROVED` | `sender` is not an approved Sender ID |
| `InvalidPhoneException` | 422 `INVALID_PHONE` | OTP phone number refused by the API |
| `InsufficientCreditsException` | 409 `INSUFFICIENT_CREDITS` | `available()`, `required()` (`OptionalLong`); nothing was sent (never in test mode) |
| `IdempotencyKeyReusedException` | 409 `IDEMPOTENCY_KEY_REUSED` | Same key, different request |
| `IdempotencyRequestInProgressException` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` | `retryAfter()`; already retried by the SDK |
| `IdempotencyKeyUnresolvedException` | 409 `IDEMPOTENCY_KEY_UNRESOLVED` | Outcome unknown, an SMS may have been sent; never retried |
| `RateLimitException` | 429 `TOO_MANY_REQUESTS` | `retryAfter()` |
| `OtpResendTooSoonException`, `OtpRateLimitedException` | 429 | Extend `RateLimitException`; `scope()`, `limit()` (rate limited) |
| `FeatureDisabledException` | 503 `FEATURE_DISABLED`, `OTP_SERVICE_DISABLED` | Extends `ServiceUnavailableException`; maintenance, nothing was done; `feature()`, `blockedBy()`, `adminMessage()`, `until()`, `retryAfter()` |
| `ServiceUnavailableException` | other 5xx | Temporary API failure |
| `ApiException` | any other code | The list is open: new codes may appear |
| `ApiConnectionException`, `ApiTimeoutException` | `CONNECTION_ERROR`, `TIMEOUT` | Network error, attempt timeout (`ApiTimeoutException` extends `ApiConnectionException`) |
| `InvalidArgumentException` | `INVALID_ARGUMENT` | Invalid input detected by the SDK, before any call |
| `UnexpectedResponseException` | — | A response the SDK cannot read |
| `SignatureVerificationException`, `InvalidWebhookPayloadException` | — | Webhook verification (answer 400) |

Catch the most specific types first:

```java
try {
  client.sms().send("0812345678", "Hello");
} catch (InsufficientCreditsException e) {
  alertFinance(e.available().orElse(0), e.required().orElse(0));
} catch (ValidationException e) {
  e.fieldErrors().forEach((field, messages) -> log.warn("{}: {}", field, String.join(" ", messages)));
} catch (ApiException e) {
  log.error("Labyrinthe Connect error {} (HTTP {}, request {})", e.code(), e.statusCode(), e.requestId().orElse("?"));
} catch (ApiConnectionException e) {
  log.error("Labyrinthe Connect unreachable: {}", e.code());   // CONNECTION_ERROR or TIMEOUT
}
```

### Maintenance (503 `FEATURE_DISABLED`)

A platform administrator can temporarily shut down the whole API (`blockedBy()` = `api`, every route, even before
the key is checked) or one function: `api.sms_send` (`sms().send` / `sendMany`), `api.otp_send` (`otp().send`),
`api.otp_verify` (`otp().verify`). The whole OTP service shut down answers the historical `OTP_SERVICE_DISABLED` on
`otp().send()`, with the same details (`feature()` = `blockedBy()` = `otp`). Nothing is done (no SMS, no debit). The
SDK retries only when a re-enable is scheduled within `maxRetryWait` (`Retry-After`), waiting exactly that delay;
otherwise it throws at once. Do not retry in a loop: queue the call and tell the user.

```java
try {
  client.sms().send("0812345678", "Hello");
} catch (FeatureDisabledException e) {
  // e.feature() = Optional[api.sms_send], e.blockedBy() = Optional[api] when the whole API is shut down
  String text = e.adminMessage().orElse("SMS sending is temporarily unavailable.");
  Instant retryAt = e.until().orElseGet(() -> Instant.now().plus(e.retryAfter().orElse(Duration.ofMinutes(15))));
  outbox.retryAt(order, retryAt, text);   // your queue
}
```

Every accessor returns an empty `Optional` when the API does not send the value. Catch `FeatureDisabledException`
before `ServiceUnavailableException`.

## Retries and idempotency

### Automatic retries

The SDK retries automatically (default: 2 retries, exponential backoff 0.5 s → 1 s → 2 s with jitter, capped at
8 s) **only when a replay cannot cause a side effect twice**:

| Request | Network error / timeout | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` | 503 `FEATURE_DISABLED` |
|---|---|---|---|---|---|
| `get`, `list`, `balance().get`, `senderIds().list`, `estimate` | retried | retried | after `Retry-After` | — | only after `Retry-After` |
| `send` / `sendMany` / `otp().send` (always with an `Idempotency-Key`) | retried, same key | retried, same key | after `Retry-After` | after `Retry-After`, same key | only after `Retry-After`, same key |
| `otp().verify` | **never** | **never** | after `Retry-After` (refused before processing) | — | only after `Retry-After` (refused before processing) |

Business errors (other 4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, 409 `IDEMPOTENCY_KEY_UNRESOLVED`) are never
retried. When the API asks to wait longer than `maxRetryWait`, the SDK throws (`RateLimitException`,
`FeatureDisabledException`…) instead of waiting. Redirects are never followed.

### Idempotency (no double sends)

Every SMS and OTP send carries an `Idempotency-Key`. When you do not give one, the SDK generates a random UUID and
reuses it for its own retries, so a network error never produces a second SMS. To also protect against **your**
retries (job queues, double clicks, crashes), pass a key derived from your business event. A replay within 24 hours
returns the original result without sending or billing again:

```java
MessageBatch batch = client.sms().sendMany(SendManySmsParams.builder()
    .addRecipient("0812345678")
    .body("Your order 1042 is ready.")
    .idempotencyKey("order-1042-ready")   // max 255 characters
    .build());
batch.replayed();   // true when this was a replay
```

- The same key with a different content: `IdempotencyKeyReusedException` (409). Recipients are compared after
  normalisation (`0812345678` then `243812345678` is a replay). `/sms` and `/otp/send` have separate key spaces.
- Original request still running: the API answers 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`; the SDK waits
  `Retry-After` and retries with the same key, then throws `IdempotencyRequestInProgressException` (`retryAfter()`).
- `IdempotencyKeyUnresolvedException` (409 `IDEMPOTENCY_KEY_UNRESOLVED`): the original request failed unexpectedly
  on the API side and its outcome is unknown: an SMS **may** have been sent. Neither the SDK nor you should retry it
  automatically (the same key keeps answering this error; a new key could send twice). Check first (`sms().list()`
  with the `reference` you sent, or ask the user whether the code arrived), then, only if nothing went out, send
  again with a **new** key.

```java
try {
  client.sms().send(params);
} catch (IdempotencyKeyUnresolvedException e) {
  Page<Message> sent = client.sms().list(ListSmsParams.builder().reference("order-1042").build());
  log.warn("Outcome unknown (request {}), {} message(s) found", e.requestId().orElse("?"), sent.total());
}
```

## Test mode, logging and security

### Test mode

A `lsk_test_…` key runs against a simulated provider: **nothing is sent, no credit is used**, responses have the
production shape. Two differences: the balance is not checked (no `InsufficientCreditsException` in test mode) and
`otp().send()` returns the code in `testCode()`. Use test keys in development, CI and the examples; check
`client.isTestMode()` at startup if you want to refuse a live key outside production.

### Logging

The SDK logs through `System.Logger`, named `cd.labyrinthe.connect` (routed to `java.util.logging` by default, or to
SLF4J / Log4j with their `System.Logger` bridge, e.g. `org.slf4j:slf4j-jdk-platform-logging`). Requests and
responses are logged at `DEBUG`, automatic retries at `WARNING` — never the key, a code, a body or a full phone
number (`24381*****78`). Errors are thrown, not logged. With Spring Boot and the SLF4J bridge:
`logging.level.cd.labyrinthe.connect=DEBUG`.

### Security

- `client.toString()` shows `lsk_test_****`; models mask phone numbers in `toString()`; `OtpSendResult` excludes
  `testCode()` from `toString()`; `LabyrintheProperties.toString()` hides the key and the webhook secret; exceptions
  are serializable without their `details()`.
- Keys stay server-side; inject them from environment variables or a vault, never from a committed file.
- Webhooks: constant-time signature check on the raw body, timestamp tolerance, deduplicate on `id()`.

### Request ids, per-call timeouts and response headers

Every response carries `X-Request-Id`: `ApiException.requestId()` and `MessageBatch.requestId()` expose it. Every
method also accepts a `RequestOptions`: a timeout for one call, your own `X-Request-Id` (sent on every retry, echoed
by the API) and a callback reading the response headers (`ResponseInfo`):

```java
client.sms().send(params, RequestOptions.builder()
    .timeout(Duration.ofSeconds(5))                       // per attempt, for this call only
    .requestId("order-1042")                              // 1-128 chars among A-Z a-z 0-9 . _ : -
    .onResponse(info -> log.info("request {} (API v{}), {} requests left, replayed={}",
        info.requestId().orElse("?"), info.apiVersion().orElse("?"),
        info.rateLimitRemaining().orElse(-1), info.replayed()))
    .build());

client.balance().get(RequestOptions.requestId("health-check-42"));   // shortcuts: RequestOptions.timeout(…), none()
```

### Timeouts

`timeout` (builder, or `RequestOptions` per call) bounds **each attempt** (the builder value is also the connect
timeout); an attempt that exceeds it raises `ApiTimeoutException` (and is retried when that is safe). The total time of a call is at most
`(maxRetries + 1)` attempts plus the waits between them. With the async API, `future.orTimeout(…)` bounds your wait,
but does not stop a send already on its way: send again with the **same** `idempotencyKey` to learn its outcome
without sending twice.

## Without the package (raw HTTP)

The API is plain JSON over HTTPS; the SDK is a convenience. With `java.net.http.HttpClient` only:

```java
HttpClient http = HttpClient.newHttpClient();
HttpRequest request = HttpRequest.newBuilder(URI.create("https://api.connect.labyrinthe-rdc.com/api/public/v1/sms"))
    .timeout(Duration.ofSeconds(30))
    .header("Authorization", "Bearer " + System.getenv("LABYRINTHE_API_KEY"))
    .header("Content-Type", "application/json")
    .header("Accept", "application/json")
    .header("Idempotency-Key", UUID.randomUUID().toString())   // keep it to retry safely
    .POST(HttpRequest.BodyPublishers.ofString(
        "{\"recipient\":\"+243812345678\",\"body\":\"Your order 1042 is ready.\",\"reference\":\"order-1042\"}"))
    .build();

HttpResponse<String> response = http.send(request, HttpResponse.BodyHandlers.ofString());
// 202: {"data":[{"id":"…","recipient":"243812345678","status":"queued",…}]}
// error: {"error":{"code":"…","message":"…","details":{}}}, plus X-Request-Id
```

Webhook signature without the package: `Mac mac = Mac.getInstance("HmacSHA256"); mac.init(new
SecretKeySpec(secret.getBytes(UTF_8), "HmacSHA256"));` then compare `"sha256=" + hex(mac.doFinal(rawBody))` to
`X-Signature` with `MessageDigest.isEqual`, and check that the `timestamp` of the envelope (Unix seconds) is recent.
You then handle retries, idempotency, error mapping and number formats yourself.

Reference: the OpenAPI specification [`openapi/openapi.yaml`](../openapi/openapi.yaml) (1.3.1), also served by the
API at `/api/public/openapi.yaml`.

### Known API limits

- Webhook endpoints are managed in the client area only (no API).
- `INSUFFICIENT_CREDITS` cannot be reproduced with a test key.
- `estimate()` counts blacklisted or opted-out numbers that `sendMany()` would refuse.
- `list()` returns the messages of the calling key; `get()` finds any message of the account.

## Upgrading

The SDK follows SemVer. See the [CHANGELOG](CHANGELOG.md).

- **1.2.0 → 1.2.1** (API 1.3.1): no code change. The default API URL is now `https://api.connect.labyrinthe-rdc.com/api/public/v1` (the 1.2.0 default
  did not answer). Upgrade, or set this URL explicitly if you stay on 1.2.0.
- **1.1.x → 1.2.0** (API 1.3.0): new `FeatureDisabledException` (503 `FEATURE_DISABLED`). `OTP_SERVICE_DISABLED` is
  now a `FeatureDisabledException` too (still a `ServiceUnavailableException`, so existing `catch` blocks keep
  working). **Behaviour change**: `OTP_SERVICE_DISABLED` is no longer retried with the 5xx backoff; both codes are
  retried only after a `Retry-After` within `maxRetryWait`.
- **1.1.0 → 1.1.1**: numbers are sent in E.164 with `+`; new `IdempotencyKeyUnresolvedException`.
- **1.0.x → 1.1.0** (API 1.2.0): `RequestOptions` / `ResponseInfo`, idempotent and retried `otp().send()`,
  `stripAccents`, `webOtpDomain`, `otp.verified` / `otp.failed` events. Without `+`, 11 digits or more not starting
  with 0 are now read as international.

## Contributing

Issues and pull requests go to the [monorepo](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks); read
[CONTRIBUTING.md](../CONTRIBUTING.md) first. From `java/`:

```bash
./mvnw verify                 # unit + OpenAPI contract tests, -Xlint:all -Werror, Spotless, SpotBugs, Javadoc
./mvnw spotless:apply         # format (google-java-format, JDK 21+)
./mvnw -q -pl examples -am verify -DskipTests   # build the examples only

# integration tests against a local backend, with a TEST key only
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 ./mvnw verify -Pintegration
```

Modules: `core/` (`labyrinthe-connect`, Java 11), `spring-boot-starter/` (Java 17), `examples/` (compiled on every
build, never published): [`SendSms`](examples/src/main/java/cd/labyrinthe/connect/examples/SendSms.java),
[`OtpLogin`](examples/src/main/java/cd/labyrinthe/connect/examples/OtpLogin.java),
[`WebhookServer`](examples/src/main/java/cd/labyrinthe/connect/examples/WebhookServer.java) and
[`SpringBootExample`](examples/src/spring/java/cd/labyrinthe/connect/examples/spring/SpringBootExample.java). On a
JDK 11, only `core` and the plain Java examples are built. Security issues: connect@labyrinthe-rdc.com rather than a
public issue.

## License

MIT — see [LICENSE](LICENSE).

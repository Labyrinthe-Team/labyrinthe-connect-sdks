# Labyrinthe Connect — Java SDK

[![CI](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/java.yml/badge.svg)](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/java.yml)
[![Maven Central](https://img.shields.io/maven-central/v/cd.labyrinthe/labyrinthe-connect.svg)](https://central.sonatype.com/artifact/cd.labyrinthe/labyrinthe-connect)
![Java](https://img.shields.io/badge/java-11%20%E2%86%92%2021-007396)

Official Java client for [Labyrinthe Connect](https://labyrinthe-rdc.com): send SMS and verify phone numbers
with one-time codes (OTP), in the DR Congo and Central Africa.

*Version française : [README.fr.md](README.fr.md).*

- Java 11+, built on `java.net.http.HttpClient`. **No runtime dependency** (not even a JSON library).
- Synchronous **and** asynchronous (`CompletableFuture`) API, thread-safe client, immutable models,
  `Optional` for nullable fields, `Instant` for dates, enums with an `UNKNOWN` value for future API values.
- Safe by default: automatic retries **only** when they cannot send an SMS twice.
- Never logs or prints your API key, OTP codes or full phone numbers.
- Optional Spring Boot 3 starter: client bean from `labyrinthe.*` properties and a signed webhook endpoint
  publishing Spring events.

## Installation

Maven:

```xml
<dependency>
  <groupId>cd.labyrinthe</groupId>
  <artifactId>labyrinthe-connect</artifactId>
  <version>1.1.1</version>
</dependency>
```

Gradle:

```kotlin
implementation("cd.labyrinthe:labyrinthe-connect:1.1.1")
// Spring Boot 3 (Java 17+):
implementation("cd.labyrinthe:labyrinthe-connect-spring-boot-starter:1.1.1")
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

`client.isTestMode()` tells you which one you are using. Every example below works with a test key.

The client is thread-safe and holds an `HttpClient` connection pool: create **one** per application and share it.

### Synchronous and asynchronous

```java
Balance balance = client.balance().get();                          // blocking

client.async().sms().send("0812345678", "Hello")                   // CompletableFuture<Message>
    .thenAccept(message -> System.out.println(message.id()));
```

`client.async()` exposes the same services with the same rules (retries, idempotency, errors). Futures fail with a
`LabyrintheException` (wrapped in a `CompletionException` by `join()`, in an `ExecutionException` by `get()`);
invalid arguments also give a failed future.

## Sending SMS

```java
Message message = client.sms().send(SendSmsParams.builder()
    .to("+243 81 234 5678")        // +243…, 00243…, 243…, 0812…, 812… are all accepted
    .body("Your order 1042 is ready.")
    .sender("MYSHOP")              // optional: an approved Sender ID (client.senderIds().list())
    .reference("order-1042")       // optional: your own reference, max 64 characters
    .build());

message.id();               // "9d5c0a3e-…"
message.recipient();        // "243812345678"
message.status();           // MessageStatus.QUEUED
message.segments();         // 1
message.creditsCharged();   // 1
message.createdAt();        // Instant
message.reference();        // Optional[order-1042]
```

Sending is asynchronous: `202 Accepted` means *queued and paid for*, not *delivered*. Follow the status with
webhooks (`message.status_changed`, recommended) or by polling:

```java
Message latest = client.sms().get(message.id());
if (latest.status().isFinal()) {
  System.out.println(latest.status() == MessageStatus.DELIVERED
      ? "Delivered" : "Failed: " + latest.errorCode().orElse("?"));
}
```

### Cost before sending

1 credit = 1 SMS segment. A pure ASCII message (GSM-7) holds 160 characters (153 per segment beyond). **Any
non-ASCII character** (é, à, ç, emoji, curly quotes…) switches to UCS-2: 70 characters (67 per segment beyond).

```java
Estimate estimate = client.sms().estimate(EstimateSmsParams.builder()
    .body("Réunion demain à 9h")
    .recipients(List.of("0812345678", "0899999999"))
    .build());
estimate.encoding();       // Encoding.UCS2
estimate.totalCredits();   // 2
estimate.isAffordable();   // does the balance cover it?
```

`stripAccents(true)` (on `SendSmsParams`, `SendManySmsParams`, `EstimateSmsParams` and `SendOtpParams`) asks the
API to replace accents and typographic characters by ASCII: the message becomes GSM-7, often half the price. The
transliteration depends on the server (`Café` may become `Cafe` or `Caf'e`): do not use it for an exact text.

### Several recipients: all or nothing

```java
try {
  MessageBatch batch = client.sms().sendMany(SendManySmsParams.builder()
      .recipients(List.of("0812345678", "0899999999"))
      .body("Meeting tomorrow at 9am.")
      .build());
  batch.messages();          // one message per distinct number (duplicates are merged)
} catch (RecipientNotSendableException e) {
  // NOTHING was sent: fix the list and try again.
  for (RejectedRecipient rejected : e.rejected()) {
    System.out.println(rejected.recipient() + ": " + rejected.reason());   // INVALID_PHONE, BLACKLISTED, OPTED_OUT
  }
}
```

Up to 1000 recipients per call. Every recipient, the Sender ID and the balance are checked **before** anything is
sent: one refused number (or an insufficient balance) means no SMS at all. Numbers the SDK can already tell are
invalid are refused locally (`InvalidArgumentException`, with their index only), before any network call.

### Idempotency (no double sends)

Every send carries an `Idempotency-Key`. When you do not give one, the SDK generates a random UUID and reuses it
for its own retries, so a network error never produces a second SMS. To also protect against **your** retries
(job queues, double clicks, crashes), pass a key derived from your business event. A replay within 24 hours
returns the original messages without sending or billing again:

```java
MessageBatch batch = client.sms().sendMany(SendManySmsParams.builder()
    .addRecipient("0812345678")
    .body("Your order 1042 is ready.")
    .idempotencyKey("order-1042-ready")
    .build());
batch.replayed();   // true when this was a replay
```

The same key with a different content is refused with `IdempotencyKeyReusedException` (409). Recipients are
compared after normalisation (`0812345678` then `243812345678` with the same key is a replay). While the original
request is still running, the API answers 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`: the SDK waits `Retry-After` and
retries with the same key, then throws `IdempotencyRequestInProgressException` (`retryAfter()`).

**`IdempotencyKeyUnresolvedException` (409 `IDEMPOTENCY_KEY_UNRESOLVED`, `sms().send()`, `sendMany()` and
`otp().send()`).** The original request with this key failed unexpectedly on the API side and its outcome is
unknown: an SMS **may** have been sent. The SDK never retries it, and you must not retry it automatically either
(not with the same key: it will keep answering this error; not with a new key: you could send twice). What to do:

1. check whether the message left: `client.sms().list(ListSmsParams.builder().reference("order-1042").build())`
   when you sent a `reference`, or ask the user whether the code arrived (OTP);
2. only if nothing was sent, send again with a **new** `Idempotency-Key`.

```java
try {
  client.sms().send(params);
} catch (IdempotencyKeyUnresolvedException e) {
  log.warn("Outcome unknown (request {}): check before sending again", e.requestId().orElse("?"));
}
```

### Listing messages

```java
Page<Message> page = client.sms().list(ListSmsParams.builder()
    .status(MessageStatus.FAILED)
    .from(LocalDate.of(2026, 11, 1))     // a LocalDate covers the whole day
    .to(Instant.now())
    .perPage(50)
    .build());
page.total(); page.data(); page.hasMore(); page.nextPage();

for (Message m : client.sms().list(ListSmsParams.builder().reference("order-1042").build()).autoPagingIterable()) {
  // every page, fetched lazily (also: autoPagingStream())
}

client.async().sms().list(ListSmsParams.defaults())
    .thenCompose(first -> first.autoPagingForEach(m -> handle(m)));
```

Filters: `status`, `recipient` (any format), `reference`, `from`, `to` (`LocalDate`, `Instant` or
`OffsetDateTime`), `page`, `perPage` (max 100). Only messages sent **with the calling key**.

### Balance and Sender IDs

```java
Balance balance = client.balance().get();           // balance.balance(), balance.held()
List<SenderId> senderIds = client.senderIds().list();  // approved names, usable as sender
```

## OTP: verify a phone number

The code is generated, sent by SMS and checked by Labyrinthe Connect. It is never returned by the API (except
with a test key) and never logged by the SDK.

```java
// 1. Send the code. Keep otpId SERVER-SIDE (session, cache, database), never in the browser.
OtpSendResult otp = client.otp().send("0812345678");
session.setAttribute("otpId", otp.otpId());
otp.codeLength();           // e.g. 6: size of the input field
otp.codeType();             // OtpCodeType.NUMERIC (numeric keypad) or ALPHANUMERIC
otp.resendAfterSeconds();   // e.g. 60: "Resend the code" countdown

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

- A wrong code is **not** an exception: `verify()` returns a result with `isVerified() == false` and a `reason()`.
- A new `send()` to the same number invalidates the previous code.
- Length, validity, attempts, Sender ID and template come from the account's OTP settings. You can override the
  template (`SendOtpParams.builder().template("Your MyShop code: {code}")`, must contain `{code}`; optional
  `{app_name}`, `{minutes}`), the language (`.locale(OtpLocale.EN)`), add the
  [Web OTP API](https://developer.mozilla.org/docs/Web/API/WebOTP_API) line for autofill
  (`.webOtpDomain("app.example.com")`) and `.stripAccents(true)`.
- Limits: `OtpResendTooSoonException` and `OtpRateLimitedException` (`scope()`, `limit()`), both with
  `retryAfter()`. After a successful verification, a new code can be sent at once.
- **Test mode**: with a `lsk_test_` key no SMS is sent and `otp.testCode()` holds the code, so you can test the
  whole flow. Always empty with a live key; excluded from `toString()`, `equals()` and `hashCode()`.

`otp().send()` always carries an `Idempotency-Key` (a UUID, or yours with `.idempotencyKey("login-42")`), so it is
retried safely after a network error, a timeout, a 5xx, 429 `TOO_MANY_REQUESTS` or 409
`IDEMPOTENCY_REQUEST_IN_PROGRESS`: a retry returns the same OTP (`otp.replayed()`), never a second SMS.
`OTP_RESEND_TOO_SOON` and `OTP_RATE_LIMITED` are never retried. `otp().verify()` is retried only on 429
`TOO_MANY_REQUESTS` (an attempt could otherwise be consumed twice).

## Webhooks

Configure an endpoint in the client area (*Developers > Webhooks*) and copy its secret. Events are `POST`ed as
JSON and signed (`X-Signature: sha256=<HMAC-SHA256 of the raw body>`). The SDK verifies the signature in constant
time (`MessageDigest.isEqual`) on the **raw body**, then checks the signed timestamp (5 minutes by default).

```java
WebhookEvent event;
try {
  event = Webhooks.constructEvent(rawBody, request.getHeader("X-Signature"), secret);  // byte[] or String, never re-serialised JSON
} catch (SignatureVerificationException | InvalidWebhookPayloadException e) {
  // answer 400
}
if (event instanceof MessageStatusChanged) {
  MessageStatusChanged changed = (MessageStatusChanged) event;
  updateMessage(changed.data().messageId(), changed.data().status());
}
// or: switch (event.eventType()) { case MESSAGE_STATUS_CHANGED: … }
```

Every event has `id()` (deduplicate on it: deliveries are retried up to 5 times and may arrive out of order),
`type()`, `eventType()`, `timestamp()` (`Instant`), `raw()` and a typed `data()`:

| Class | `type()` | `data()` |
|---|---|---|
| `MessageStatusChanged` | `message.status_changed` | `messageId`, `recipient`, `status`, `providerMessageId`, `campaignId`, `reference`, `errorCode`, `errorMessage` |
| `CampaignCompleted` | `campaign.completed` | `campaignId`, `name`, `recipientsCount`, `messagesByStatus`, `creditsCharged`, `completedAt` |
| `BalanceLow` | `balance.low` | `balance`, `threshold` |
| `OtpVerified` | `otp.verified` | `OtpEventData`: `otpId`, `phone`, `status` (`VERIFIED`), `reason` (empty), `verifiedAt` |
| `OtpFailed` | `otp.failed` | `OtpEventData`: `status` `FAILED` (`MAX_ATTEMPTS_REACHED`) or `EXPIRED` (`EXPIRED`) |
| `TestPing` | `test.ping` | `Map` (empty) |
| `UnknownEvent` | a type added after this SDK version | `Map`, as sent; acknowledge it with a 2xx |

The hierarchy is closed (package-private constructor). `Webhooks.constructEvent(body, header, secret, tolerance)`
sets another tolerance (`Duration.ZERO` disables the check, not recommended). Answer `2xx` within 10 seconds;
queue slow work. `Webhooks.sign(body, secret)` builds a valid header for your tests. A complete receiver with the
JDK HTTP server: [`WebhookServer.java`](examples/src/main/java/cd/labyrinthe/connect/examples/WebhookServer.java).

## Spring Boot

```xml
<dependency>
  <groupId>cd.labyrinthe</groupId>
  <artifactId>labyrinthe-connect-spring-boot-starter</artifactId>
  <version>1.1.1</version>
</dependency>
```

```properties
labyrinthe.api-key=${LABYRINTHE_API_KEY}
labyrinthe.webhook.secret=${LABYRINTHE_WEBHOOK_SECRET}
# optional: labyrinthe.base-url, timeout=30s, max-retries=2, max-retry-wait=10s, default-country-code=243,
#           default-sender, webhook.path=/webhooks/labyrinthe, webhook.tolerance=5m, webhook.enabled=true
```

```java
@Service
class Orders {
  private final LabyrintheClient labyrinthe;               // auto-configured bean
  Orders(LabyrintheClient labyrinthe) { this.labyrinthe = labyrinthe; }

  @EventListener                                            // verified webhook events are Spring events
  void onStatus(MessageStatusChanged event) { … }
}
```

- The `LabyrintheClient` bean is created when `labyrinthe.api-key` (or `LABYRINTHE_API_KEY`) is set and you did
  not declare your own. `LabyrintheClientBuilderCustomizer` beans can adjust the builder (proxy, `HttpClient`…).
- In Spring MVC applications, `POST /webhooks/labyrinthe` is registered when `labyrinthe.webhook.secret` is set.
  It answers `200 {"received":true}`, or `400` for a bad signature, an expired timestamp or an invalid body.
  Events are published with `ApplicationEventPublisher.publishEvent(event)`: listen to `WebhookEvent` or to a
  subclass. A listener that throws gives a `500`, so the delivery is retried.
- With Spring Security, exclude the endpoint from CSRF (`csrf.ignoringRequestMatchers("/webhooks/labyrinthe")`)
  and permit it: the signature is the authentication.
- Requires Spring Boot 3 and Java 17+. Example: [`SpringBootExample.java`](examples/src/spring/java/cd/labyrinthe/connect/examples/spring/SpringBootExample.java).

## Errors

Every exception is unchecked and extends `LabyrintheException`, with `code()` (stable, for your code),
`getMessage()`, `statusCode()` (HTTP status, `0` without a response) and `details()`. API errors extend
`ApiException` and also expose `headers()`, `requestId()` (`X-Request-Id`, for the support) and `apiVersion()`.

| Exception | When |
|---|---|
| `AuthenticationException` | 401 — missing, invalid or revoked key |
| `PermissionException` | 403 — IP not allowed (`FORBIDDEN`), OTP disabled for the account (`OTP_DISABLED_FOR_ACCOUNT`) |
| `AccountSuspendedException` | 403 `ACCOUNT_SUSPENDED` — extends `PermissionException`; the whole API is closed |
| `NotFoundException` | 404 — unknown message or `otpId` |
| `ValidationException` | 422 `VALIDATION_ERROR` — `fieldErrors()` |
| `RecipientNotSendableException` | 422 — `rejected()`, `reason()`; nothing was sent |
| `SenderIdNotApprovedException` | 422 — `sender` is not an approved Sender ID |
| `InvalidPhoneException` | 422 `INVALID_PHONE` (OTP) |
| `InsufficientCreditsException` | 409 — `available()`, `required()`; nothing was sent |
| `IdempotencyKeyReusedException` | 409 — same key, different request |
| `IdempotencyRequestInProgressException` | 409 — original request still running; `retryAfter()`, retry with the same key |
| `IdempotencyKeyUnresolvedException` | 409 `IDEMPOTENCY_KEY_UNRESOLVED` — the original request failed, outcome unknown (an SMS may have left); never retried: check, then send with a **new** key |
| `RateLimitException` | 429 `TOO_MANY_REQUESTS` — `retryAfter()` |
| `OtpResendTooSoonException`, `OtpRateLimitedException` | 429 — extend `RateLimitException` (`retryAfter()`); `scope()`, `limit()` |
| `ServiceUnavailableException` | 5xx, including 503 `OTP_SERVICE_DISABLED` |
| `ApiException` | any other code (the list is open: new codes may appear) |
| `ApiConnectionException`, `ApiTimeoutException` | network error (`CONNECTION_ERROR`) or timeout (`TIMEOUT`) |
| `InvalidArgumentException` | invalid input detected by the SDK, before any call (`INVALID_ARGUMENT`) |
| `UnexpectedResponseException` | a response the SDK cannot read |
| `SignatureVerificationException`, `InvalidWebhookPayloadException` | webhook verification (answer 400) |

```java
try {
  client.sms().send("0812345678", "Hello");
} catch (InsufficientCreditsException e) {
  alertFinance(e.available().orElse(0), e.required().orElse(0));
} catch (ApiException e) {
  log.error("Labyrinthe Connect error {} (HTTP {})", e.code(), e.statusCode());
}
```

## Retries and timeouts

The SDK retries automatically (default: 2 retries, exponential backoff 0.5 s → 1 s → 2 s with jitter) **only when a
replay cannot cause a side effect twice**:

| Request | Network error / timeout | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` |
|---|---|---|---|---|
| `get`, `list`, `balance().get`, `senderIds().list`, `estimate` | retried | retried | retried after `Retry-After` | — |
| `send` / `sendMany` / `otp().send` (always with an `Idempotency-Key`) | retried, same key | retried, same key | retried after `Retry-After` | retried after `Retry-After`, same key |
| `otp().verify` | **never** | **never** | retried (refused before processing) | — |

Business errors (4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, 409 `IDEMPOTENCY_KEY_UNRESOLVED`) are never
retried. When the API asks to wait longer
than `maxRetryWait` (default 10 s), the SDK throws `RateLimitException` instead of waiting. Redirects are never
followed (a custom `HttpClient` that follows redirects is refused).

Every method accepts a `RequestOptions`: a timeout for one call, your own `X-Request-Id` (sent on every retry,
echoed by the API in its logs and responses) and a callback reading the response headers:

```java
client.sms().send(params, RequestOptions.builder()
    .timeout(Duration.ofSeconds(5))
    .requestId("order-1042")                              // 1-128 chars among A-Z a-z 0-9 . _ : -
    .onResponse(info -> log.info("request {} (API v{}), {} requests left",
        info.requestId().orElse("?"), info.apiVersion().orElse("?"), info.rateLimitRemaining()))
    .build());
```

## Configuration

```java
LabyrintheClient client = LabyrintheClient.builder()
    .apiKey(System.getenv("LABYRINTHE_API_KEY"))   // default: LABYRINTHE_API_KEY
    .baseUrl("https://api.labyrinthe-rdc.com/api/public/v1")  // default: LABYRINTHE_BASE_URL, then production; a bare host is completed
    .timeout(Duration.ofSeconds(30))               // per attempt, also the connect timeout
    .maxRetries(2)                                  // 0 disables retries (max 10)
    .maxRetryWait(Duration.ofSeconds(10))          // longest Retry-After the SDK waits for
    .defaultCountryCode("243")                     // added to local numbers: 0812345678 → 243812345678
    .defaultSender("MYSHOP")                       // used when a send has none
    .httpClient(HttpClient.newBuilder().proxy(ProxySelector.of(proxy)).build())  // your own client (must not follow redirects)
    .build();
```

- **Logging**: `System.Logger` named `cd.labyrinthe.connect` (routed to `java.util.logging` by default, or to
  SLF4J/Log4j with their `System.Logger` bridge, e.g. `org.slf4j:slf4j-jdk-platform-logging`). Requests and
  responses are logged at `DEBUG`, automatic retries at `WARNING` — never the key, a code, a body or a full phone
  number (`24381*****78`). Errors are thrown, not logged.
- `toString()` shows `lsk_test_****`; models mask phone numbers in `toString()`; exceptions are serializable
  without their `details()`.
- `PhoneNumbers.normalize("0812345678")` returns `Optional[243812345678]` with the same rules as the API:
  without "+", 11 digits or more not starting with 0 are already international (`33612345678` stays
  `33612345678`); use the "+" for a short foreign number. `isValid()` and `mask()` are also available.
  International numbers are never blocked. Requests always carry the number in E.164 form with the "+"
  (`+243812345678`), so a short foreign number such as `+2481234567` keeps its country code.
- `User-Agent: labyrinthe-java/<version> Java/<version>`; `Version.VERSION` holds the SDK version.

## Development

```bash
./mvnw verify                 # unit + OpenAPI contract tests, -Xlint:all -Werror, Spotless, SpotBugs, Javadoc
./mvnw spotless:apply         # format (google-java-format, JDK 17+)

# integration tests against a local backend, with a TEST key only
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 ./mvnw verify -Pintegration
```

Modules: `core/` (`labyrinthe-connect`, Java 11), `spring-boot-starter/` (Java 17), `examples/` (compiled on every
build, never published). On a JDK 11, only `core` and the plain Java examples are built. The API contract lives in
[`openapi/openapi.yaml`](../openapi/openapi.yaml); the contract tests keep the SDK aligned with it.

## License

MIT — see [LICENSE](LICENSE).

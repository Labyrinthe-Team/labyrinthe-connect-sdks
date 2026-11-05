package cd.labyrinthe.connect;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.junit.jupiter.api.Assumptions.assumeTrue;

import cd.labyrinthe.connect.exception.AuthenticationException;
import cd.labyrinthe.connect.exception.LabyrintheException;
import cd.labyrinthe.connect.internal.ErrorMapper;
import cd.labyrinthe.connect.internal.Json;
import cd.labyrinthe.connect.webhook.WebhookEvent;
import cd.labyrinthe.connect.webhook.WebhookEventType;
import cd.labyrinthe.connect.webhook.Webhooks;
import java.io.IOException;
import java.lang.reflect.Method;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.time.Clock;
import java.time.Instant;
import java.time.ZoneOffset;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collections;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.TreeSet;
import java.util.stream.Collectors;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.function.Executable;
import org.yaml.snakeyaml.LoaderOptions;
import org.yaml.snakeyaml.Yaml;
import org.yaml.snakeyaml.constructor.SafeConstructor;

/**
 * Garde le SDK aligné sur openapi/openapi.yaml (source de vérité) : opérations, énumérations, codes
 * d'erreur, limites, validation des réponses simulées et des requêtes du SDK contre les schémas,
 * exemples de la spec lus par le SDK. Ignoré hors du monorepo.
 */
class ContractTest {

  private static Map<String, Object> spec;
  private static SchemaValidator validator;

  @BeforeAll
  @SuppressWarnings("unchecked")
  static void loadSpec() throws IOException {
    String configured = System.getProperty("labyrinthe.specPath", "../../openapi/openapi.yaml");
    Path path = Paths.get(configured);
    if (!Files.exists(path)) {
      path = Paths.get("../openapi/openapi.yaml");
    }
    assumeTrue(Files.exists(path), "openapi/openapi.yaml not found (outside the monorepo)");
    Yaml yaml = new Yaml(new SafeConstructor(new LoaderOptions()));
    spec = (Map<String, Object>) yaml.load(Files.readString(path, StandardCharsets.UTF_8));
    validator = new SchemaValidator(spec);
  }

  @SuppressWarnings("unchecked")
  private static <T> T at(Object... path) {
    Object node = spec;
    for (Object segment : path) {
      node =
          segment instanceof Integer
              ? ((List<Object>) node).get((Integer) segment)
              : ((Map<String, Object>) node).get(segment);
    }
    return (T) node;
  }

  private static Object responseSchema(String path, String method, String status) {
    return at("paths", path, method, "responses", status, "content", "application/json", "schema");
  }

  private static Object requestSchema(String path) {
    return at("paths", path, "post", "requestBody", "content", "application/json", "schema");
  }

  private static void assertValid(Object schema, Object value, String label) {
    List<String> problems = validator.validate(schema, value);
    assertTrue(problems.isEmpty(), label + ": " + problems);
  }

  private static List<String> known(Enum<?>[] values) {
    return Arrays.stream(values)
        .filter(v -> !"UNKNOWN".equals(v.name()))
        .map(Object::toString)
        .collect(Collectors.toList());
  }

  @Test
  void targetsSpec110() {
    assertEquals("1.1.0", at("info", "version"));
  }

  @Test
  void implementsEveryOperationAndOnlyThose() {
    Map<String, Map<String, Object>> paths = at("paths");
    Set<String> operations = new TreeSet<>();
    paths.forEach(
        (path, methods) ->
            methods.keySet().stream()
                .filter(m -> Set.of("get", "post", "put", "patch", "delete").contains(m))
                .forEach(m -> operations.add(m + " " + path)));
    assertEquals(
        new TreeSet<>(
            List.of(
                "get /balance",
                "get /sender-ids",
                "get /sms",
                "get /sms/{id}",
                "post /otp/send",
                "post /otp/verify",
                "post /sms",
                "post /sms/estimate")),
        operations);
  }

  @Test
  void defaultsToTheProductionServer() {
    assertEquals(LabyrintheClient.DEFAULT_BASE_URL, at("servers", 0, "url"));
  }

  @Test
  void hasTheEnumsOfTheSpec() {
    assertEquals(
        known(MessageStatus.values()), at("components", "schemas", "MessageStatus", "enum"));
    assertEquals(
        known(SenderIdStatus.values()), at("components", "schemas", "SenderIdStatus", "enum"));
    assertEquals(
        known(RejectionReason.values()), at("components", "schemas", "RejectionReason", "enum"));
    assertEquals(
        known(Encoding.values()),
        at("components", "schemas", "Message", "properties", "encoding", "enum"));
    assertEquals(
        known(Encoding.values()),
        at("components", "schemas", "Estimate", "properties", "encoding", "enum"));
    assertEquals(
        known(OtpFailureReason.values()),
        at("components", "schemas", "OtpVerifyResult", "properties", "reason", "oneOf", 1, "enum"));
    assertEquals(
        known(OtpRateLimitScope.values()),
        at(
            "components",
            "schemas",
            "OtpRateLimitedError",
            "allOf",
            1,
            "properties",
            "error",
            "properties",
            "details",
            "properties",
            "scope",
            "enum"));
    assertEquals(
        known(WebhookEventType.values()),
        at("components", "schemas", "WebhookEnvelope", "properties", "event", "enum"));
    assertEquals(
        known(WebhookEventType.values()), new ArrayList<>(((Map<?, ?>) at("webhooks")).keySet()));
    assertEquals(
        known(OtpLocale.values()),
        at("components", "schemas", "SendOtpRequest", "properties", "locale", "enum"));
  }

  @Test
  void handlesEveryDocumentedErrorCode() {
    // Remontés volontairement en ApiException générique.
    Set<String> generic = Set.of("HTTP_ERROR", "INVALID_OTP_TEMPLATE");
    List<String> codes = at("components", "schemas", "ErrorCode", "examples");
    for (String code : codes) {
      assertTrue(
          ErrorMapper.CLASSES_BY_CODE.containsKey(code) || generic.contains(code),
          code + " is not handled");
    }
  }

  @Test
  void enforcesTheLimitsOfTheSpec() {
    Map<String, Map<String, Object>> send =
        at("components", "schemas", "SendSmsRequest", "properties");
    assertEquals(Limits.MAX_BODY_LENGTH, send.get("body").get("maxLength"));
    assertEquals(Limits.MAX_SENDER_LENGTH, send.get("sender").get("maxLength"));
    assertEquals(Limits.MAX_REFERENCE_LENGTH, send.get("reference").get("maxLength"));
    assertEquals(Limits.MAX_RECIPIENTS, send.get("recipients").get("maxItems"));
    assertEquals(
        Limits.MAX_IDEMPOTENCY_KEY_LENGTH,
        ContractTest.<Object>at(
            "components", "parameters", "IdempotencyKey", "schema", "maxLength"));
    List<Map<String, Object>> parameters = at("paths", "/sms", "get", "parameters");
    Map<String, Object> perPage =
        parameters.stream().filter(p -> "per_page".equals(p.get("name"))).findFirst().orElseThrow();
    assertEquals(Limits.MAX_PER_PAGE, ((Map<?, ?>) perPage.get("schema")).get("maximum"));
    assertEquals(Limits.DEFAULT_PER_PAGE, ((Map<?, ?>) perPage.get("schema")).get("default"));
    assertEquals(
        Limits.MAX_OTP_TEMPLATE_LENGTH,
        ContractTest.<Object>at(
            "components", "schemas", "SendOtpRequest", "properties", "template", "maxLength"));
    assertEquals(
        Limits.MAX_OTP_CODE_LENGTH,
        ContractTest.<Object>at(
            "components", "schemas", "VerifyOtpRequest", "properties", "code", "maxLength"));
    assertEquals(
        Webhooks.SIGNATURE_HEADER, at("components", "parameters", "WebhookSignature", "name"));
  }

  @Test
  void messageFixtureHasExactlyTheRequiredFields() {
    Map<?, ?> message = (Map<?, ?>) Json.parse(MockApi.message());
    List<String> required = at("components", "schemas", "Message", "required");
    assertEquals(new TreeSet<>(required), new TreeSet<>(message.keySet()));
  }

  private static Object decode(String json) {
    return Json.parse(json);
  }

  @Test
  void mockedResponsesAreValidResponsesOfTheSpec() {
    String estimate =
        "{\"data\":{\"encoding\":\"ucs2\",\"characters\":19,\"segments_per_message\":1,"
            + "\"recipients_count\":1,\"total_credits\":1,\"balance_after\":null}}";
    assertValid(
        responseSchema("/sms", "post", "202"),
        decode("{\"data\":[" + MockApi.message() + "," + MockApi.message("delivered", "1") + "]}"),
        "POST /sms 202");
    assertValid(
        responseSchema("/sms/{id}", "get", "200"),
        decode("{\"data\":" + MockApi.message() + "}"),
        "GET /sms/{id}");
    assertValid(
        responseSchema("/balance", "get", "200"),
        decode("{\"data\":{\"balance\":120,\"held\":5}}"),
        "balance");
    assertValid(responseSchema("/sms/estimate", "post", "200"), decode(estimate), "estimate");
    assertValid(
        responseSchema("/sender-ids", "get", "200"),
        decode(
            "{\"data\":[{\"id\":\"3c9a1f7e-2b4d-4e6a-8f0c-9d1b5e7a3c20\",\"value\":\"MYSHOP\","
                + "\"status\":\"approved\",\"purpose\":null,\"rejection_reason\":null,"
                + "\"is_default\":true,\"created_at\":\"2026-10-01T08:00:00+00:00\"}]}"),
        "sender-ids");
    assertValid(
        responseSchema("/otp/send", "post", "201"),
        decode(
            "{\"data\":{\"otp_id\":\"9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10\","
                + "\"expires_at\":\"2026-11-05T10:05:00+00:00\",\"max_attempts\":3,"
                + "\"test_code\":\"482913\"}}"),
        "otp/send");
    assertValid(
        responseSchema("/otp/verify", "post", "200"),
        decode("{\"data\":{\"verified\":false,\"reason\":\"max_attempts_reached\"}}"),
        "otp/verify");
    assertValid(
        responseSchema("/sms", "get", "200"),
        decode(MockApi.page(1, 1, 1, MockApi.message()).body),
        "GET /sms");
    assertValid(
        at("components", "schemas", "InsufficientCreditsError"),
        decode(
            MockApi.error(409, "INSUFFICIENT_CREDITS", "{\"available\":3,\"required\":12}").body),
        "INSUFFICIENT_CREDITS");
    assertValid(
        at("components", "schemas", "RecipientNotSendableError"),
        decode(
            MockApi.error(
                    422,
                    "RECIPIENT_NOT_SENDABLE",
                    "{\"reason\":\"blacklisted\",\"rejected\":[{\"recipient\":\"243812345678\","
                        + "\"reason\":\"blacklisted\"}]}")
                .body),
        "RECIPIENT_NOT_SENDABLE");
    assertValid(
        at("components", "schemas", "OtpRateLimitedError"),
        decode(MockApi.error(429, "OTP_RATE_LIMITED", "{\"scope\":\"phone\",\"limit\":5}").body),
        "OTP_RATE_LIMITED");
    assertValid(
        at("components", "schemas", "OtpResendTooSoonError"),
        decode(MockApi.error(429, "OTP_RESEND_TOO_SOON", "{\"retry_after_seconds\":30}").body),
        "OTP_RESEND_TOO_SOON");
    assertValid(
        at("components", "schemas", "ValidationError"),
        decode(
            MockApi.error(
                    422, "VALIDATION_ERROR", "{\"body\":[\"Le champ body est obligatoire.\"]}")
                .body),
        "VALIDATION_ERROR");
  }

  @Test
  void theValidatorRejectsInvalidValues() {
    Object balance = responseSchema("/balance", "get", "200");
    assertFalse(
        validator.validate(balance, decode("{\"data\":{\"balance\":\"x\",\"held\":0}}")).isEmpty());
    assertFalse(validator.validate(balance, decode("{\"data\":{\"balance\":1}}")).isEmpty());
    assertFalse(
        validator.validate(requestSchema("/sms"), decode("{\"recipient\":\"1\"}")).isEmpty());
    assertFalse(
        validator
            .validate(requestSchema("/otp/send"), decode("{\"phone\":\"1\",\"locale\":\"de\"}"))
            .isEmpty());
  }

  @Test
  void everyRequestSentByTheSdkIsValidForTheSpec() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.data(202, "[" + MockApi.message() + "]"),
          MockApi.data(202, "[" + MockApi.message() + "]"),
          MockApi.data(
              200,
              "{\"encoding\":\"gsm7\",\"characters\":5,\"segments_per_message\":1,"
                  + "\"recipients_count\":1,\"total_credits\":1,\"balance_after\":1}"),
          MockApi.data(201, RetryTest.OTP_SENT),
          MockApi.data(200, RetryTest.VERIFIED));
      LabyrintheClient client = api.client();
      client
          .sms()
          .send(
              SendSmsParams.builder()
                  .to("0812345678")
                  .body("Hello")
                  .sender("MYSHOP")
                  .reference("order-1042")
                  .build());
      client
          .sms()
          .sendMany(
              SendManySmsParams.builder()
                  .recipients(List.of("0812345678", "+33612345678"))
                  .body("Hello")
                  .build());
      client
          .sms()
          .estimate(EstimateSmsParams.builder().body("Hello").addRecipient("0812345678").build());
      client
          .otp()
          .send(
              SendOtpParams.builder()
                  .phone("0812345678")
                  .template("Code: {code}")
                  .locale(OtpLocale.FR)
                  .build());
      client.otp().verify("otp-1", "482913");
      for (MockApi.Recorded request : api.requests) {
        String path = request.path.replace("/api/public/v1", "");
        assertValid(requestSchema(path), request.json(), request.method + " " + path);
      }
      assertTrue(
          api.requests.get(0).header("Idempotency-Key").length()
              <= Limits.MAX_IDEMPOTENCY_KEY_LENGTH);
    }
    String pattern = at("components", "parameters", "WebhookSignature", "schema", "pattern");
    assertTrue(Webhooks.sign("{}", "secret").matches(pattern.replace("^", "").replace("$", "")));
  }

  @Test
  void readsTheOtpVerifyExamplesOfTheSpec() {
    Map<String, Map<String, Object>> examples =
        at(
            "paths",
            "/otp/verify",
            "post",
            "responses",
            "200",
            "content",
            "application/json",
            "examples");
    try (MockApi api = new MockApi()) {
      for (Map<String, Object> example : examples.values()) {
        api.enqueue(MockApi.json(200, Json.write(example.get("value"))));
      }
      LabyrintheClient client = api.client();
      assertEquals(OtpVerifyResult.verified(), client.otp().verify("otp-1", "1"));
      assertEquals(
          OtpVerifyResult.failed(OtpFailureReason.INVALID_CODE), client.otp().verify("otp-1", "1"));
    }
  }

  @Test
  void mapsTheErrorExampleOfTheSpec() {
    Object example =
        at("components", "responses", "Unauthenticated", "content", "application/json", "example");
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.json(401, Json.write(example)));
      Executable call = () -> api.client().balance().get();
      AuthenticationException error =
          org.junit.jupiter.api.Assertions.assertThrows(AuthenticationException.class, call);
      assertEquals("UNAUTHENTICATED", error.code());
      assertEquals(401, error.statusCode());
      assertEquals("Clé API invalide.", error.getMessage());
    }
  }

  @Test
  @SuppressWarnings("unchecked")
  void parsesEveryWebhookExampleAndReadsEveryRequiredField() throws Exception {
    Map<String, Object> webhooks = at("webhooks");
    for (String name : webhooks.keySet()) {
      Map<String, Object> content =
          at("webhooks", name, "post", "requestBody", "content", "application/json");
      Map<String, Object> example =
          content.get("example") != null
              ? (Map<String, Object>) content.get("example")
              : Map.of(
                  "id",
                  "00000000-0000-4000-8000-000000000000",
                  "event",
                  name,
                  "data",
                  Map.of(),
                  "timestamp",
                  1794046500);
      assertValid(content.get("schema"), Json.parse(Json.write(example)), "webhook " + name);

      byte[] body = Json.write(example).getBytes(StandardCharsets.UTF_8);
      long timestamp = ((Number) example.get("timestamp")).longValue();
      WebhookEvent event =
          Webhooks.constructEvent(
              body,
              Webhooks.sign(body, "secret"),
              "secret",
              Webhooks.DEFAULT_TOLERANCE,
              Clock.fixed(Instant.ofEpochSecond(timestamp), ZoneOffset.UTC));
      assertEquals(name, event.type());
      assertEquals(name, event.eventType().value());
      assertEquals(example.get("id"), event.id());

      Map<String, Object> dataSchema =
          at(
              "webhooks",
              name,
              "post",
              "requestBody",
              "content",
              "application/json",
              "schema",
              "allOf",
              1,
              "properties",
              "data");
      Object ref = dataSchema.get("$ref");
      if (ref instanceof String) {
        Map<String, Object> resolved = (Map<String, Object>) validator.resolve((String) ref);
        Object data = event.getClass().getMethod("data").invoke(event);
        for (String field : (List<String>) resolved.get("required")) {
          Method accessor = data.getClass().getMethod(camelCase(field));
          accessor.invoke(data);
        }
      }
    }
  }

  @Test
  void exceptionCodesAreStable() {
    List<String> sdkCodes = new ArrayList<>();
    for (Class<? extends LabyrintheException> type :
        List.of(
            cd.labyrinthe.connect.exception.ApiConnectionException.class,
            cd.labyrinthe.connect.exception.ApiTimeoutException.class,
            cd.labyrinthe.connect.exception.InvalidArgumentException.class,
            cd.labyrinthe.connect.exception.UnexpectedResponseException.class,
            cd.labyrinthe.connect.exception.SignatureVerificationException.class,
            cd.labyrinthe.connect.exception.InvalidWebhookPayloadException.class)) {
      try {
        sdkCodes.add((String) type.getField("CODE").get(null));
      } catch (ReflectiveOperationException e) {
        throw new AssertionError(e);
      }
    }
    Collections.sort(sdkCodes);
    assertEquals(
        List.of(
            "CONNECTION_ERROR",
            "INVALID_ARGUMENT",
            "INVALID_WEBHOOK_PAYLOAD",
            "SIGNATURE_VERIFICATION_FAILED",
            "TIMEOUT",
            "UNEXPECTED_RESPONSE"),
        sdkCodes);
  }

  private static String camelCase(String snake) {
    StringBuilder out = new StringBuilder();
    boolean upper = false;
    for (char c : snake.toCharArray()) {
      if (c == '_') {
        upper = true;
      } else {
        out.append(upper ? Character.toUpperCase(c) : c);
        upper = false;
      }
    }
    return out.toString();
  }
}

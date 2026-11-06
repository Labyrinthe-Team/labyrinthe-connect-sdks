package cd.labyrinthe.connect;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import cd.labyrinthe.connect.exception.ApiConnectionException;
import cd.labyrinthe.connect.exception.ApiTimeoutException;
import cd.labyrinthe.connect.exception.IdempotencyKeyReusedException;
import cd.labyrinthe.connect.exception.IdempotencyKeyUnresolvedException;
import cd.labyrinthe.connect.exception.IdempotencyRequestInProgressException;
import cd.labyrinthe.connect.exception.OtpRateLimitedException;
import cd.labyrinthe.connect.exception.OtpResendTooSoonException;
import cd.labyrinthe.connect.exception.RateLimitException;
import cd.labyrinthe.connect.exception.ServiceUnavailableException;
import cd.labyrinthe.connect.exception.ValidationException;
import java.time.Duration;
import java.time.ZoneOffset;
import java.time.ZonedDateTime;
import java.time.format.DateTimeFormatter;
import java.util.HashSet;
import java.util.List;
import java.util.Set;
import org.junit.jupiter.api.Test;

class RetryTest {

  static final String OTP_SENT =
      "{\"otp_id\":\"otp-1\",\"expires_at\":\"2026-11-05T10:05:00+00:00\",\"max_attempts\":3,"
          + "\"code_length\":6,\"code_type\":\"numeric\",\"resend_after_seconds\":60}";
  static final String VERIFIED = "{\"verified\":true,\"reason\":null,\"attempts_remaining\":null}";

  @Test
  void retriesAGetOn5xxThenSucceeds() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.error(503, "HTTP_ERROR"), MockApi.error(500, "HTTP_ERROR"), MockApi.balance());
      assertEquals(5, api.client().balance().get().balance());
      assertEquals(3, api.requests.size());
      assertEquals(2, api.scheduler.sleeps.size());
    }
  }

  @Test
  void stopsAfterMaxRetries() {
    try (MockApi api = new MockApi()) {
      for (int i = 0; i < 5; i++) {
        api.enqueue(MockApi.error(503, "HTTP_ERROR"));
      }
      assertThrows(
          ServiceUnavailableException.class,
          () -> api.builder().maxRetries(2).build().balance().get());
      assertEquals(3, api.requests.size());
    }
  }

  @Test
  void zeroMaxRetriesDisablesRetries() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.error(503, "HTTP_ERROR"));
      assertThrows(
          ServiceUnavailableException.class,
          () -> api.builder().maxRetries(0).build().balance().get());
      assertEquals(1, api.requests.size());
      assertTrue(api.scheduler.sleeps.isEmpty());
    }
  }

  @Test
  void retriesAGetOnNetworkErrors() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.disconnect(), MockApi.balance());
      assertEquals(5, api.client().balance().get().balance());
      assertEquals(2, api.requests.size());
    }
  }

  @Test
  void retriesGetListSenderIdsAndEstimate() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.disconnect(),
          MockApi.data(200, MockApi.message()),
          MockApi.error(502, "HTTP_ERROR"),
          MockApi.data(200, "[]"),
          MockApi.error(504, "HTTP_ERROR"),
          MockApi.page(1, 1, 0),
          MockApi.disconnect(),
          MockApi.data(
              200,
              "{\"encoding\":\"gsm7\",\"characters\":5,\"segments_per_message\":1,"
                  + "\"recipients_count\":1,\"total_credits\":1,\"balance_after\":9}"));
      LabyrintheClient client = api.client();
      client.sms().get("m1");
      assertTrue(client.senderIds().list().isEmpty());
      assertEquals(0, client.sms().list().total());
      assertEquals(
          1,
          client
              .sms()
              .estimate(
                  EstimateSmsParams.builder().body("Hello").addRecipient("0812345678").build())
              .totalCredits());
      assertEquals(8, api.requests.size());
    }
  }

  @Test
  void retriesASendWithTheSameIdempotencyKeyAndBody() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.disconnect(),
          MockApi.error(500, "HTTP_ERROR"),
          MockApi.data(202, "[" + MockApi.message() + "]").header("Idempotent-Replayed", "true"));
      api.client().sms().send("0812345678", "Hello");
      assertEquals(3, api.requests.size());
      Set<String> keys = new HashSet<>();
      Set<String> bodies = new HashSet<>();
      for (MockApi.Recorded request : api.requests) {
        keys.add(request.header("Idempotency-Key"));
        bodies.add(request.body);
      }
      assertEquals(1, keys.size());
      assertEquals(1, bodies.size());
    }
  }

  @Test
  void waitsForRetryAfterOn429() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.error(429, "TOO_MANY_REQUESTS", "{\"retry_after_seconds\":3}")
              .header("Retry-After", "3"),
          MockApi.balance());
      api.client().balance().get();
      assertEquals(List.of(Duration.ofSeconds(3)), api.scheduler.sleeps);
    }
  }

  @Test
  void usesDetailsRetryAfterSecondsWithoutHeader() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.error(429, "TOO_MANY_REQUESTS", "{\"retry_after_seconds\":4}"),
          MockApi.balance());
      api.client().balance().get();
      assertEquals(List.of(Duration.ofSeconds(4)), api.scheduler.sleeps);
    }
  }

  @Test
  void acceptsAnHttpDateRetryAfter() {
    try (MockApi api = new MockApi()) {
      String at =
          DateTimeFormatter.RFC_1123_DATE_TIME.format(
              ZonedDateTime.now(ZoneOffset.UTC).plusSeconds(2));
      api.enqueue(
          MockApi.error(429, "TOO_MANY_REQUESTS").header("Retry-After", at), MockApi.balance());
      api.client().balance().get();
      Duration slept = api.scheduler.sleeps.get(0);
      assertTrue(
          !slept.isNegative() && slept.compareTo(Duration.ofSeconds(2)) <= 0, slept::toString);
    }
  }

  @Test
  void doesNotWaitWhenRetryAfterExceedsMaxRetryWait() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.error(429, "TOO_MANY_REQUESTS").header("Retry-After", "45"));
      RateLimitException error =
          assertThrows(
              RateLimitException.class,
              () -> api.builder().maxRetryWait(Duration.ofSeconds(10)).build().balance().get());
      assertEquals(Duration.ofSeconds(45), error.retryAfter().orElseThrow());
      assertEquals(1, api.requests.size());
      assertTrue(api.scheduler.sleeps.isEmpty());
    }
  }

  @Test
  void a429WithoutRetryAfterUsesBackoff() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.error(429, "TOO_MANY_REQUESTS"), MockApi.balance());
      api.client().balance().get();
      long millis = api.scheduler.sleeps.get(0).toMillis();
      assertTrue(millis >= 250 && millis <= 500, String.valueOf(millis));
    }
  }

  @Test
  void backsOffExponentiallyWithJitterCapped() {
    try (MockApi api = new MockApi()) {
      for (int i = 0; i < 4; i++) {
        api.enqueue(MockApi.error(503, "HTTP_ERROR"));
      }
      assertThrows(
          ServiceUnavailableException.class,
          () -> api.builder().maxRetries(3).build().balance().get());
      assertEquals(3, api.scheduler.sleeps.size());
      long[] bases = {500, 1000, 2000};
      for (int i = 0; i < 3; i++) {
        long millis = api.scheduler.sleeps.get(i).toMillis();
        assertTrue(millis >= bases[i] / 2 && millis <= bases[i], millis + " vs " + bases[i]);
      }
    }
    try (MockApi api = new MockApi()) {
      for (int i = 0; i < 3; i++) {
        api.enqueue(MockApi.error(503, "HTTP_ERROR"));
      }
      assertThrows(
          ServiceUnavailableException.class,
          () -> api.builder().maxRetryWait(Duration.ofMillis(100)).build().balance().get());
      for (Duration delay : api.scheduler.sleeps) {
        assertTrue(delay.toMillis() <= 100);
      }
    }
  }

  @Test
  void neverRetriesClientErrors() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.error(422, "VALIDATION_ERROR", "{\"body\":[\"x\"]}"),
          MockApi.error(409, "IDEMPOTENCY_KEY_REUSED"));
      LabyrintheClient client = api.client();
      assertThrows(ValidationException.class, () -> client.sms().send("0812345678", "Hello"));
      assertThrows(
          IdempotencyKeyReusedException.class, () -> client.sms().send("0812345678", "Hello"));
      assertEquals(2, api.requests.size());
      assertTrue(api.scheduler.sleeps.isEmpty());
    }
  }

  @Test
  void neverRetriesAnUnresolvedIdempotencyKeyOnSmsSend() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.error(409, "IDEMPOTENCY_KEY_UNRESOLVED"), MockApi.data(201, "{}"));
      LabyrintheClient client = api.builder().maxRetries(3).build();
      IdempotencyKeyUnresolvedException error =
          assertThrows(
              IdempotencyKeyUnresolvedException.class,
              () -> client.sms().send("0812345678", "Hello"));
      assertEquals(409, error.statusCode());
      assertEquals("IDEMPOTENCY_KEY_UNRESOLVED", error.code());
      assertEquals(1, api.requests.size());
      assertTrue(api.scheduler.sleeps.isEmpty());
    }
  }

  @Test
  void neverRetriesAnUnresolvedIdempotencyKeyOnOtpSend() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.error(409, "IDEMPOTENCY_KEY_UNRESOLVED"), MockApi.data(201, OTP_SENT));
      LabyrintheClient client = api.builder().maxRetries(3).build();
      assertThrows(IdempotencyKeyUnresolvedException.class, () -> client.otp().send("0812345678"));
      assertEquals(1, api.requests.size());
      assertTrue(api.scheduler.sleeps.isEmpty());
    }
  }

  @Test
  void otpSendIsRetriedAfterANetworkErrorWithTheSameKeyAndBody() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.disconnect(), MockApi.data(201, OTP_SENT).header("Idempotent-Replayed", "true"));
      OtpSendResult otp = api.client().otp().send("0812345678");
      assertEquals("otp-1", otp.otpId());
      assertTrue(otp.replayed());
      assertEquals(2, api.requests.size());
      String key = api.requests.get(0).header("Idempotency-Key");
      assertEquals(key, api.requests.get(1).header("Idempotency-Key"));
      assertEquals(key, otp.idempotencyKey());
      assertEquals(api.requests.get(0).body, api.requests.get(1).body);
    }
  }

  @Test
  void otpSendIsRetriedAfterATimeout() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.data(201, OTP_SENT).delay(1500), MockApi.data(201, OTP_SENT));
      OtpSendResult otp =
          api.builder().timeout(Duration.ofMillis(300)).build().otp().send("0812345678");
      assertEquals("otp-1", otp.otpId());
      assertEquals(2, api.requests.size());
      assertEquals(
          api.requests.get(0).header("Idempotency-Key"),
          api.requests.get(1).header("Idempotency-Key"));
    }
  }

  @Test
  void otpSendIsRetriedAfterA5xxButVerifyIsNot() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.error(500, "HTTP_ERROR"),
          MockApi.data(201, OTP_SENT),
          MockApi.error(503, "HTTP_ERROR"));
      LabyrintheClient client = api.client();
      assertEquals("otp-1", client.otp().send("0812345678").otpId());
      assertThrows(ServiceUnavailableException.class, () -> client.otp().verify("otp-1", "123456"));
      assertEquals(3, api.requests.size());
    }
  }

  @Test
  void otpVerifyIsNeverRetriedAfterATimeout() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.data(200, VERIFIED).delay(1500), MockApi.data(200, VERIFIED));
      ApiTimeoutException error =
          assertThrows(
              ApiTimeoutException.class,
              () ->
                  api.builder()
                      .timeout(Duration.ofMillis(300))
                      .build()
                      .otp()
                      .verify("otp-1", "123456"));
      assertTrue(error.getMessage().contains("within 0.3 s"), error.getMessage());
      assertTrue(error.getMessage().contains("may have been processed"), error.getMessage());
      assertEquals("TIMEOUT", error.code());
      assertEquals(1, api.requests.size());
    }
  }

  @Test
  void idempotencyRequestInProgressIsRetriedWithTheSameKeyForSmsAndOtp() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.error(409, "IDEMPOTENCY_REQUEST_IN_PROGRESS", "{\"retry_after_seconds\":5}")
              .header("Retry-After", "5"),
          MockApi.data(202, "[" + MockApi.message() + "]").header("Idempotent-Replayed", "true"),
          MockApi.error(409, "IDEMPOTENCY_REQUEST_IN_PROGRESS", "{\"retry_after_seconds\":2}"),
          MockApi.data(201, OTP_SENT).header("Idempotent-Replayed", "true"));
      LabyrintheClient client = api.client();
      client.sms().send("0812345678", "Hello");
      assertTrue(client.otp().send("0812345678").replayed());
      assertEquals(List.of(Duration.ofSeconds(5), Duration.ofSeconds(2)), api.scheduler.sleeps);
      assertEquals(4, api.requests.size());
      assertEquals(
          api.requests.get(0).header("Idempotency-Key"),
          api.requests.get(1).header("Idempotency-Key"));
      assertEquals(
          api.requests.get(2).header("Idempotency-Key"),
          api.requests.get(3).header("Idempotency-Key"));
    }
  }

  @Test
  void idempotencyRequestInProgressIsThrownWhenTheWaitIsTooLong() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.error(409, "IDEMPOTENCY_REQUEST_IN_PROGRESS", "{\"retry_after_seconds\":5}")
              .header("Retry-After", "5"));
      IdempotencyRequestInProgressException error =
          assertThrows(
              IdempotencyRequestInProgressException.class,
              () ->
                  api.builder()
                      .maxRetryWait(Duration.ofSeconds(1))
                      .build()
                      .otp()
                      .send("0812345678"));
      assertEquals(409, error.statusCode());
      assertEquals(Duration.ofSeconds(5), error.retryAfter().orElseThrow());
      assertEquals(1, api.requests.size());
      assertTrue(api.scheduler.sleeps.isEmpty());
    }
  }

  @Test
  void yourRequestIdIsSentOnEveryAttempt() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.disconnect(), MockApi.error(503, "HTTP_ERROR"), MockApi.balance());
      api.client().balance().get(RequestOptions.requestId("order-1042:try.1"));
      assertEquals(3, api.requests.size());
      for (MockApi.Recorded request : api.requests) {
        assertEquals("order-1042:try.1", request.header("X-Request-Id"));
      }
    }
  }

  @Test
  void otpIsRetriedOnTooManyRequests() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.error(429, "TOO_MANY_REQUESTS").header("Retry-After", "1"),
          MockApi.data(201, OTP_SENT),
          MockApi.error(429, "TOO_MANY_REQUESTS", "{\"retry_after_seconds\":2}"),
          MockApi.data(200, VERIFIED));
      LabyrintheClient client = api.client();
      assertEquals("otp-1", client.otp().send("0812345678").otpId());
      assertTrue(client.otp().verify("otp-1", "123456").isVerified());
      assertEquals(List.of(Duration.ofSeconds(1), Duration.ofSeconds(2)), api.scheduler.sleeps);
    }
  }

  @Test
  void otpBusinessLimitsAreNeverRetried() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.error(429, "OTP_RESEND_TOO_SOON", "{\"retry_after_seconds\":1}")
              .header("Retry-After", "1"),
          MockApi.error(429, "OTP_RATE_LIMITED", "{\"scope\":\"account\",\"limit\":100}"));
      LabyrintheClient client = api.client();
      assertThrows(OtpResendTooSoonException.class, () -> client.otp().send("0812345678"));
      assertThrows(OtpRateLimitedException.class, () -> client.otp().send("0812345678"));
      assertEquals(2, api.requests.size());
      assertTrue(api.scheduler.sleeps.isEmpty());
    }
  }

  @Test
  void otpVerifyIsNeverRetriedAfterANetworkError() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.disconnect());
      ApiConnectionException error =
          assertThrows(ApiConnectionException.class, () -> api.client().otp().verify("otp-1", "1"));
      assertTrue(error.getMessage().contains("may have been processed"), error.getMessage());
      assertEquals("CONNECTION_ERROR", error.code());
      assertEquals(1, api.requests.size());
    }
  }

  @Test
  void timeoutIsRetriedForSafeRequestsThenThrown() {
    try (MockApi api = new MockApi()) {
      for (int i = 0; i < 3; i++) {
        api.enqueue(MockApi.balance().delay(1000));
      }
      ApiTimeoutException error =
          assertThrows(
              ApiTimeoutException.class,
              () -> api.builder().timeout(Duration.ofMillis(200)).build().balance().get());
      assertTrue(error.getMessage().endsWith("within 0.2 s (GET /balance)."), error.getMessage());
      assertEquals(3, api.requests.size());
    }
  }

  @Test
  void perRequestTimeoutOverridesTheClientTimeout() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.balance().delay(1000));
      assertThrows(
          ApiTimeoutException.class,
          () ->
              api.builder()
                  .maxRetries(0)
                  .build()
                  .balance()
                  .get(RequestOptions.timeout(Duration.ofMillis(200))));
    }
  }

  @Test
  void connectionErrorMessage() {
    LabyrintheClient client =
        LabyrintheClient.builder()
            .apiKey(MockApi.KEY)
            .baseUrl("http://127.0.0.1:1")
            .maxRetries(0)
            .build();
    ApiConnectionException error =
        assertThrows(ApiConnectionException.class, () -> client.balance().get());
    assertEquals("CONNECTION_ERROR", error.code());
    assertEquals(0, error.statusCode());
    assertTrue(
        error
            .getMessage()
            .startsWith("Could not reach the Labyrinthe Connect API (GET /balance): "),
        error.getMessage());
    assertEquals(null, error.getCause());
  }
}

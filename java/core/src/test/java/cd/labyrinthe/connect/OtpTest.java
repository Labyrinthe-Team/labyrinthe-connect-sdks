package cd.labyrinthe.connect;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNotEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import cd.labyrinthe.connect.exception.InvalidArgumentException;
import java.time.Duration;
import java.time.Instant;
import java.util.Map;
import java.util.OptionalInt;
import java.util.UUID;
import org.junit.jupiter.api.Test;

class OtpTest {

  @Test
  void sendsTheShortForeignNumberWithThePlus() {
    // BACKEND-TODO n° 32 : « +2481234567 » (Seychelles) garde son indicatif.
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.data(201, RetryTest.OTP_SENT));
      api.client().otp().send("+2481234567");
      assertEquals("+2481234567", api.last().json().get("phone"));
    }
  }

  @Test
  void sendsACodeWithTheAccountSettings() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.data(201, RetryTest.OTP_SENT));
      OtpSendResult otp = api.client().otp().send("0812345678");
      assertEquals("otp-1", otp.otpId());
      assertEquals(Instant.parse("2026-11-05T10:05:00Z"), otp.expiresAt());
      assertEquals(3, otp.maxAttempts());
      assertEquals(6, otp.codeLength());
      assertEquals(OtpCodeType.NUMERIC, otp.codeType());
      assertEquals(60, otp.resendAfterSeconds());
      assertEquals(Duration.ofMinutes(1), otp.resendAfter());
      assertFalse(otp.replayed());
      assertFalse(otp.testCode().isPresent());
      assertEquals("/api/public/v1/otp/send", api.last().path);
      assertEquals(Map.of("phone", "+243812345678"), api.last().json());
      // Idempotency-Key générée (UUID) : l'envoi OTP est relançable sans second SMS.
      String key = api.last().header("Idempotency-Key");
      assertEquals(key, UUID.fromString(key).toString());
      assertEquals(key, otp.idempotencyKey());
    }
  }

  @Test
  void eachSendGetsItsOwnKeyUnlessYouGiveOne() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.data(201, RetryTest.OTP_SENT),
          MockApi.data(201, RetryTest.OTP_SENT),
          MockApi.data(201, RetryTest.OTP_SENT).header("Idempotent-Replayed", "true"));
      LabyrintheClient client = api.client();
      client.otp().send("0812345678");
      client.otp().send("0812345678");
      assertNotEquals(
          api.requests.get(0).header("Idempotency-Key"),
          api.requests.get(1).header("Idempotency-Key"));
      OtpSendResult replay =
          client
              .otp()
              .send(SendOtpParams.builder().phone("0812345678").idempotencyKey("login-42").build());
      assertEquals("login-42", api.last().header("Idempotency-Key"));
      assertEquals("login-42", replay.idempotencyKey());
      assertTrue(replay.replayed());
    }
  }

  @Test
  void sendsTheWebOtpDomainAndStripAccents() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.data(
              201,
              "{\"otp_id\":\"otp-1\",\"expires_at\":\"2026-11-05T10:05:00+00:00\","
                  + "\"max_attempts\":5,\"code_length\":8,\"code_type\":\"alphanumeric\","
                  + "\"resend_after_seconds\":0}"));
      OtpSendResult otp =
          api.client()
              .otp()
              .send(
                  SendOtpParams.builder()
                      .phone("+33612345678")
                      .webOtpDomain("App.Example.com")
                      .stripAccents(true)
                      .build());
      assertEquals(
          Map.of(
              "phone", "+33612345678", "web_otp_domain", "app.example.com", "strip_accents", true),
          api.last().json());
      assertEquals(8, otp.codeLength());
      assertEquals(OtpCodeType.ALPHANUMERIC, otp.codeType());
      assertEquals(0, otp.resendAfterSeconds());
    }
  }

  @Test
  void toleratesAResponseWithoutThe120Fields() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.data(
              201,
              "{\"otp_id\":\"otp-1\",\"expires_at\":\"2026-11-05T10:05:00.000000Z\","
                  + "\"max_attempts\":3}"),
          MockApi.data(
              201,
              "{\"otp_id\":\"otp-2\",\"expires_at\":\"2026-11-05T10:05:00+00:00\","
                  + "\"max_attempts\":3,\"code_length\":6,\"code_type\":\"emoji\","
                  + "\"resend_after_seconds\":30}"));
      OtpSendResult old = api.client().otp().send("0812345678");
      assertEquals(0, old.codeLength());
      assertEquals(OtpCodeType.UNKNOWN, old.codeType());
      assertEquals(0, old.resendAfterSeconds());
      assertEquals(Instant.parse("2026-11-05T10:05:00Z"), old.expiresAt());
      assertEquals(OtpCodeType.UNKNOWN, api.client().otp().send("0812345678").codeType());
    }
  }

  @Test
  void sendsTheTemplateAndLocale() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.data(201, RetryTest.OTP_SENT));
      api.client()
          .otp()
          .send(
              SendOtpParams.builder()
                  .phone("+33 6 12 34 56 78")
                  .template("Your MyShop code: {code}")
                  .locale(OtpLocale.EN)
                  .build());
      assertEquals(
          Map.of("phone", "+33612345678", "template", "Your MyShop code: {code}", "locale", "en"),
          api.last().json());
    }
  }

  @Test
  void exposesTheTestCodeButNeverInToString() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.data(
              201,
              "{\"otp_id\":\"otp-1\",\"expires_at\":\"2026-11-05T10:05:00+00:00\","
                  + "\"max_attempts\":3,\"test_code\":\"482913\"}"));
      OtpSendResult otp = api.client().otp().send("0812345678");
      assertEquals("482913", otp.testCode().orElseThrow());
      assertFalse(otp.toString().contains("482913"));
      assertTrue(otp.toString().contains("[redacted]"));
      OtpSendResult same =
          new OtpSendResult(
              "otp-1",
              otp.expiresAt(),
              3,
              0,
              OtpCodeType.UNKNOWN,
              0,
              null,
              otp.idempotencyKey(),
              false);
      assertEquals(same, otp);
      assertEquals(same.hashCode(), otp.hashCode());
    }
  }

  @Test
  void verifiesACode() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.data(200, RetryTest.VERIFIED),
          MockApi.data(
              200, "{\"verified\":false,\"reason\":\"invalid_code\",\"attempts_remaining\":4}"),
          MockApi.data(200, "{\"verified\":false,\"reason\":\"brand_new_reason\"}"),
          MockApi.data(
              200,
              "{\"verified\":false,\"reason\":\"max_attempts_reached\",\"attempts_remaining\":0}"),
          MockApi.data(
              200, "{\"verified\":false,\"reason\":\"expired\",\"attempts_remaining\":null}"));
      LabyrintheClient client = api.client();
      OtpVerifyResult ok = client.otp().verify("otp-1", " 482 913 ");
      assertTrue(ok.isVerified());
      assertFalse(ok.reason().isPresent());
      assertFalse(ok.attemptsRemaining().isPresent());
      assertEquals(Map.of("otp_id", "otp-1", "code", " 482 913 "), api.last().json());
      assertEquals("/api/public/v1/otp/verify", api.last().path);

      OtpVerifyResult wrong = client.otp().verify("otp-1", "000000");
      assertEquals(OtpVerifyResult.failed(OtpFailureReason.INVALID_CODE, 4), wrong);
      assertEquals(OptionalInt.of(4), wrong.attemptsRemaining());
      assertNotEquals(OtpVerifyResult.failed(OtpFailureReason.INVALID_CODE), wrong);
      assertNotEquals(OtpVerifyResult.verified(), wrong);
      assertTrue(wrong.toString().contains("attemptsRemaining=4"));
      OtpVerifyResult unknown = client.otp().verify("otp-1", "000000");
      assertEquals(OtpFailureReason.UNKNOWN, unknown.reason().orElseThrow());
      assertFalse(unknown.attemptsRemaining().isPresent());
      assertEquals(
          OtpVerifyResult.failed(OtpFailureReason.MAX_ATTEMPTS_REACHED, 0),
          client.otp().verify("otp-1", "000000"));
      assertEquals(
          OtpVerifyResult.failed(OtpFailureReason.EXPIRED), client.otp().verify("otp-1", "000000"));
      assertThrows(
          IllegalArgumentException.class,
          () -> OtpVerifyResult.failed(OtpFailureReason.INVALID_CODE, -1));
    }
  }

  @Test
  void validatesBeforeAnyCall() {
    try (MockApi api = new MockApi()) {
      LabyrintheClient client = api.client();
      assertThrows(InvalidArgumentException.class, () -> client.otp().send("12"));
      assertThrows(
          InvalidArgumentException.class,
          () -> SendOtpParams.builder().phone("0812345678").template("No token").build());
      assertThrows(
          InvalidArgumentException.class,
          () ->
              SendOtpParams.builder()
                  .phone("0812345678")
                  .template("{code}" + "x".repeat(1600))
                  .build());
      for (String domain :
          new String[] {
            "localhost", "https://app.example.com", "app.example.com:443", "a..b", ""
          }) {
        assertThrows(
            InvalidArgumentException.class,
            () -> SendOtpParams.builder().phone("0812345678").webOtpDomain(domain).build(),
            domain);
      }
      assertThrows(
          InvalidArgumentException.class,
          () ->
              SendOtpParams.builder()
                  .phone("0812345678")
                  .webOtpDomain("a".repeat(250) + ".com")
                  .build());
      assertThrows(
          InvalidArgumentException.class,
          () -> SendOtpParams.builder().phone("0812345678").idempotencyKey("").build());
      assertThrows(InvalidArgumentException.class, () -> client.otp().verify("", "123456"));
      InvalidArgumentException code =
          assertThrows(
              InvalidArgumentException.class,
              () -> client.otp().verify("otp-1", "123456789012345678901"));
      assertFalse(code.getMessage().contains("123456789012345678901"));
      assertThrows(InvalidArgumentException.class, () -> client.otp().verify("otp-1", " "));
      assertTrue(api.requests.isEmpty());
    }
  }
}

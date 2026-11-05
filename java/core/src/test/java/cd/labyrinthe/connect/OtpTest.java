package cd.labyrinthe.connect;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNotEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import cd.labyrinthe.connect.exception.InvalidArgumentException;
import java.time.Instant;
import java.util.Map;
import org.junit.jupiter.api.Test;

class OtpTest {

  @Test
  void sendsACodeWithTheAccountSettings() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.data(201, RetryTest.OTP_SENT));
      OtpSendResult otp = api.client().otp().send("0812345678");
      assertEquals("otp-1", otp.otpId());
      assertEquals(Instant.parse("2026-11-05T10:05:00Z"), otp.expiresAt());
      assertEquals(3, otp.maxAttempts());
      assertFalse(otp.testCode().isPresent());
      assertEquals("/api/public/v1/otp/send", api.last().path);
      assertEquals(Map.of("phone", "243812345678"), api.last().json());
      assertEquals(null, api.last().header("Idempotency-Key"));
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
          Map.of("phone", "33612345678", "template", "Your MyShop code: {code}", "locale", "en"),
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
      OtpSendResult same = new OtpSendResult("otp-1", otp.expiresAt(), 3, null);
      assertEquals(same, otp);
      assertEquals(same.hashCode(), otp.hashCode());
    }
  }

  @Test
  void verifiesACode() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.data(200, RetryTest.VERIFIED),
          MockApi.data(200, "{\"verified\":false,\"reason\":\"invalid_code\"}"),
          MockApi.data(200, "{\"verified\":false,\"reason\":\"brand_new_reason\"}"));
      LabyrintheClient client = api.client();
      OtpVerifyResult ok = client.otp().verify("otp-1", " 482 913 ");
      assertTrue(ok.isVerified());
      assertFalse(ok.reason().isPresent());
      assertEquals(Map.of("otp_id", "otp-1", "code", " 482 913 "), api.last().json());
      assertEquals("/api/public/v1/otp/verify", api.last().path);

      OtpVerifyResult wrong = client.otp().verify("otp-1", "000000");
      assertEquals(OtpVerifyResult.failed(OtpFailureReason.INVALID_CODE), wrong);
      assertNotEquals(OtpVerifyResult.verified(), wrong);
      assertEquals(
          OtpFailureReason.UNKNOWN, client.otp().verify("otp-1", "000000").reason().orElseThrow());
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

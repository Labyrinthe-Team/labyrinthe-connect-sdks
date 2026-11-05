package cd.labyrinthe.connect;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import cd.labyrinthe.connect.exception.ApiConnectionException;
import cd.labyrinthe.connect.exception.AuthenticationException;
import cd.labyrinthe.connect.exception.LabyrintheException;
import java.util.ArrayList;
import java.util.List;
import java.util.logging.Handler;
import java.util.logging.Level;
import java.util.logging.LogRecord;
import java.util.logging.Logger;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

class SecurityTest {

  private static final String SECRET_PART = MockApi.KEY.substring("lsk_test_".length());

  private final List<String> logs = new ArrayList<>();
  private final Logger logger = Logger.getLogger("cd.labyrinthe.connect");
  private Level previousLevel;
  private final Handler handler =
      new Handler() {
        @Override
        public void publish(LogRecord record) {
          synchronized (logs) {
            logs.add(record.getLevel() + " " + record.getMessage());
          }
        }

        @Override
        public void flush() {}

        @Override
        public void close() {}
      };

  @BeforeEach
  void captureLogs() {
    previousLevel = logger.getLevel();
    logger.setLevel(Level.ALL);
    handler.setLevel(Level.ALL);
    logger.addHandler(handler);
  }

  @AfterEach
  void releaseLogs() {
    logger.removeHandler(handler);
    logger.setLevel(previousLevel);
  }

  private void assertNoSecret(String text, String... secrets) {
    assertFalse(text.contains(SECRET_PART), "API key leaked: " + text);
    for (String secret : secrets) {
      assertFalse(text.contains(secret), "\"" + secret + "\" leaked: " + text);
    }
  }

  @Test
  void neverShowsTheApiKey() {
    try (MockApi api = new MockApi()) {
      LabyrintheClient client = api.client();
      assertEquals(
          "LabyrintheClient(apiKey=lsk_test_****, baseUrl=" + client.baseUrl() + ", testMode=true)",
          client.toString());
      for (Object service :
          new Object[] {
            client.sms(),
            client.otp(),
            client.balance(),
            client.senderIds(),
            client.async(),
            client.async().sms(),
            client.async().otp(),
            client.async().balance(),
            client.async().senderIds()
          }) {
        assertNoSecret(service.toString());
        assertTrue(service.toString().contains("lsk_test_****"), service.toString());
      }
      assertTrue(
          LabyrintheClient.builder()
              .apiKey("sk_other_format_secret")
              .build()
              .toString()
              .contains("apiKey=****"));

      api.enqueue(MockApi.error(401, "UNAUTHENTICATED"));
      AuthenticationException error =
          assertThrows(AuthenticationException.class, () -> client.balance().get());
      assertNoSecret(error.getMessage());
      assertNoSecret(error.toString());
      assertNoSecret(error.headers().toString());
    }
  }

  @Test
  void logsNeverContainSecretsCodesBodiesOrFullNumbers() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.disconnect(),
          MockApi.data(202, "[" + MockApi.message() + "]").header("Idempotent-Replayed", "true"),
          MockApi.page(1, 1, 0),
          MockApi.data(
              201,
              "{\"otp_id\":\"otp-1\",\"expires_at\":\"2026-11-05T10:05:00+00:00\","
                  + "\"max_attempts\":3,\"test_code\":\"482913\"}"),
          MockApi.data(200, "{\"verified\":false,\"reason\":\"invalid_code\"}"),
          MockApi.error(
              422, "VALIDATION_ERROR", "{\"phone\":[\"Le numéro 0899999999 est invalide\"]}"),
          MockApi.disconnect());
      LabyrintheClient client = api.client();
      client.sms().send("0812345678", "Top secret body");
      client.sms().list(ListSmsParams.builder().recipient("0812345678").build());
      OtpSendResult otp = client.otp().send("0812345678");
      client.otp().verify(otp.otpId(), "771199");
      assertThrows(LabyrintheException.class, () -> client.otp().send("0899999999"));
      ApiConnectionException network =
          assertThrows(ApiConnectionException.class, () -> client.otp().verify("otp-1", "771199"));
      assertNoSecret(network.getMessage(), "771199");

      String all;
      synchronized (logs) {
        all = String.join("\n", logs);
      }
      assertFalse(logs.isEmpty());
      assertTrue(all.contains("WARNING"), all);
      assertTrue(all.contains("(idempotent replay)"), all);
      assertTrue(all.contains("recipient=24381*****78"), all);
      assertNoSecret(
          all,
          "Top secret body",
          "482913",
          "771199",
          "243812345678",
          "0812345678",
          "812345678",
          "0899999999");
    }
  }

  @Test
  void toStringsMaskPersonalData() {
    String[] texts = {
      SendSmsParams.builder().to("0812345678").body("Secret body").build().toString(),
      SendManySmsParams.builder().addRecipient("0812345678").body("Secret body").build().toString(),
      EstimateSmsParams.builder().addRecipient("0812345678").body("Secret body").build().toString(),
      SendOtpParams.builder().phone("0812345678").build().toString(),
      ListSmsParams.builder().recipient("0812345678").build().toString(),
      new RejectedRecipient("243812345678", RejectionReason.BLACKLISTED).toString(),
      ApiMapper.message(
              ApiMapper.API.asObject(
                  cd.labyrinthe.connect.internal.Json.parse(MockApi.message()), "data"))
          .toString(),
    };
    for (String text : texts) {
      assertNoSecret(text, "0812345678", "243812345678", "Secret body");
    }
  }
}

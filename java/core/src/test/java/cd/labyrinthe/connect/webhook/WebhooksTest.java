package cd.labyrinthe.connect.webhook;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertInstanceOf;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import cd.labyrinthe.connect.MessageStatus;
import cd.labyrinthe.connect.exception.InvalidArgumentException;
import cd.labyrinthe.connect.exception.InvalidWebhookPayloadException;
import cd.labyrinthe.connect.exception.SignatureVerificationException;
import java.nio.charset.StandardCharsets;
import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.ZoneOffset;
import java.util.Locale;
import java.util.Map;
import org.junit.jupiter.api.Test;

class WebhooksTest {

  static final String SECRET = "whsec_test_secret";
  static final long NOW = 1_793_952_000L;
  static final Clock CLOCK = Clock.fixed(Instant.ofEpochSecond(NOW), ZoneOffset.UTC);

  static String envelope(String event, String data, long timestamp) {
    return "{\"id\":\"1f0c7e52-8d3a-4b6e-9c2f-5a7d1e3b9f40\",\"event\":\""
        + event
        + "\",\"data\":"
        + data
        + ",\"timestamp\":"
        + timestamp
        + "}";
  }

  static WebhookEvent construct(String body) {
    byte[] bytes = body.getBytes(StandardCharsets.UTF_8);
    return Webhooks.constructEvent(
        bytes, Webhooks.sign(bytes, SECRET), SECRET, Webhooks.DEFAULT_TOLERANCE, CLOCK);
  }

  @Test
  void signsLikeTheBackend() {
    // HMAC-SHA256("secret", "{}") calculé indépendamment (Python hmac).
    assertEquals(
        "sha256=77325902caca812dc259733aacd046b73817372c777b8d95b402647474516e13",
        Webhooks.sign("{}", "secret"));
    assertEquals(
        Webhooks.sign("é", SECRET), Webhooks.sign("é".getBytes(StandardCharsets.UTF_8), SECRET));
  }

  @Test
  void parsesMessageStatusChanged() {
    String body =
        envelope(
            "message.status_changed",
            "{\"message_id\":\"m1\",\"recipient\":\"243812345678\",\"status\":\"delivered\","
                + "\"provider_message_id\":\"DD-88231\",\"campaign_id\":null}",
            NOW);
    WebhookEvent event = construct(body);
    MessageStatusChanged changed = assertInstanceOf(MessageStatusChanged.class, event);
    assertEquals("1f0c7e52-8d3a-4b6e-9c2f-5a7d1e3b9f40", event.id());
    assertEquals("message.status_changed", event.type());
    assertEquals(WebhookEventType.MESSAGE_STATUS_CHANGED, event.eventType());
    assertEquals(Instant.ofEpochSecond(NOW), event.timestamp());
    assertEquals("m1", changed.data().messageId());
    assertEquals(MessageStatus.DELIVERED, changed.data().status());
    assertEquals("DD-88231", changed.data().providerMessageId().orElseThrow());
    assertFalse(changed.data().campaignId().isPresent());
    assertFalse(changed.data().reference().isPresent());
    assertFalse(changed.data().errorCode().isPresent());
    assertFalse(changed.data().toString().contains("243812345678"));
    assertEquals("m1", ((Map<?, ?>) event.raw().get("data")).get("message_id"));
  }

  @Test
  void parsesTheEnrichedMessageStatusChanged() {
    MessageStatusChanged changed =
        assertInstanceOf(
            MessageStatusChanged.class,
            construct(
                envelope(
                    "message.status_changed",
                    "{\"message_id\":\"m1\",\"recipient\":\"243812345678\",\"status\":\"failed\","
                        + "\"provider_message_id\":null,\"campaign_id\":null,"
                        + "\"reference\":\"CMD-1042\",\"error_code\":\"DD-17\","
                        + "\"error_message\":\"Numéro injoignable\"}",
                    NOW)));
    assertEquals(MessageStatus.FAILED, changed.data().status());
    assertEquals("CMD-1042", changed.data().reference().orElseThrow());
    assertEquals("DD-17", changed.data().errorCode().orElseThrow());
    assertEquals("Numéro injoignable", changed.data().errorMessage().orElseThrow());
  }

  @Test
  void parsesOtpVerifiedAndOtpFailed() {
    OtpVerified verified =
        assertInstanceOf(
            OtpVerified.class,
            construct(
                envelope(
                    "otp.verified",
                    "{\"otp_id\":\"o1\",\"phone\":\"243812345678\",\"status\":\"verified\","
                        + "\"reason\":null,\"verified_at\":\"2026-11-06T09:41:02+00:00\"}",
                    NOW)));
    assertEquals(WebhookEventType.OTP_VERIFIED, verified.eventType());
    assertEquals("o1", verified.data().otpId());
    assertEquals("243812345678", verified.data().phone());
    assertEquals(OtpEventStatus.VERIFIED, verified.data().status());
    assertFalse(verified.data().reason().isPresent());
    assertEquals(Instant.parse("2026-11-06T09:41:02Z"), verified.data().verifiedAt().orElseThrow());
    assertFalse(verified.data().toString().contains("243812345678"));

    OtpFailed failed =
        assertInstanceOf(
            OtpFailed.class,
            construct(
                envelope(
                    "otp.failed",
                    "{\"otp_id\":\"o2\",\"phone\":\"33612345678\",\"status\":\"failed\","
                        + "\"reason\":\"max_attempts_reached\",\"verified_at\":null}",
                    NOW)));
    assertEquals(WebhookEventType.OTP_FAILED, failed.eventType());
    assertEquals(OtpEventStatus.FAILED, failed.data().status());
    assertEquals(
        cd.labyrinthe.connect.OtpFailureReason.MAX_ATTEMPTS_REACHED,
        failed.data().reason().orElseThrow());
    assertFalse(failed.data().verifiedAt().isPresent());

    OtpFailed expired =
        assertInstanceOf(
            OtpFailed.class,
            construct(
                envelope(
                    "otp.failed",
                    "{\"otp_id\":\"o3\",\"phone\":\"243812345678\",\"status\":\"expired\","
                        + "\"reason\":\"expired\",\"verified_at\":null}",
                    NOW)));
    assertEquals(OtpEventStatus.EXPIRED, expired.data().status());
    assertEquals(
        cd.labyrinthe.connect.OtpFailureReason.EXPIRED, expired.data().reason().orElseThrow());
    assertEquals(OtpEventStatus.UNKNOWN, OtpEventStatus.fromValue("pending"));
  }

  @Test
  void parsesCampaignCompletedBalanceLowAndTestPing() {
    CampaignCompleted campaign =
        assertInstanceOf(
            CampaignCompleted.class,
            construct(
                envelope(
                    "campaign.completed",
                    "{\"campaign_id\":\"c1\",\"name\":\"Promo\",\"recipients_count\":1200,"
                        + "\"messages_by_status\":{\"delivered\":1150,\"failed\":30},"
                        + "\"credits_charged\":1200,\"completed_at\":\"2026-11-06T10:15:00+00:00\"}",
                    NOW)));
    assertEquals("Promo", campaign.data().name());
    assertEquals(1150L, campaign.data().messagesByStatus().get("delivered"));
    assertEquals(1200, campaign.data().creditsCharged());
    assertEquals(
        Instant.parse("2026-11-06T10:15:00Z"), campaign.data().completedAt().orElseThrow());

    BalanceLow low =
        assertInstanceOf(
            BalanceLow.class,
            construct(envelope("balance.low", "{\"balance\":950,\"threshold\":1000}", NOW)));
    assertEquals(950, low.data().balance());
    assertEquals(1000, low.data().threshold());

    TestPing ping = assertInstanceOf(TestPing.class, construct(envelope("test.ping", "[]", NOW)));
    assertTrue(ping.data().isEmpty());
    assertEquals(WebhookEventType.TEST_PING, ping.eventType());
  }

  @Test
  void unknownEventsAreVerifiedAndExposed() {
    UnknownEvent event =
        assertInstanceOf(
            UnknownEvent.class,
            construct(envelope("contact.created", "{\"contact_id\":\"o1\"}", NOW)));
    assertEquals("contact.created", event.type());
    assertEquals(WebhookEventType.UNKNOWN, event.eventType());
    assertEquals("o1", event.data().get("contact_id"));
  }

  @Test
  void rejectsAWrongOrMissingSignature() {
    String body = envelope("test.ping", "{}", NOW);
    String good = Webhooks.sign(body, SECRET);
    for (String bad :
        new String[] {
          null,
          "",
          "sha256=",
          good.substring(7),
          "sha1=" + good.substring(7),
          good.substring(0, good.length() - 1) + (good.endsWith("0") ? "1" : "0"),
          Webhooks.sign(body, "other-secret"),
          good + "00",
          "sha256=" + "z".repeat(64)
        }) {
      assertThrows(
          SignatureVerificationException.class,
          () -> Webhooks.constructEvent(body, bad, SECRET),
          String.valueOf(bad));
    }
    // En-tête en majuscules et espaces tolérés.
    assertTrue(Webhooks.verifySignature(body, "  " + good.toUpperCase(Locale.ROOT) + " ", SECRET));
  }

  @Test
  void rejectsATamperedBody() {
    String body = envelope("test.ping", "{}", NOW);
    String signature = Webhooks.sign(body, SECRET);
    assertThrows(
        SignatureVerificationException.class,
        () -> Webhooks.constructEvent(body.replace("test.ping", "balance.low"), signature, SECRET));
    // Le corps re-sérialisé (espaces) ne correspond plus : il faut le corps brut.
    assertThrows(
        SignatureVerificationException.class,
        () -> Webhooks.constructEvent(body.replace(",", ", "), signature, SECRET));
  }

  @Test
  void rejectsAnExpiredOrFutureTimestamp() {
    for (long timestamp : new long[] {NOW - 301, NOW + 301}) {
      byte[] body = envelope("test.ping", "{}", timestamp).getBytes(StandardCharsets.UTF_8);
      SignatureVerificationException error =
          assertThrows(
              SignatureVerificationException.class,
              () ->
                  Webhooks.constructEvent(
                      body,
                      Webhooks.sign(body, SECRET),
                      SECRET,
                      Webhooks.DEFAULT_TOLERANCE,
                      CLOCK));
      assertTrue(error.getMessage().contains("300 s tolerance"), error.getMessage());
      assertEquals("SIGNATURE_VERIFICATION_FAILED", error.code());
    }
    byte[] old = envelope("test.ping", "{}", NOW - 300).getBytes(StandardCharsets.UTF_8);
    Webhooks.constructEvent(old, Webhooks.sign(old, SECRET), SECRET, Duration.ofMinutes(5), CLOCK);
    byte[] ancient = envelope("test.ping", "{}", 0).getBytes(StandardCharsets.UTF_8);
    Webhooks.constructEvent(ancient, Webhooks.sign(ancient, SECRET), SECRET, Duration.ZERO, CLOCK);
    assertThrows(
        SignatureVerificationException.class,
        () ->
            Webhooks.constructEvent(
                ancient, Webhooks.sign(ancient, SECRET), SECRET, Duration.ofSeconds(10), CLOCK));
  }

  @Test
  void rejectsSignedBodiesThatAreNotEnvelopes() {
    for (String body :
        new String[] {
          "not json",
          "[1,2]",
          "{\"event\":\"test.ping\",\"data\":{},\"timestamp\":" + NOW + "}",
          "{\"id\":\"x\",\"data\":{},\"timestamp\":" + NOW + "}",
          "{\"id\":\"x\",\"event\":\"test.ping\",\"data\":{}}",
          envelope("message.status_changed", "{\"message_id\":\"m1\"}", NOW),
          envelope("balance.low", "{\"balance\":\"lots\",\"threshold\":1}", NOW),
        }) {
      assertThrows(InvalidWebhookPayloadException.class, () -> construct(body), body);
    }
  }

  @Test
  void validatesArguments() {
    assertThrows(InvalidArgumentException.class, () -> Webhooks.sign("{}", ""));
    assertThrows(InvalidArgumentException.class, () -> Webhooks.sign((String) null, SECRET));
    byte[] body = "{}".getBytes(StandardCharsets.UTF_8);
    assertThrows(
        InvalidArgumentException.class,
        () -> Webhooks.constructEvent(body, "x", SECRET, Duration.ofSeconds(-1), CLOCK));
  }
}

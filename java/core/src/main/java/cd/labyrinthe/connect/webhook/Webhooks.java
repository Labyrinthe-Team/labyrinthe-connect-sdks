package cd.labyrinthe.connect.webhook;

import cd.labyrinthe.connect.MessageStatus;
import cd.labyrinthe.connect.OtpFailureReason;
import cd.labyrinthe.connect.exception.InvalidArgumentException;
import cd.labyrinthe.connect.exception.InvalidWebhookPayloadException;
import cd.labyrinthe.connect.exception.SignatureVerificationException;
import cd.labyrinthe.connect.internal.Json;
import cd.labyrinthe.connect.internal.JsonReader;
import java.nio.charset.StandardCharsets;
import java.security.InvalidKeyException;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.time.Clock;
import java.time.DateTimeException;
import java.time.Duration;
import java.time.Instant;
import java.util.LinkedHashMap;
import java.util.Locale;
import java.util.Map;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;

/**
 * Webhook verification and typed events.
 *
 * <pre>{@code
 * WebhookEvent event = Webhooks.constructEvent(rawBody, request.getHeader("X-Signature"), secret);
 * if (event instanceof MessageStatusChanged) {
 *   MessageStatusChanged changed = (MessageStatusChanged) event;
 *   updateMessage(changed.data().messageId(), changed.data().status());
 * } else if (event instanceof OtpVerified) {
 *   markVerified(((OtpVerified) event).data().otpId());
 * }
 * }</pre>
 *
 * <p>The signature ({@code X-Signature: sha256=<hex HMAC-SHA256 of the raw body>}) is checked in
 * constant time ({@link MessageDigest#isEqual(byte[], byte[])}) on the <b>raw</b> body, before any
 * JSON parsing; then the signed {@code timestamp} must be within the tolerance (replay protection).
 * Deduplicate on {@link WebhookEvent#id()}: deliveries are retried up to 5 times and may arrive out
 * of order.
 */
public final class Webhooks {

  /** Name of the signature header: {@value}. */
  public static final String SIGNATURE_HEADER = "X-Signature";

  /** Default maximum age of a delivery: 5 minutes. */
  public static final Duration DEFAULT_TOLERANCE = Duration.ofMinutes(5);

  private static final String PREFIX = "sha256=";
  private static final int SIGNATURE_LENGTH = PREFIX.length() + 64;
  private static final char[] HEX = "0123456789abcdef".toCharArray();

  private static final JsonReader READER =
      new JsonReader("Invalid webhook payload", InvalidWebhookPayloadException::new);

  private Webhooks() {}

  /**
   * Verifies a delivery with the default tolerance (5 minutes) and returns the typed event.
   *
   * @param rawBody the exact request body, decoded as UTF-8 — never a re-serialised object
   * @param signatureHeader value of the {@code X-Signature} header ({@code null} when missing)
   * @param secret the endpoint secret (client area, Developers, Webhooks)
   * @return the event
   * @throws SignatureVerificationException wrong or missing signature, or timestamp out of
   *     tolerance: answer 400
   * @throws InvalidWebhookPayloadException signed body that is not a valid envelope: answer 400
   */
  public static WebhookEvent constructEvent(String rawBody, String signatureHeader, String secret) {
    return constructEvent(rawBody, signatureHeader, secret, DEFAULT_TOLERANCE);
  }

  /**
   * Verifies a delivery and returns the typed event.
   *
   * @param rawBody the exact request body, decoded as UTF-8
   * @param signatureHeader value of the {@code X-Signature} header
   * @param secret the endpoint secret
   * @param tolerance max age of the signed timestamp; {@link Duration#ZERO} disables the check (not
   *     recommended)
   * @return the event
   * @throws SignatureVerificationException wrong or missing signature, or expired timestamp
   * @throws InvalidWebhookPayloadException signed body that is not a valid envelope
   */
  public static WebhookEvent constructEvent(
      String rawBody, String signatureHeader, String secret, Duration tolerance) {
    return constructEvent(bytes(rawBody), signatureHeader, secret, tolerance, Clock.systemUTC());
  }

  /**
   * Verifies a delivery with the default tolerance and returns the typed event.
   *
   * @param rawBody the exact bytes of the request body (preferred)
   * @param signatureHeader value of the {@code X-Signature} header
   * @param secret the endpoint secret
   * @return the event
   * @throws SignatureVerificationException wrong or missing signature, or expired timestamp
   * @throws InvalidWebhookPayloadException signed body that is not a valid envelope
   */
  public static WebhookEvent constructEvent(byte[] rawBody, String signatureHeader, String secret) {
    return constructEvent(rawBody, signatureHeader, secret, DEFAULT_TOLERANCE, Clock.systemUTC());
  }

  /**
   * Verifies a delivery and returns the typed event.
   *
   * @param rawBody the exact bytes of the request body
   * @param signatureHeader value of the {@code X-Signature} header
   * @param secret the endpoint secret
   * @param tolerance max age of the signed timestamp; {@link Duration#ZERO} disables the check
   * @param clock current time (tests)
   * @return the event
   * @throws SignatureVerificationException wrong or missing signature, or expired timestamp
   * @throws InvalidWebhookPayloadException signed body that is not a valid envelope
   */
  public static WebhookEvent constructEvent(
      byte[] rawBody, String signatureHeader, String secret, Duration tolerance, Clock clock) {
    if (rawBody == null) {
      throw new InvalidArgumentException("The webhook body cannot be null.");
    }
    if (tolerance == null || tolerance.isNegative()) {
      throw new InvalidArgumentException("tolerance cannot be negative.");
    }
    if (!verifySignature(rawBody, signatureHeader, secret)) {
      throw new SignatureVerificationException(
          "Invalid webhook signature: the body was not signed with this secret.");
    }

    Object decoded;
    try {
      decoded = Json.parse(new String(rawBody, StandardCharsets.UTF_8));
    } catch (Json.JsonException e) {
      decoded = null;
    }
    if (!(decoded instanceof Map)) {
      throw new InvalidWebhookPayloadException("The webhook body is not a JSON object.");
    }
    Map<String, Object> envelope = READER.asObject(decoded, "body");

    long timestamp = READER.longValue(envelope, "timestamp");
    if (!tolerance.isZero()) {
      long now = clock.instant().getEpochSecond();
      if (Math.abs(now - timestamp) > tolerance.getSeconds()) {
        throw new SignatureVerificationException(
            "Webhook timestamp is outside the "
                + tolerance.getSeconds()
                + " s tolerance (possible replay).");
      }
    }
    return build(envelope, timestamp);
  }

  /**
   * Constant-time check of the {@code X-Signature} header against the raw body.
   *
   * @param rawBody the exact bytes of the request body
   * @param signatureHeader value of the header (case-insensitive hexadecimal)
   * @param secret the endpoint secret
   * @return true when the signature matches
   */
  public static boolean verifySignature(byte[] rawBody, String signatureHeader, String secret) {
    byte[] expected = sign(rawBody, secret).getBytes(StandardCharsets.US_ASCII);
    String signature =
        signatureHeader == null ? "" : signatureHeader.trim().toLowerCase(Locale.ROOT);
    if (signature.length() != SIGNATURE_LENGTH || !signature.startsWith(PREFIX)) {
      return false;
    }
    for (int i = PREFIX.length(); i < signature.length(); i++) {
      char c = signature.charAt(i);
      if (!((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f'))) {
        return false;
      }
    }
    return MessageDigest.isEqual(signature.getBytes(StandardCharsets.US_ASCII), expected);
  }

  /**
   * Constant-time check of the {@code X-Signature} header against the raw body.
   *
   * @param rawBody the exact request body, as UTF-8 text
   * @param signatureHeader value of the header
   * @param secret the endpoint secret
   * @return true when the signature matches
   */
  public static boolean verifySignature(String rawBody, String signatureHeader, String secret) {
    return verifySignature(bytes(rawBody), signatureHeader, secret);
  }

  /**
   * The {@code X-Signature} header value for a body: {@code "sha256=<hex>"}. Useful to test your
   * endpoint.
   *
   * @param rawBody the body
   * @param secret the endpoint secret
   * @return the header value
   */
  public static String sign(byte[] rawBody, String secret) {
    if (secret == null || secret.isEmpty()) {
      throw new InvalidArgumentException("The webhook secret cannot be empty.");
    }
    if (rawBody == null) {
      throw new InvalidArgumentException("The webhook body cannot be null.");
    }
    try {
      Mac mac = Mac.getInstance("HmacSHA256");
      mac.init(new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
      byte[] digest = mac.doFinal(rawBody);
      StringBuilder out = new StringBuilder(SIGNATURE_LENGTH).append(PREFIX);
      for (byte b : digest) {
        out.append(HEX[(b >> 4) & 0xf]).append(HEX[b & 0xf]);
      }
      return out.toString();
    } catch (NoSuchAlgorithmException | InvalidKeyException e) {
      // HmacSHA256 est garanti par toute JVM conforme.
      throw new IllegalStateException("HmacSHA256 is not available", e);
    }
  }

  /**
   * The {@code X-Signature} header value for a body.
   *
   * @param rawBody the body, as UTF-8 text
   * @param secret the endpoint secret
   * @return the header value
   */
  public static String sign(String rawBody, String secret) {
    return sign(bytes(rawBody), secret);
  }

  private static byte[] bytes(String rawBody) {
    if (rawBody == null) {
      throw new InvalidArgumentException("The webhook body cannot be null.");
    }
    return rawBody.getBytes(StandardCharsets.UTF_8);
  }

  private static WebhookEvent build(Map<String, Object> envelope, long timestamp) {
    String id = READER.string(envelope, "id");
    String name = READER.string(envelope, "event");
    Map<String, Object> data = READER.optionalObject(envelope, "data");
    Instant sentAt;
    try {
      sentAt = Instant.ofEpochSecond(timestamp);
    } catch (DateTimeException e) {
      throw new InvalidWebhookPayloadException(
          "Invalid webhook payload: field \"timestamp\" is out of range.");
    }

    switch (WebhookEventType.fromValue(name)) {
      case MESSAGE_STATUS_CHANGED:
        return new MessageStatusChanged(
            id,
            name,
            sentAt,
            envelope,
            new MessageStatusChanged.Data(
                READER.string(data, "message_id"),
                READER.string(data, "recipient"),
                MessageStatus.fromValue(READER.string(data, "status")),
                READER.nullableString(data, "provider_message_id"),
                READER.nullableString(data, "campaign_id"),
                // Champs de l'API 1.2.0 : absents d'une livraison plus ancienne.
                READER.nullableString(data, "reference"),
                READER.nullableString(data, "error_code"),
                READER.nullableString(data, "error_message")));
      case CAMPAIGN_COMPLETED:
        Map<String, Object> counts = READER.optionalObject(data, "messages_by_status");
        Map<String, Long> byStatus = new LinkedHashMap<>();
        for (String status : counts.keySet()) {
          byStatus.put(status, READER.longValue(counts, status));
        }
        String campaignName = READER.nullableString(data, "name");
        Long recipients = READER.nullableLong(data, "recipients_count");
        Long credits = READER.nullableLong(data, "credits_charged");
        return new CampaignCompleted(
            id,
            name,
            sentAt,
            envelope,
            new CampaignCompleted.Data(
                READER.string(data, "campaign_id"),
                campaignName == null ? "" : campaignName,
                recipients == null ? 0 : recipients,
                byStatus,
                credits == null ? 0 : credits,
                READER.nullableInstant(data, "completed_at")));
      case BALANCE_LOW:
        return new BalanceLow(
            id,
            name,
            sentAt,
            envelope,
            new BalanceLow.Data(
                READER.longValue(data, "balance"), READER.longValue(data, "threshold")));
      case OTP_VERIFIED:
        return new OtpVerified(id, name, sentAt, envelope, otpData(data));
      case OTP_FAILED:
        return new OtpFailed(id, name, sentAt, envelope, otpData(data));
      case TEST_PING:
        return new TestPing(id, name, sentAt, envelope, data);
      default:
        return new UnknownEvent(id, name, sentAt, envelope, data);
    }
  }

  private static OtpEventData otpData(Map<String, Object> data) {
    String reason = READER.nullableString(data, "reason");
    return new OtpEventData(
        READER.string(data, "otp_id"),
        READER.string(data, "phone"),
        OtpEventStatus.fromValue(READER.string(data, "status")),
        reason == null ? null : OtpFailureReason.fromValue(reason),
        READER.nullableInstant(data, "verified_at"));
  }
}

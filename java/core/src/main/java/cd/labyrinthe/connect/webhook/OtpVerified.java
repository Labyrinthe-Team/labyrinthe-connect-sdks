package cd.labyrinthe.connect.webhook;

import java.time.Instant;
import java.util.Map;
import java.util.Objects;

/**
 * {@code otp.verified}: a code was verified (right code given to {@code otp().verify()}, from the
 * API or the client area). Sent once, after commit.
 */
public final class OtpVerified extends WebhookEvent {

  private final OtpEventData data;

  OtpVerified(
      String id, String type, Instant timestamp, Map<String, Object> raw, OtpEventData data) {
    super(id, type, timestamp, raw);
    this.data = Objects.requireNonNull(data, "data");
  }

  @Override
  public WebhookEventType eventType() {
    return WebhookEventType.OTP_VERIFIED;
  }

  /**
   * The verification.
   *
   * @return the data
   */
  public OtpEventData data() {
    return data;
  }
}

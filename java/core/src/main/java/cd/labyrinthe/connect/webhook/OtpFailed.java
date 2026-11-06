package cd.labyrinthe.connect.webhook;

import java.time.Instant;
import java.util.Map;
import java.util.Objects;

/**
 * {@code otp.failed}: a verification failed for good: every attempt was used ({@link
 * OtpEventStatus#FAILED}) or the code expired ({@link OtpEventStatus#EXPIRED}). A wrong code that
 * leaves attempts sends nothing. Sent once, after commit.
 */
public final class OtpFailed extends WebhookEvent {

  private final OtpEventData data;

  OtpFailed(String id, String type, Instant timestamp, Map<String, Object> raw, OtpEventData data) {
    super(id, type, timestamp, raw);
    this.data = Objects.requireNonNull(data, "data");
  }

  @Override
  public WebhookEventType eventType() {
    return WebhookEventType.OTP_FAILED;
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

package cd.labyrinthe.connect.webhook;

import java.time.Instant;
import java.util.Map;
import java.util.Objects;

/**
 * An event type added to the API after this SDK version.
 *
 * <p>Its signature was verified: {@link #type()} holds its name and {@link #data()} its content (as
 * sent, snake_case). Acknowledge it with a 2xx.
 */
public final class UnknownEvent extends WebhookEvent {

  private final Map<String, Object> data;

  UnknownEvent(
      String id,
      String type,
      Instant timestamp,
      Map<String, Object> raw,
      Map<String, Object> data) {
    super(id, type, timestamp, raw);
    this.data = Objects.requireNonNull(data, "data");
  }

  @Override
  public WebhookEventType eventType() {
    return WebhookEventType.UNKNOWN;
  }

  /**
   * Content of the event, as sent.
   *
   * @return unmodifiable map
   */
  public Map<String, Object> data() {
    return data;
  }
}

package cd.labyrinthe.connect.webhook;

import java.time.Instant;
import java.util.Map;
import java.util.Objects;

/** {@code test.ping}: sent by the Test button of the client area (Developers, Webhooks). */
public final class TestPing extends WebhookEvent {

  private final Map<String, Object> data;

  TestPing(
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
    return WebhookEventType.TEST_PING;
  }

  /**
   * Content of the event (usually empty).
   *
   * @return unmodifiable map
   */
  public Map<String, Object> data() {
    return data;
  }
}

package cd.labyrinthe.connect.webhook;

import java.time.Instant;
import java.util.Map;

/**
 * A verified webhook event. The hierarchy is closed: an event is one of {@link
 * MessageStatusChanged}, {@link CampaignCompleted}, {@link BalanceLow}, {@link TestPing} or {@link
 * UnknownEvent}.
 *
 * <pre>{@code
 * if (event instanceof MessageStatusChanged) {
 *   MessageStatusChanged changed = (MessageStatusChanged) event;
 *   updateMessage(changed.data().messageId(), changed.data().status());
 * }
 * // or: switch (event.eventType()) { case MESSAGE_STATUS_CHANGED: ... }
 * }</pre>
 */
public abstract class WebhookEvent {

  private final String id;
  private final String type;
  private final Instant timestamp;
  private final Map<String, Object> raw;

  // Constructeur du paquet : aucune autre sous-classe possible (hiérarchie fermée). Les valeurs
  // sont validées par Webhooks avant la construction.
  WebhookEvent(String id, String type, Instant timestamp, Map<String, Object> raw) {
    this.id = id;
    this.type = type;
    this.timestamp = timestamp;
    this.raw = raw;
  }

  /**
   * Event identifier, identical across delivery attempts: deduplicate on it (deliveries are retried
   * up to 5 times and may arrive out of order).
   *
   * @return the event id
   */
  public final String id() {
    return id;
  }

  /**
   * Event name exactly as sent by the API, e.g. {@code "message.status_changed"} (also for unknown
   * events).
   *
   * @return the event name
   */
  public final String type() {
    return type;
  }

  /**
   * The event type, for a {@code switch}.
   *
   * @return the type ({@link WebhookEventType#UNKNOWN} for an {@link UnknownEvent})
   */
  public abstract WebhookEventType eventType();

  /**
   * Time of this delivery attempt (signed).
   *
   * @return the timestamp
   */
  public final Instant timestamp() {
    return timestamp;
  }

  /**
   * Decoded envelope {@code {"id", "event", "data", "timestamp"}}, as sent by the API.
   *
   * @return unmodifiable map
   */
  public final Map<String, Object> raw() {
    return raw;
  }

  @Override
  public String toString() {
    return getClass().getSimpleName() + "(id=" + id + ", type=" + type + ")";
  }
}

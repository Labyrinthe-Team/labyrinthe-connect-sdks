package cd.labyrinthe.connect.webhook;

/**
 * Webhook event types emitted by the API.
 *
 * <p>{@link #UNKNOWN} is used for an event added to the API after this SDK version.
 */
public enum WebhookEventType {
  /** {@code message.status_changed}: the status of a message changed. */
  MESSAGE_STATUS_CHANGED("message.status_changed"),
  /** {@code campaign.completed}: every message of a campaign reached a final status. */
  CAMPAIGN_COMPLETED("campaign.completed"),
  /** {@code balance.low}: the balance went below the account's low-balance threshold. */
  BALANCE_LOW("balance.low"),
  /** {@code test.ping}: sent by the Test button of the client area. */
  TEST_PING("test.ping"),
  /** An event type this SDK version does not know yet. */
  UNKNOWN("unknown");

  private final String value;

  WebhookEventType(String value) {
    this.value = value;
  }

  /**
   * The event name used by the API.
   *
   * @return e.g. {@code "message.status_changed"}
   */
  public String value() {
    return value;
  }

  /**
   * The constant for an event name.
   *
   * @param value event name (may be {@code null})
   * @return the matching constant, or {@link #UNKNOWN}
   */
  public static WebhookEventType fromValue(String value) {
    for (WebhookEventType candidate : values()) {
      if (candidate != UNKNOWN && candidate.value.equals(value)) {
        return candidate;
      }
    }
    return UNKNOWN;
  }

  /** Returns the event name. */
  @Override
  public String toString() {
    return value;
  }
}

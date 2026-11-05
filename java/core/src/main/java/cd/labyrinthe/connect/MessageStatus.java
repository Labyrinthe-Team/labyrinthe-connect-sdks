package cd.labyrinthe.connect;

/**
 * Lifecycle of a message.
 *
 * <p>{@link #UNKNOWN} is never sent by the API: the SDK uses it for a value added to the API after
 * this SDK version, so that your code keeps working.
 */
public enum MessageStatus {
  /** Queued at Labyrinthe Connect. */
  QUEUED("queued"),
  /** Handed over to the SMS provider. */
  SUBMITTED("submitted"),
  /** Accepted by the mobile operator. */
  SENT("sent"),
  /** Delivered to the handset (final). */
  DELIVERED("delivered"),
  /** Failed (final). */
  FAILED("failed"),
  /** Rejected by the provider or the operator (final). */
  REJECTED("rejected"),
  /** Expired before delivery (final). */
  EXPIRED("expired"),
  /** Cannot be delivered to this number (final). */
  UNDELIVERABLE("undeliverable"),
  /** A value this SDK version does not know yet. */
  UNKNOWN("unknown");

  private final String value;

  MessageStatus(String value) {
    this.value = value;
  }

  /**
   * The value used by the API.
   *
   * @return the API value, e.g. {@code "queued"}
   */
  public String value() {
    return value;
  }

  /**
   * The constant for an API value.
   *
   * @param value API value (may be {@code null})
   * @return the matching constant, or {@link #UNKNOWN}
   */
  public static MessageStatus fromValue(String value) {
    for (MessageStatus candidate : values()) {
      if (candidate != UNKNOWN && candidate.value.equals(value)) {
        return candidate;
      }
    }
    return UNKNOWN;
  }

  /**
   * Whether the status will not change any more (delivered or failed).
   *
   * @return true for {@link #DELIVERED} and every failed status
   */
  public boolean isFinal() {
    return this == DELIVERED || isFailed();
  }

  /**
   * Whether the message failed.
   *
   * @return true for {@link #FAILED}, {@link #REJECTED}, {@link #EXPIRED} and {@link
   *     #UNDELIVERABLE}
   */
  public boolean isFailed() {
    return this == FAILED || this == REJECTED || this == EXPIRED || this == UNDELIVERABLE;
  }

  /**
   * Whether the message is still on its way.
   *
   * @return true for {@link #QUEUED}, {@link #SUBMITTED} and {@link #SENT}
   */
  public boolean isPending() {
    return this == QUEUED || this == SUBMITTED || this == SENT;
  }

  /** Returns the API value. */
  @Override
  public String toString() {
    return value;
  }
}

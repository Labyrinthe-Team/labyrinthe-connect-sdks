package cd.labyrinthe.connect;

/**
 * Validation status of a Sender ID.
 *
 * <p>{@link #UNKNOWN} is never sent by the API: the SDK uses it for a value added to the API after
 * this SDK version, so that your code keeps working.
 */
public enum SenderIdStatus {
  /** Waiting for review by Labyrinthe Connect. */
  PENDING("pending"),
  /** Waiting for the SMS provider. */
  PENDING_PROVIDER("pending_provider"),
  /** Usable as {@code sender}. */
  APPROVED("approved"),
  /** Refused. */
  REJECTED("rejected"),
  /** Suspended. */
  SUSPENDED("suspended"),
  /** A value this SDK version does not know yet. */
  UNKNOWN("unknown");

  private final String value;

  SenderIdStatus(String value) {
    this.value = value;
  }

  /**
   * The value used by the API.
   *
   * @return the API value, e.g. {@code "approved"}
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
  public static SenderIdStatus fromValue(String value) {
    for (SenderIdStatus candidate : values()) {
      if (candidate != UNKNOWN && candidate.value.equals(value)) {
        return candidate;
      }
    }
    return UNKNOWN;
  }

  /** Returns the API value. */
  @Override
  public String toString() {
    return value;
  }
}

package cd.labyrinthe.connect;

/**
 * Why a recipient was refused (see {@link
 * cd.labyrinthe.connect.exception.RecipientNotSendableException}).
 *
 * <p>{@link #UNKNOWN} is never sent by the API: the SDK uses it for a value added to the API after
 * this SDK version, so that your code keeps working.
 */
public enum RejectionReason {
  /** The number is not a valid phone number. */
  INVALID_PHONE("invalid_phone"),
  /** The number is blacklisted. */
  BLACKLISTED("blacklisted"),
  /** The recipient opted out. */
  OPTED_OUT("opted_out"),
  /** A value this SDK version does not know yet. */
  UNKNOWN("unknown");

  private final String value;

  RejectionReason(String value) {
    this.value = value;
  }

  /**
   * The value used by the API.
   *
   * @return the API value, e.g. {@code "blacklisted"}
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
  public static RejectionReason fromValue(String value) {
    for (RejectionReason candidate : values()) {
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

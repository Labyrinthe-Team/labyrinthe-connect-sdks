package cd.labyrinthe.connect;

/**
 * Why an OTP verification failed.
 *
 * <p>{@link #UNKNOWN} is never sent by the API: the SDK uses it for a value added to the API after
 * this SDK version, so that your code keeps working.
 */
public enum OtpFailureReason {
  /** Wrong code: one attempt was consumed, the user may try again. */
  INVALID_CODE("invalid_code"),
  /** The code expired: send a new one. */
  EXPIRED("expired"),
  /** The code was already verified (each code is single-use). */
  ALREADY_USED("already_used"),
  /** Too many wrong attempts: the code is permanently blocked. */
  MAX_ATTEMPTS_REACHED("max_attempts_reached"),
  /** A value this SDK version does not know yet. */
  UNKNOWN("unknown");

  private final String value;

  OtpFailureReason(String value) {
    this.value = value;
  }

  /**
   * The value used by the API.
   *
   * @return the API value, e.g. {@code "invalid_code"}
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
  public static OtpFailureReason fromValue(String value) {
    for (OtpFailureReason candidate : values()) {
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

package cd.labyrinthe.connect.webhook;

/**
 * Outcome of an OTP verification, in {@link OtpVerified} and {@link OtpFailed} events.
 *
 * <p>{@link #UNKNOWN} is never sent by the API: the SDK uses it for a value added to the API after
 * this SDK version.
 */
public enum OtpEventStatus {
  /** The right code was given ({@code otp.verified}). */
  VERIFIED("verified"),
  /** Every attempt was used ({@code otp.failed}, reason {@code max_attempts_reached}). */
  FAILED("failed"),
  /** The code expired before being verified ({@code otp.failed}, reason {@code expired}). */
  EXPIRED("expired"),
  /** A value this SDK version does not know yet. */
  UNKNOWN("unknown");

  private final String value;

  OtpEventStatus(String value) {
    this.value = value;
  }

  /**
   * The value used by the API.
   *
   * @return e.g. {@code "verified"}
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
  public static OtpEventStatus fromValue(String value) {
    for (OtpEventStatus candidate : values()) {
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

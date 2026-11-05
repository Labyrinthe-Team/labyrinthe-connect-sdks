package cd.labyrinthe.connect;

/**
 * Scope of an {@code OTP_RATE_LIMITED} error: one phone number or the whole account.
 *
 * <p>{@link #UNKNOWN} is never sent by the API: the SDK uses it for a value added to the API after
 * this SDK version, so that your code keeps working.
 */
public enum OtpRateLimitScope {
  /** Hourly cap reached for this phone number. */
  PHONE("phone"),
  /** Hourly cap reached for the whole account. */
  ACCOUNT("account"),
  /** A value this SDK version does not know yet. */
  UNKNOWN("unknown");

  private final String value;

  OtpRateLimitScope(String value) {
    this.value = value;
  }

  /**
   * The value used by the API.
   *
   * @return the API value, e.g. {@code "phone"}
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
  public static OtpRateLimitScope fromValue(String value) {
    for (OtpRateLimitScope candidate : values()) {
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

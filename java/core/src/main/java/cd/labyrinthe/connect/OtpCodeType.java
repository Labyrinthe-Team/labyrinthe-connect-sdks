package cd.labyrinthe.connect;

/**
 * Alphabet of an OTP code ({@link OtpSendResult#codeType()}), to choose the input field (numeric
 * keypad or text).
 *
 * <p>{@link #UNKNOWN} is never sent by the API: the SDK uses it for a value added to the API after
 * this SDK version, or when the API (older than 1.2.0) does not send the field.
 */
public enum OtpCodeType {
  /** Digits 0-9. */
  NUMERIC("numeric"),
  /**
   * Upper-case letters and digits without ambiguous characters ({@code
   * ABCDEFGHJKMNPQRSTUVWXYZ23456789}); the input is case-insensitive.
   */
  ALPHANUMERIC("alphanumeric"),
  /** A value this SDK version does not know, or no value. */
  UNKNOWN("unknown");

  private final String value;

  OtpCodeType(String value) {
    this.value = value;
  }

  /**
   * The value used by the API.
   *
   * @return the API value, e.g. {@code "numeric"}
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
  public static OtpCodeType fromValue(String value) {
    for (OtpCodeType candidate : values()) {
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

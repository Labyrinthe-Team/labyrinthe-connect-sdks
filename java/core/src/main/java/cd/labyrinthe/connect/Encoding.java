package cd.labyrinthe.connect;

/**
 * Character encoding of an SMS: GSM-7 (160 characters per SMS) or UCS-2 (70 characters).
 *
 * <p>{@link #UNKNOWN} is never sent by the API: the SDK uses it for a value added to the API after
 * this SDK version, so that your code keeps working.
 */
public enum Encoding {
  /** GSM-7: pure ASCII text, 160 characters (153 per segment beyond). */
  GSM7("gsm7"),
  /** UCS-2: any non-ASCII character, 70 characters (67 per segment beyond). */
  UCS2("ucs2"),
  /** A value this SDK version does not know yet. */
  UNKNOWN("unknown");

  private final String value;

  Encoding(String value) {
    this.value = value;
  }

  /**
   * The value used by the API.
   *
   * @return the API value, e.g. {@code "gsm7"}
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
  public static Encoding fromValue(String value) {
    for (Encoding candidate : values()) {
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

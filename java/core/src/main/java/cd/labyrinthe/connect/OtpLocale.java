package cd.labyrinthe.connect;

/** Languages of the standard OTP templates. */
public enum OtpLocale {
  /** French. */
  FR("fr"),
  /** English. */
  EN("en");

  private final String value;

  OtpLocale(String value) {
    this.value = value;
  }

  /**
   * The value used by the API.
   *
   * @return {@code "fr"} or {@code "en"}
   */
  public String value() {
    return value;
  }

  /** Returns the API value. */
  @Override
  public String toString() {
    return value;
  }
}

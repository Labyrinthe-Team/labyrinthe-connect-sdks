package cd.labyrinthe.connect;

import cd.labyrinthe.connect.exception.InvalidArgumentException;
import java.util.Optional;

/**
 * Parameters of {@code otp().send()}.
 *
 * <p>Code length, validity, attempts, Sender ID and default template come from the account's OTP
 * settings (client area, OTP verification).
 */
public final class SendOtpParams {

  private final String phone;
  private final String template;
  private final OtpLocale locale;

  private SendOtpParams(Builder builder) {
    this.phone = Checks.required(builder.phone, "phone");
    if (builder.template != null) {
      if (!builder.template.contains("{code}")) {
        throw new InvalidArgumentException("The OTP template must contain the {code} token.");
      }
      if (builder.template.length() > Limits.MAX_OTP_TEMPLATE_LENGTH) {
        throw new InvalidArgumentException(
            "The OTP template must be at most " + Limits.MAX_OTP_TEMPLATE_LENGTH + " characters.");
      }
    }
    this.template = builder.template;
    this.locale = builder.locale;
  }

  /**
   * A new builder.
   *
   * @return the builder
   */
  public static Builder builder() {
    return new Builder();
  }

  /**
   * Phone number to verify, in any accepted format.
   *
   * @return the number
   */
  public String phone() {
    return phone;
  }

  /**
   * Message template.
   *
   * @return the template, if set
   */
  public Optional<String> template() {
    return Optional.ofNullable(template);
  }

  /**
   * Language of the standard template.
   *
   * @return the locale, if set
   */
  public Optional<OtpLocale> locale() {
    return Optional.ofNullable(locale);
  }

  /** The phone number is masked. */
  @Override
  public String toString() {
    return "SendOtpParams(phone=" + PhoneNumbers.mask(phone) + ", locale=" + locale + ")";
  }

  /** Builder of {@link SendOtpParams}. */
  public static final class Builder {
    private String phone;
    private String template;
    private OtpLocale locale;

    private Builder() {}

    /**
     * Phone number to verify. Required.
     *
     * @param phone number in any accepted format
     * @return this builder
     */
    public Builder phone(String phone) {
      this.phone = phone;
      return this;
    }

    /**
     * Message template; must contain {@code {code}}. Optional tokens: {@code {app_name}}, {@code
     * {minutes}}. Defaults to the account's template, then to the standard one.
     *
     * @param template at most {@value Limits#MAX_OTP_TEMPLATE_LENGTH} characters
     * @return this builder
     */
    public Builder template(String template) {
      this.template = template;
      return this;
    }

    /**
     * Language of the standard template; defaults to the account's OTP settings.
     *
     * @param locale the language
     * @return this builder
     */
    public Builder locale(OtpLocale locale) {
      this.locale = locale;
      return this;
    }

    /**
     * Builds the parameters.
     *
     * @return the parameters
     * @throws InvalidArgumentException if a value is invalid
     */
    public SendOtpParams build() {
      return new SendOtpParams(this);
    }
  }
}

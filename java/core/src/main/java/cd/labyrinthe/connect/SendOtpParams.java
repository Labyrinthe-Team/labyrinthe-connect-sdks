package cd.labyrinthe.connect;

import cd.labyrinthe.connect.exception.InvalidArgumentException;
import java.util.Locale;
import java.util.Optional;
import java.util.regex.Pattern;

/**
 * Parameters of {@code otp().send()}.
 *
 * <p>Code length, alphabet, validity, attempts, Sender ID and default template come from the
 * account's OTP settings (client area, OTP verification).
 */
public final class SendOtpParams {

  /** Pattern of {@code web_otp_domain} (host name of at least two labels), from the spec. */
  public static final String WEB_OTP_DOMAIN_PATTERN =
      "^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\\.)+"
          + "[A-Za-z]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$";

  private static final Pattern WEB_OTP_DOMAIN = Pattern.compile(WEB_OTP_DOMAIN_PATTERN);

  private final String phone;
  private final String template;
  private final OtpLocale locale;
  private final String webOtpDomain;
  private final boolean stripAccents;
  private final String idempotencyKey;

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
    if (builder.webOtpDomain != null
        && (builder.webOtpDomain.length() > Limits.MAX_WEB_OTP_DOMAIN_LENGTH
            || !WEB_OTP_DOMAIN.matcher(builder.webOtpDomain).matches())) {
      throw new InvalidArgumentException(
          "webOtpDomain must be a host name of at least two labels, without scheme, port or path"
              + " (e.g. app.example.com).");
    }
    // Mis en minuscules comme par l'API : la même valeur à chaque relance.
    this.webOtpDomain =
        builder.webOtpDomain == null ? null : builder.webOtpDomain.toLowerCase(Locale.ROOT);
    this.stripAccents = builder.stripAccents;
    this.idempotencyKey = Checks.idempotencyKey(builder.idempotencyKey);
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

  /**
   * Web OTP API domain (lower case).
   *
   * @return the domain, if set
   */
  public Optional<String> webOtpDomain() {
    return Optional.ofNullable(webOtpDomain);
  }

  /**
   * Whether accents and typographic characters are replaced by ASCII.
   *
   * @return true when requested
   */
  public boolean stripAccents() {
    return stripAccents;
  }

  /**
   * Your idempotency key.
   *
   * @return the key, if set
   */
  public Optional<String> idempotencyKey() {
    return Optional.ofNullable(idempotencyKey);
  }

  /** The phone number is masked. */
  @Override
  public String toString() {
    return "SendOtpParams(phone="
        + PhoneNumbers.mask(phone)
        + ", locale="
        + locale
        + ", webOtpDomain="
        + webOtpDomain
        + ", stripAccents="
        + stripAccents
        + ")";
  }

  /** Builder of {@link SendOtpParams}. */
  public static final class Builder {
    private String phone;
    private String template;
    private OtpLocale locale;
    private String webOtpDomain;
    private boolean stripAccents;
    private String idempotencyKey;

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
     * Host name of the site that reads the code with the <a
     * href="https://developer.mozilla.org/docs/Web/API/WebOTP_API">Web OTP API</a> (autofill on
     * Chrome and Android): the SMS gets a last line {@code @<domain> #<code>}.
     *
     * @param webOtpDomain e.g. {@code "app.example.com"}: at least two labels, no scheme, port or
     *     path, at most {@value Limits#MAX_WEB_OTP_DOMAIN_LENGTH} characters
     * @return this builder
     */
    public Builder webOtpDomain(String webOtpDomain) {
      this.webOtpDomain = webOtpDomain;
      return this;
    }

    /**
     * Replaces accents and typographic characters by their ASCII equivalent before sending: the
     * message becomes GSM-7, often half the price. The transliteration depends on the server.
     *
     * @param stripAccents true to strip (default false)
     * @return this builder
     */
    public Builder stripAccents(boolean stripAccents) {
      this.stripAccents = stripAccents;
      return this;
    }

    /**
     * Your idempotency key (e.g. derived from the login attempt). A replay within 24 hours returns
     * the same OTP (same {@code otpId}) without a new SMS. When unset, the SDK generates a UUID per
     * call, reused by its automatic retries.
     *
     * @param idempotencyKey 1 to {@value Limits#MAX_IDEMPOTENCY_KEY_LENGTH} characters
     * @return this builder
     */
    public Builder idempotencyKey(String idempotencyKey) {
      this.idempotencyKey = idempotencyKey;
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

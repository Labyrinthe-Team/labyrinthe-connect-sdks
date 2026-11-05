package cd.labyrinthe.connect;

import cd.labyrinthe.connect.exception.InvalidArgumentException;
import java.util.Optional;
import java.util.regex.Pattern;

/**
 * Phone number helpers, consistent with the API's own normalisation.
 *
 * <p>The API expects international numbers without "+" (e.g. {@code "243812345678"}). Accepted
 * input formats, with the default country code 243 (DR Congo):
 *
 * <ul>
 *   <li>{@code "+243 81 234 5678"}, {@code "00243812345678"} → {@code "243812345678"} (explicit
 *       international prefix kept)
 *   <li>{@code "243812345678"} → {@code "243812345678"}
 *   <li>{@code "0812345678"}, {@code "812345678"} → {@code "243812345678"} (local format, default
 *       country code added)
 *   <li>{@code "+33 6 12 34 56 78"} → {@code "33612345678"} (other countries are never blocked)
 * </ul>
 *
 * <p>Validation is deliberately light (8 to 15 digits, E.164): the API remains the authority on
 * whether a number can receive SMS.
 */
public final class PhoneNumbers {

  /** Default country code added to local numbers (DR Congo): {@value}. */
  public static final String DEFAULT_COUNTRY_CODE = "243";

  private static final Pattern NON_DIGITS = Pattern.compile("[^0-9]");
  private static final Pattern COUNTRY_CODE = Pattern.compile("[1-9][0-9]{0,2}");

  private PhoneNumbers() {}

  /**
   * Normalises a number with the default country code 243.
   *
   * @param raw number in any accepted format
   * @return the international number without "+", or empty when it cannot be a phone number
   */
  public static Optional<String> normalize(String raw) {
    return normalize(raw, DEFAULT_COUNTRY_CODE);
  }

  /**
   * Normalises a number.
   *
   * @param raw number in any accepted format
   * @param defaultCountryCode country code added to local numbers, 1 to 3 digits without "+"
   * @return the international number without "+", or empty when it cannot be a phone number
   * @throws InvalidArgumentException if {@code defaultCountryCode} is invalid
   */
  public static Optional<String> normalize(String raw, String defaultCountryCode) {
    assertCountryCode(defaultCountryCode);
    String value = raw == null ? "" : raw.trim();
    if (value.isEmpty()) {
      return Optional.empty();
    }
    boolean explicitInternational = value.startsWith("+") || value.startsWith("00");
    String digits = NON_DIGITS.matcher(value).replaceAll("");
    if (explicitInternational) {
      if (value.startsWith("00")) {
        digits = digits.substring(2);
      }
    } else if (!(digits.startsWith(defaultCountryCode) && digits.length() > 10)) {
      // Format local : on retire les « 0 » de tête et on ajoute l'indicatif par défaut.
      digits = defaultCountryCode + stripLeadingZeros(digits);
    }
    return digits.length() >= 8 && digits.length() <= 15 ? Optional.of(digits) : Optional.empty();
  }

  /**
   * Whether a number can be normalised (with the default country code 243).
   *
   * @param raw number in any format
   * @return true when {@link #normalize(String)} returns a number
   */
  public static boolean isValid(String raw) {
    return normalize(raw).isPresent();
  }

  /**
   * Masks a number for logs: {@code "243812345678"} → {@code "24381*****78"}.
   *
   * @param phone number in any format
   * @return the masked digits
   */
  public static String mask(String phone) {
    String digits = phone == null ? "" : NON_DIGITS.matcher(phone).replaceAll("");
    int length = digits.length();
    if (length <= 4) {
      return repeat('*', length);
    }
    int visibleStart = Math.min(5, Math.max(1, length - 7));
    return digits.substring(0, visibleStart)
        + repeat('*', length - visibleStart - 2)
        + digits.substring(length - 2);
  }

  /**
   * Normalises a number or throws (the number is never part of the message).
   *
   * @param raw number
   * @param defaultCountryCode default country code
   * @param field field name for the message
   * @return the normalised number
   */
  static String normalizeOrThrow(String raw, String defaultCountryCode, String field) {
    return normalize(raw, defaultCountryCode)
        .orElseThrow(
            () ->
                new InvalidArgumentException(
                    "Invalid phone number in \""
                        + field
                        + "\": expected an international number (e.g. 243812345678) or a local"
                        + " number."));
  }

  static void assertCountryCode(String countryCode) {
    if (countryCode == null || !COUNTRY_CODE.matcher(countryCode).matches()) {
      throw new InvalidArgumentException(
          "The default country code must be 1 to 3 digits without \"+\" (e.g. \"243\").");
    }
  }

  private static String stripLeadingZeros(String digits) {
    int i = 0;
    while (i < digits.length() && digits.charAt(i) == '0') {
      i++;
    }
    return digits.substring(i);
  }

  private static String repeat(char c, int count) {
    StringBuilder out = new StringBuilder(count);
    for (int i = 0; i < count; i++) {
      out.append(c);
    }
    return out.toString();
  }
}

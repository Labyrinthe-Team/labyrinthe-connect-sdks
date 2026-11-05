package cd.labyrinthe.connect.exception;

import cd.labyrinthe.connect.OtpRateLimitScope;
import java.util.Map;
import java.util.OptionalLong;

/**
 * 429 {@code OTP_RATE_LIMITED}: hourly cap reached for this phone number or the whole account.
 * Never retried.
 */
public class OtpRateLimitedException extends RateLimitException {

  private static final long serialVersionUID = 1L;

  /**
   * Creates the exception.
   *
   * @param message message
   * @param code API error code
   * @param statusCode HTTP status
   * @param details {@code error.details} ({@code scope}, {@code limit})
   * @param headers response headers
   */
  public OtpRateLimitedException(
      String message,
      String code,
      int statusCode,
      Map<String, ?> details,
      Map<String, String> headers) {
    super(message, code, statusCode, details, headers);
  }

  /**
   * Whether the cap applies to the phone number or to the whole account.
   *
   * @return the scope ({@link OtpRateLimitScope#UNKNOWN} when absent or unknown)
   */
  public OtpRateLimitScope scope() {
    Object scope = details().get("scope");
    return OtpRateLimitScope.fromValue(scope instanceof String ? (String) scope : null);
  }

  /**
   * The hourly cap that was reached.
   *
   * @return the cap, if given
   */
  public OptionalLong limit() {
    Long value = detailLong("limit");
    return value == null ? OptionalLong.empty() : OptionalLong.of(value);
  }
}

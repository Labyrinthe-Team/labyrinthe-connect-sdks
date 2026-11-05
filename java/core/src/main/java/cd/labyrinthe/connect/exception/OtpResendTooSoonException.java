package cd.labyrinthe.connect.exception;

import java.util.Map;

/**
 * 429 {@code OTP_RESEND_TOO_SOON}: a code was sent to this number too recently. Never retried: show
 * a countdown of {@link #retryAfter()}.
 */
public class OtpResendTooSoonException extends RateLimitException {

  private static final long serialVersionUID = 1L;

  /**
   * Creates the exception.
   *
   * @param message message
   * @param code API error code
   * @param statusCode HTTP status
   * @param details {@code error.details}
   * @param headers response headers
   */
  public OtpResendTooSoonException(
      String message,
      String code,
      int statusCode,
      Map<String, ?> details,
      Map<String, String> headers) {
    super(message, code, statusCode, details, headers);
  }
}

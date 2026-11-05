package cd.labyrinthe.connect.exception;

import java.util.Map;

/**
 * 403: IP address not allowed for this key ({@code FORBIDDEN}) or OTP disabled for the account
 * ({@code OTP_DISABLED_FOR_ACCOUNT}).
 */
public class PermissionException extends ApiException {

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
  public PermissionException(
      String message,
      String code,
      int statusCode,
      Map<String, ?> details,
      Map<String, String> headers) {
    super(message, code, statusCode, details, headers);
  }
}

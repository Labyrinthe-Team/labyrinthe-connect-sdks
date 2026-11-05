package cd.labyrinthe.connect.exception;

import java.util.Map;

/** 5xx, including 503 {@code OTP_SERVICE_DISABLED}. */
public class ServiceUnavailableException extends ApiException {

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
  public ServiceUnavailableException(
      String message,
      String code,
      int statusCode,
      Map<String, ?> details,
      Map<String, String> headers) {
    super(message, code, statusCode, details, headers);
  }
}

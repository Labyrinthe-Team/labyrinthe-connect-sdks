package cd.labyrinthe.connect.exception;

import java.util.Map;

/** 404 {@code NOT_FOUND}: unknown message or {@code otp_id} (or one of another account). */
public class NotFoundException extends ApiException {

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
  public NotFoundException(
      String message,
      String code,
      int statusCode,
      Map<String, ?> details,
      Map<String, String> headers) {
    super(message, code, statusCode, details, headers);
  }
}

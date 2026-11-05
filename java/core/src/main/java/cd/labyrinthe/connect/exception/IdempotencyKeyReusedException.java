package cd.labyrinthe.connect.exception;

import java.util.Map;

/**
 * 409 {@code IDEMPOTENCY_KEY_REUSED}: the same {@code Idempotency-Key} was used for a different
 * request.
 */
public class IdempotencyKeyReusedException extends ApiException {

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
  public IdempotencyKeyReusedException(
      String message,
      String code,
      int statusCode,
      Map<String, ?> details,
      Map<String, String> headers) {
    super(message, code, statusCode, details, headers);
  }
}

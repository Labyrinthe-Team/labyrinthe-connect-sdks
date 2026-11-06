package cd.labyrinthe.connect.exception;

import java.util.Map;

/**
 * 409 {@code IDEMPOTENCY_KEY_UNRESOLVED}: the original request with this {@code Idempotency-Key}
 * failed unexpectedly and its outcome is unknown (an SMS may have been sent).
 *
 * <p>Never retried by the SDK, and must never be retried automatically: check first (for example
 * with {@code sms().list()} filtered by {@code reference}, or with the user), then send again with
 * a NEW idempotency key if needed.
 */
public class IdempotencyKeyUnresolvedException extends ApiException {

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
  public IdempotencyKeyUnresolvedException(
      String message,
      String code,
      int statusCode,
      Map<String, ?> details,
      Map<String, String> headers) {
    super(message, code, statusCode, details, headers);
  }
}

package cd.labyrinthe.connect.exception;

import cd.labyrinthe.connect.internal.RetryAfter;
import java.time.Duration;
import java.util.Map;
import java.util.Optional;

/**
 * 409 {@code IDEMPOTENCY_REQUEST_IN_PROGRESS}: the original request with this {@code
 * Idempotency-Key} is still being processed.
 *
 * <p>The SDK already retried with the same key after {@link #retryAfter()} (within {@code
 * maxRetries} and {@code maxRetryWait}); when you get this exception, retry later with the same
 * key: it is safe, nothing is sent twice.
 */
public class IdempotencyRequestInProgressException extends ApiException {

  private static final long serialVersionUID = 1L;

  /**
   * Creates the exception.
   *
   * @param message message
   * @param code API error code
   * @param statusCode HTTP status
   * @param details {@code error.details} ({@code retry_after_seconds})
   * @param headers response headers ({@code Retry-After})
   */
  public IdempotencyRequestInProgressException(
      String message,
      String code,
      int statusCode,
      Map<String, ?> details,
      Map<String, String> headers) {
    super(message, code, statusCode, details, headers);
  }

  /**
   * How long to wait before retrying with the same key, from {@code Retry-After} or {@code
   * details.retry_after_seconds}.
   *
   * @return the delay, if known
   */
  public Optional<Duration> retryAfter() {
    return RetryAfter.of(headers(), details());
  }
}

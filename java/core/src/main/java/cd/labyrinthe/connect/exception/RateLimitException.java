package cd.labyrinthe.connect.exception;

import cd.labyrinthe.connect.internal.RetryAfter;
import java.time.Duration;
import java.util.Map;
import java.util.Optional;

/**
 * 429: too many requests.
 *
 * <p>The SDK already retried {@code TOO_MANY_REQUESTS} when it was safe to do so; when you get this
 * exception, wait {@link #retryAfter()}.
 */
public class RateLimitException extends ApiException {

  private static final long serialVersionUID = 1L;

  /**
   * Creates the exception.
   *
   * @param message message
   * @param code API error code
   * @param statusCode HTTP status
   * @param details {@code error.details}
   * @param headers response headers ({@code Retry-After})
   */
  public RateLimitException(
      String message,
      String code,
      int statusCode,
      Map<String, ?> details,
      Map<String, String> headers) {
    super(message, code, statusCode, details, headers);
  }

  /**
   * How long to wait, from {@code Retry-After} (seconds or HTTP date) or {@code
   * details.retry_after_seconds}.
   *
   * @return the delay, if known
   */
  public Optional<Duration> retryAfter() {
    return RetryAfter.of(headers(), details());
  }
}

package cd.labyrinthe.connect.exception;

import cd.labyrinthe.connect.internal.JsonReader;
import cd.labyrinthe.connect.internal.RetryAfter;
import java.time.Duration;
import java.time.Instant;
import java.util.Collections;
import java.util.Map;
import java.util.Optional;

/**
 * 503 {@code FEATURE_DISABLED} (or the legacy {@code OTP_SERVICE_DISABLED} of {@code POST
 * /otp/send}): a platform administrator temporarily shut down the whole API or one function.
 * Nothing was done (no SMS sent, no credit used).
 *
 * <p>Never retried blindly: the SDK retries only when the API announces a scheduled re-enable
 * ({@code Retry-After}) within {@code maxRetryWait}, waiting exactly that delay. When you get this
 * exception, retry after {@link #retryAfter()} if present, otherwise treat the call as a temporary
 * failure (queue it, tell the user).
 */
public class FeatureDisabledException extends ServiceUnavailableException {

  private static final long serialVersionUID = 1L;

  /**
   * Creates the exception.
   *
   * @param message message
   * @param code API error code
   * @param statusCode HTTP status
   * @param details {@code error.details} ({@code feature}, {@code blocked_by}, {@code message},
   *     {@code until})
   * @param headers response headers ({@code Retry-After})
   */
  public FeatureDisabledException(
      String message,
      String code,
      int statusCode,
      Map<String, ?> details,
      Map<String, String> headers) {
    super(message, code, statusCode, details, headers);
  }

  /**
   * Feature requested by the call ({@code api}, {@code api.sms_send}, {@code api.otp_send}, {@code
   * api.otp_verify}, {@code otp}…; open list).
   *
   * @return the feature, if given
   */
  public Optional<String> feature() {
    return detailString("feature");
  }

  /**
   * Feature actually shut down: {@link #feature()} itself or a parent ({@code api} when the whole
   * API is shut down).
   *
   * @return the blocking feature, if given
   */
  public Optional<String> blockedBy() {
    return detailString("blocked_by");
  }

  /**
   * Message written by the administrator ({@code details.message}); {@link #getMessage()} repeats
   * it, or gives a generic text.
   *
   * @return the administrator's message, if any
   */
  public Optional<String> adminMessage() {
    return detailString("message");
  }

  /**
   * Scheduled automatic re-enable ({@code details.until}).
   *
   * @return the instant, if known and readable
   */
  public Optional<Instant> until() {
    Object value = details().get("until");
    return value instanceof String
        ? Optional.ofNullable(JsonReader.parseInstant((String) value))
        : Optional.empty();
  }

  /**
   * Time left before the scheduled re-enable, from the {@code Retry-After} header (present only
   * when {@link #until()} is known).
   *
   * @return the delay, if known
   */
  public Optional<Duration> retryAfter() {
    return RetryAfter.of(headers(), Collections.emptyMap());
  }

  private Optional<String> detailString(String key) {
    Object value = details().get(key);
    return value instanceof String && !((String) value).isEmpty()
        ? Optional.of((String) value)
        : Optional.empty();
  }
}

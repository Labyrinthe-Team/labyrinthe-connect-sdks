package cd.labyrinthe.connect.exception;

import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.Map;

/**
 * Base class of every exception thrown by the SDK: catch it to handle any failure in one place.
 *
 * <p>Every exception carries a stable {@link #code()} (switch on it, or on the class, never on the
 * message: API messages are in French, for humans), the HTTP {@link #statusCode()} ({@code 0} when
 * no response was received) and the error {@link #details()}.
 *
 * <p>Exceptions never contain the API key, OTP codes or full phone numbers, and never chain the
 * underlying {@code java.net.http} exception.
 */
public class LabyrintheException extends RuntimeException {

  private static final long serialVersionUID = 1L;

  private final String code;
  private final int statusCode;
  // transient : les détails JSON ne sont pas sérialisables en Java (Map d'interface).
  private final transient Map<String, Object> details;

  /**
   * Creates an exception.
   *
   * @param message human-readable message
   * @param code stable machine-readable code, e.g. {@code "INSUFFICIENT_CREDITS"}
   * @param statusCode HTTP status, or {@code 0} without a response
   * @param details error details ({@code null} for none)
   */
  public LabyrintheException(String message, String code, int statusCode, Map<String, ?> details) {
    super(message, null, true, true);
    this.code = code;
    this.statusCode = statusCode;
    this.details =
        details == null || details.isEmpty()
            ? Collections.emptyMap()
            : Collections.unmodifiableMap(new LinkedHashMap<>(details));
  }

  /**
   * Stable machine-readable code: an API error code ({@code "VALIDATION_ERROR"}…) or an SDK code
   * ({@code "CONNECTION_ERROR"}, {@code "TIMEOUT"}, {@code "INVALID_ARGUMENT"}…).
   *
   * @return the code
   */
  public String code() {
    return code;
  }

  /**
   * HTTP status of the response.
   *
   * @return the status, or {@code 0} when no response was received (network error, timeout, invalid
   *     argument, webhook verification…)
   */
  public int statusCode() {
    return statusCode;
  }

  /**
   * Error details, depending on the code (empty when there are none). Values are decoded JSON:
   * {@code Map}, {@code List}, {@code String}, {@code Long}, {@code Double}, {@code Boolean}.
   *
   * @return unmodifiable details
   */
  public Map<String, Object> details() {
    return details == null ? Collections.emptyMap() : details;
  }

  @Override
  public String toString() {
    return getClass().getSimpleName()
        + "(code="
        + code
        + ", statusCode="
        + statusCode
        + ", message="
        + getMessage()
        + ")";
  }
}

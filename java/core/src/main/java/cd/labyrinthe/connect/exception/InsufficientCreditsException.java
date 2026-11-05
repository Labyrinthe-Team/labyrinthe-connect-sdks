package cd.labyrinthe.connect.exception;

import java.util.Map;
import java.util.OptionalLong;

/**
 * 409 {@code INSUFFICIENT_CREDITS}: the balance does not cover the whole send. Nothing was sent.
 */
public class InsufficientCreditsException extends ApiException {

  private static final long serialVersionUID = 1L;

  /**
   * Creates the exception.
   *
   * @param message message
   * @param code API error code
   * @param statusCode HTTP status
   * @param details {@code error.details} ({@code available}, {@code required})
   * @param headers response headers
   */
  public InsufficientCreditsException(
      String message,
      String code,
      int statusCode,
      Map<String, ?> details,
      Map<String, String> headers) {
    super(message, code, statusCode, details, headers);
  }

  /**
   * Credits available on the account.
   *
   * @return the credits, if given
   */
  public OptionalLong available() {
    Long value = detailLong("available");
    return value == null ? OptionalLong.empty() : OptionalLong.of(value);
  }

  /**
   * Credits needed for this send.
   *
   * @return the credits, if given
   */
  public OptionalLong required() {
    Long value = detailLong("required");
    return value == null ? OptionalLong.empty() : OptionalLong.of(value);
  }
}

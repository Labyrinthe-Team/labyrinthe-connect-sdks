package cd.labyrinthe.connect.exception;

/**
 * The API could not be reached (DNS, TLS, connection refused or reset...).
 *
 * <p>For {@code otp().send()} and {@code otp().verify()} the request may or may not have been
 * processed: the SDK never retries them after a network error.
 */
public class ApiConnectionException extends LabyrintheException {

  private static final long serialVersionUID = 1L;

  /** Default code of this exception: {@value}. */
  public static final String CODE = "CONNECTION_ERROR";

  /**
   * Creates the exception with the {@value #CODE} code.
   *
   * @param message message (never contains secrets, codes or full phone numbers)
   */
  public ApiConnectionException(String message) {
    this(message, CODE);
  }

  /**
   * Creates the exception with another code (subclasses).
   *
   * @param message message
   * @param code code
   */
  protected ApiConnectionException(String message, String code) {
    super(message, code, 0, null);
  }
}

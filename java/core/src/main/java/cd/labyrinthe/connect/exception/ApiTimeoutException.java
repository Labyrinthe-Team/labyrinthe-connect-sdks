package cd.labyrinthe.connect.exception;

/** An attempt took longer than the configured timeout. */
public class ApiTimeoutException extends ApiConnectionException {

  private static final long serialVersionUID = 1L;

  /** Code of this exception: {@value}. */
  public static final String CODE = "TIMEOUT";

  /**
   * Creates the exception.
   *
   * @param message message
   */
  public ApiTimeoutException(String message) {
    super(message, CODE);
  }
}

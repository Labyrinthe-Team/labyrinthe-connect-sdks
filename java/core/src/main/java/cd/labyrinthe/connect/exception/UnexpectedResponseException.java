package cd.labyrinthe.connect.exception;

/** A successful response that the SDK cannot read (not JSON, missing field...). */
public class UnexpectedResponseException extends LabyrintheException {

  private static final long serialVersionUID = 1L;

  /** Code of this exception: {@value}. */
  public static final String CODE = "UNEXPECTED_RESPONSE";

  /**
   * Creates the exception.
   *
   * @param message message
   */
  public UnexpectedResponseException(String message) {
    this(message, 0);
  }

  /**
   * Creates the exception.
   *
   * @param message message
   * @param statusCode HTTP status of the response
   */
  public UnexpectedResponseException(String message, int statusCode) {
    super(message, CODE, statusCode, null);
  }
}

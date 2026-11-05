package cd.labyrinthe.connect.exception;

/** Invalid input detected by the SDK, before any network call. */
public class InvalidArgumentException extends LabyrintheException {

  private static final long serialVersionUID = 1L;

  /** Default code of this exception: {@value}. */
  public static final String CODE = "INVALID_ARGUMENT";

  /**
   * Creates the exception with the {@value #CODE} code.
   *
   * @param message message (never contains secrets, codes or full phone numbers)
   */
  public InvalidArgumentException(String message) {
    this(message, CODE);
  }

  /**
   * Creates the exception with another code (subclasses).
   *
   * @param message message
   * @param code code
   */
  protected InvalidArgumentException(String message, String code) {
    super(message, code, 0, null);
  }
}

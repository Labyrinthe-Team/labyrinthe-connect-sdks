package cd.labyrinthe.connect.exception;

/**
 * A webhook failed verification (missing or wrong signature, timestamp out of tolerance). Answer
 * {@code 400}.
 */
public class SignatureVerificationException extends LabyrintheException {

  private static final long serialVersionUID = 1L;

  /** Default code of this exception: {@value}. */
  public static final String CODE = "SIGNATURE_VERIFICATION_FAILED";

  /**
   * Creates the exception with the {@value #CODE} code.
   *
   * @param message message (never contains secrets, codes or full phone numbers)
   */
  public SignatureVerificationException(String message) {
    this(message, CODE);
  }

  /**
   * Creates the exception with another code (subclasses).
   *
   * @param message message
   * @param code code
   */
  protected SignatureVerificationException(String message, String code) {
    super(message, code, 0, null);
  }
}

package cd.labyrinthe.connect.exception;

/** A correctly signed webhook body is not a valid event envelope. Answer {@code 400}. */
public class InvalidWebhookPayloadException extends LabyrintheException {

  private static final long serialVersionUID = 1L;

  /** Default code of this exception: {@value}. */
  public static final String CODE = "INVALID_WEBHOOK_PAYLOAD";

  /**
   * Creates the exception with the {@value #CODE} code.
   *
   * @param message message (never contains secrets, codes or full phone numbers)
   */
  public InvalidWebhookPayloadException(String message) {
    this(message, CODE);
  }

  /**
   * Creates the exception with another code (subclasses).
   *
   * @param message message
   * @param code code
   */
  protected InvalidWebhookPayloadException(String message, String code) {
    super(message, code, 0, null);
  }
}

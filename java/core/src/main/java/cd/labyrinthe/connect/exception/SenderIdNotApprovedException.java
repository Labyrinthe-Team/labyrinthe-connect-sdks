package cd.labyrinthe.connect.exception;

import java.util.Map;

/**
 * 422 {@code SENDER_ID_NOT_APPROVED}: {@code sender} is not an approved Sender ID of the account.
 * Nothing was sent.
 */
public class SenderIdNotApprovedException extends ApiException {

  private static final long serialVersionUID = 1L;

  /**
   * Creates the exception.
   *
   * @param message message
   * @param code API error code
   * @param statusCode HTTP status
   * @param details {@code error.details}
   * @param headers response headers
   */
  public SenderIdNotApprovedException(
      String message,
      String code,
      int statusCode,
      Map<String, ?> details,
      Map<String, String> headers) {
    super(message, code, statusCode, details, headers);
  }
}

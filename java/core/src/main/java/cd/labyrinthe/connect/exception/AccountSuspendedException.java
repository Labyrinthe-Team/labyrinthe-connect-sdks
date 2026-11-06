package cd.labyrinthe.connect.exception;

import java.util.Map;

/**
 * 403 {@code ACCOUNT_SUSPENDED}: the account was suspended by the platform. The whole API is closed
 * until it is reactivated: contact Labyrinthe Connect, retrying does not help.
 */
public class AccountSuspendedException extends PermissionException {

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
  public AccountSuspendedException(
      String message,
      String code,
      int statusCode,
      Map<String, ?> details,
      Map<String, String> headers) {
    super(message, code, statusCode, details, headers);
  }
}

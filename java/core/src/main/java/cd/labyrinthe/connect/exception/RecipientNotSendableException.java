package cd.labyrinthe.connect.exception;

import cd.labyrinthe.connect.RejectedRecipient;
import cd.labyrinthe.connect.RejectionReason;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Map;

/**
 * 422 {@code RECIPIENT_NOT_SENDABLE}: at least one recipient is invalid, blacklisted or opted out.
 *
 * <p>Sending is all-or-nothing: <b>nothing was sent</b> and no credit was used. Remove the {@link
 * #rejected()} numbers and send again.
 */
public class RecipientNotSendableException extends ApiException {

  private static final long serialVersionUID = 1L;

  /**
   * Creates the exception.
   *
   * @param message message
   * @param code API error code
   * @param statusCode HTTP status
   * @param details {@code error.details} ({@code reason}, {@code rejected})
   * @param headers response headers
   */
  public RecipientNotSendableException(
      String message,
      String code,
      int statusCode,
      Map<String, ?> details,
      Map<String, String> headers) {
    super(message, code, statusCode, details, headers);
  }

  /**
   * Reason of the first rejection.
   *
   * @return the reason ({@link RejectionReason#UNKNOWN} when absent or unknown)
   */
  public RejectionReason reason() {
    return RejectionReason.fromValue(stringOrNull(details().get("reason")));
  }

  /**
   * Every rejected recipient ({@code sms().send} / {@code sms().sendMany}). Empty for OTP sends.
   *
   * @return unmodifiable list
   */
  public List<RejectedRecipient> rejected() {
    Object items = details().get("rejected");
    if (!(items instanceof List)) {
      return Collections.emptyList();
    }
    List<RejectedRecipient> rejected = new ArrayList<>();
    for (Object item : (List<?>) items) {
      if (item instanceof Map) {
        Object recipient = ((Map<?, ?>) item).get("recipient");
        if (recipient instanceof String) {
          rejected.add(
              new RejectedRecipient(
                  (String) recipient,
                  RejectionReason.fromValue(stringOrNull(((Map<?, ?>) item).get("reason")))));
        }
      }
    }
    return Collections.unmodifiableList(rejected);
  }

  private static String stringOrNull(Object value) {
    return value instanceof String ? (String) value : null;
  }
}

package cd.labyrinthe.connect.exception;

import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/** 422 {@code VALIDATION_ERROR}: one or more fields are missing or invalid. */
public class ValidationException extends ApiException {

  private static final long serialVersionUID = 1L;

  /**
   * Creates the exception.
   *
   * @param message message
   * @param code API error code
   * @param statusCode HTTP status
   * @param details {@code error.details}: messages per field
   * @param headers response headers
   */
  public ValidationException(
      String message,
      String code,
      int statusCode,
      Map<String, ?> details,
      Map<String, String> headers) {
    super(message, code, statusCode, details, headers);
  }

  /**
   * Error messages per field, e.g. {@code {"body": ["Le champ body est obligatoire."]}}.
   *
   * @return unmodifiable messages per field
   */
  public Map<String, List<String>> fieldErrors() {
    Map<String, List<String>> errors = new LinkedHashMap<>();
    for (Map.Entry<String, Object> entry : details().entrySet()) {
      Object messages = entry.getValue();
      List<String> list = new ArrayList<>();
      if (messages instanceof List) {
        for (Object item : (List<?>) messages) {
          if (item instanceof String || item instanceof Number || item instanceof Boolean) {
            list.add(String.valueOf(item));
          }
        }
      } else if (messages != null) {
        list.add(String.valueOf(messages));
      }
      errors.put(entry.getKey(), Collections.unmodifiableList(list));
    }
    return Collections.unmodifiableMap(errors);
  }
}

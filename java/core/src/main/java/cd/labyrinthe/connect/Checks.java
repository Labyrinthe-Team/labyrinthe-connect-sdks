package cd.labyrinthe.connect;

import cd.labyrinthe.connect.exception.InvalidArgumentException;

/** Contrôles communs des paramètres. Usage interne. */
final class Checks {

  private Checks() {}

  static String body(String body) {
    if (body == null || body.isEmpty()) {
      throw new InvalidArgumentException("The SMS body cannot be empty.");
    }
    if (body.length() > Limits.MAX_BODY_LENGTH) {
      throw new InvalidArgumentException(
          "The SMS body must be at most " + Limits.MAX_BODY_LENGTH + " characters.");
    }
    return body;
  }

  static String sender(String sender) {
    if (sender != null && (sender.isEmpty() || sender.length() > Limits.MAX_SENDER_LENGTH)) {
      throw new InvalidArgumentException(
          "sender must be 1 to " + Limits.MAX_SENDER_LENGTH + " characters.");
    }
    return sender;
  }

  static String reference(String reference) {
    if (reference != null && reference.length() > Limits.MAX_REFERENCE_LENGTH) {
      throw new InvalidArgumentException(
          "reference must be at most " + Limits.MAX_REFERENCE_LENGTH + " characters.");
    }
    return reference;
  }

  static String idempotencyKey(String key) {
    if (key != null && (key.isEmpty() || key.length() > Limits.MAX_IDEMPOTENCY_KEY_LENGTH)) {
      throw new InvalidArgumentException(
          "idempotencyKey must be 1 to " + Limits.MAX_IDEMPOTENCY_KEY_LENGTH + " characters.");
    }
    return key;
  }

  static String required(String value, String name) {
    if (value == null || value.trim().isEmpty()) {
      throw new InvalidArgumentException(name + " cannot be empty.");
    }
    return value;
  }
}

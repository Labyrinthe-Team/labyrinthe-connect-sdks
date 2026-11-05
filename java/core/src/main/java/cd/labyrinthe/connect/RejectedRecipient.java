package cd.labyrinthe.connect;

import java.util.Objects;

/** A refused recipient of an all-or-nothing send. Immutable. */
public final class RejectedRecipient {

  private final String recipient;
  private final RejectionReason reason;

  /**
   * Creates a rejected recipient.
   *
   * @param recipient number as received, or normalised
   * @param reason why it was refused
   */
  public RejectedRecipient(String recipient, RejectionReason reason) {
    this.recipient = Objects.requireNonNull(recipient, "recipient");
    this.reason = Objects.requireNonNull(reason, "reason");
  }

  /**
   * The number, as received or normalised. Personal data: mask it in your logs.
   *
   * @return the number
   */
  public String recipient() {
    return recipient;
  }

  /**
   * Why it was refused.
   *
   * @return the reason
   */
  public RejectionReason reason() {
    return reason;
  }

  @Override
  public boolean equals(Object other) {
    return other instanceof RejectedRecipient
        && ((RejectedRecipient) other).recipient.equals(recipient)
        && ((RejectedRecipient) other).reason == reason;
  }

  @Override
  public int hashCode() {
    return Objects.hash(recipient, reason);
  }

  /** The recipient is masked. */
  @Override
  public String toString() {
    return "RejectedRecipient(recipient="
        + PhoneNumbers.mask(recipient)
        + ", reason="
        + reason
        + ")";
  }
}

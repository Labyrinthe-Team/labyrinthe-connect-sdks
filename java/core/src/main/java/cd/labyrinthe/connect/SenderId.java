package cd.labyrinthe.connect;

import java.time.Instant;
import java.util.Objects;
import java.util.Optional;

/** An approved sender name, usable as {@code sender} when sending. Immutable. */
public final class SenderId {

  private final String id;
  private final String value;
  private final SenderIdStatus status;
  private final String purpose;
  private final String rejectionReason;
  private final boolean isDefault;
  private final Instant createdAt;

  SenderId(
      String id,
      String value,
      SenderIdStatus status,
      String purpose,
      String rejectionReason,
      boolean isDefault,
      Instant createdAt) {
    this.id = Objects.requireNonNull(id, "id");
    this.value = Objects.requireNonNull(value, "value");
    this.status = Objects.requireNonNull(status, "status");
    this.purpose = purpose;
    this.rejectionReason = rejectionReason;
    this.isDefault = isDefault;
    this.createdAt = createdAt;
  }

  /**
   * Identifier (UUID).
   *
   * @return the id
   */
  public String id() {
    return id;
  }

  /**
   * The name shown on the handset, e.g. {@code "MYSHOP"} (11 characters max).
   *
   * @return the name
   */
  public String value() {
    return value;
  }

  /**
   * Validation status ({@link SenderIdStatus#APPROVED} for this endpoint).
   *
   * @return the status
   */
  public SenderIdStatus status() {
    return status;
  }

  /**
   * Declared purpose.
   *
   * @return the purpose, if any
   */
  public Optional<String> purpose() {
    return Optional.ofNullable(purpose);
  }

  /**
   * Reason of a rejection.
   *
   * @return the reason, if any
   */
  public Optional<String> rejectionReason() {
    return Optional.ofNullable(rejectionReason);
  }

  /**
   * Whether this is the default Sender ID of the account.
   *
   * @return true for the default
   */
  public boolean isDefault() {
    return isDefault;
  }

  /**
   * Creation time.
   *
   * @return the time, if given
   */
  public Optional<Instant> createdAt() {
    return Optional.ofNullable(createdAt);
  }

  @Override
  public boolean equals(Object other) {
    if (this == other) {
      return true;
    }
    if (!(other instanceof SenderId)) {
      return false;
    }
    SenderId that = (SenderId) other;
    return isDefault == that.isDefault
        && id.equals(that.id)
        && value.equals(that.value)
        && status == that.status
        && Objects.equals(purpose, that.purpose)
        && Objects.equals(rejectionReason, that.rejectionReason)
        && Objects.equals(createdAt, that.createdAt);
  }

  @Override
  public int hashCode() {
    return Objects.hash(id, value, status, isDefault);
  }

  @Override
  public String toString() {
    return "SenderId(value=" + value + ", status=" + status + ", isDefault=" + isDefault + ")";
  }
}

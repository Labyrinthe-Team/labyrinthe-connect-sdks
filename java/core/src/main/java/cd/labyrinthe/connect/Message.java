package cd.labyrinthe.connect;

import java.time.Instant;
import java.util.Objects;
import java.util.Optional;

/**
 * An SMS, one per recipient. Immutable.
 *
 * <p>The text is deliberately not returned by the API (it may contain an OTP code). {@link
 * #toString()} masks the recipient.
 */
public final class Message {

  private final String id;
  private final String reference;
  private final String recipient;
  private final String sender;
  private final Encoding encoding;
  private final MessageStatus status;
  private final int segments;
  private final long creditsCharged;
  private final String campaignId;
  private final String providerMessageId;
  private final String errorCode;
  private final String errorMessage;
  private final Instant createdAt;
  private final Instant submittedAt;
  private final Instant deliveredAt;

  Message(
      String id,
      String reference,
      String recipient,
      String sender,
      Encoding encoding,
      MessageStatus status,
      int segments,
      long creditsCharged,
      String campaignId,
      String providerMessageId,
      String errorCode,
      String errorMessage,
      Instant createdAt,
      Instant submittedAt,
      Instant deliveredAt) {
    this.id = Objects.requireNonNull(id, "id");
    this.reference = reference;
    this.recipient = Objects.requireNonNull(recipient, "recipient");
    this.sender = sender;
    this.encoding = encoding;
    this.status = Objects.requireNonNull(status, "status");
    this.segments = segments;
    this.creditsCharged = creditsCharged;
    this.campaignId = campaignId;
    this.providerMessageId = providerMessageId;
    this.errorCode = errorCode;
    this.errorMessage = errorMessage;
    this.createdAt = Objects.requireNonNull(createdAt, "createdAt");
    this.submittedAt = submittedAt;
    this.deliveredAt = deliveredAt;
  }

  /**
   * Identifier of the message (UUID).
   *
   * @return the id
   */
  public String id() {
    return id;
  }

  /**
   * Your reference, given at send time.
   *
   * @return the reference, if any
   */
  public Optional<String> reference() {
    return Optional.ofNullable(reference);
  }

  /**
   * Recipient, international format without "+", e.g. {@code "243812345678"}. Personal data: mask
   * it in your logs ({@link PhoneNumbers#mask(String)}).
   *
   * @return the recipient
   */
  public String recipient() {
    return recipient;
  }

  /**
   * Sender shown on the handset.
   *
   * @return the sender, if known
   */
  public Optional<String> sender() {
    return Optional.ofNullable(sender);
  }

  /**
   * Encoding of the text (may be missing for old messages: see BACKEND-TODO n° 9).
   *
   * @return the encoding, if known
   */
  public Optional<Encoding> encoding() {
    return Optional.ofNullable(encoding);
  }

  /**
   * Current status.
   *
   * @return the status
   */
  public MessageStatus status() {
    return status;
  }

  /**
   * Number of SMS segments.
   *
   * @return the segments
   */
  public int segments() {
    return segments;
  }

  /**
   * Credits charged (equal to {@link #segments()}; fictitious with a test key).
   *
   * @return the credits
   */
  public long creditsCharged() {
    return creditsCharged;
  }

  /**
   * Campaign of origin (messages sent from the client area).
   *
   * @return the campaign id, if any
   */
  public Optional<String> campaignId() {
    return Optional.ofNullable(campaignId);
  }

  /**
   * Identifier given by the SMS provider.
   *
   * @return the provider id, if any
   */
  public Optional<String> providerMessageId() {
    return Optional.ofNullable(providerMessageId);
  }

  /**
   * Error code of a failed message.
   *
   * @return the error code, if any
   */
  public Optional<String> errorCode() {
    return Optional.ofNullable(errorCode);
  }

  /**
   * Error message of a failed message.
   *
   * @return the error message, if any
   */
  public Optional<String> errorMessage() {
    return Optional.ofNullable(errorMessage);
  }

  /**
   * Creation time.
   *
   * @return the creation time
   */
  public Instant createdAt() {
    return createdAt;
  }

  /**
   * Time the message was handed over to the provider.
   *
   * @return the time, if submitted
   */
  public Optional<Instant> submittedAt() {
    return Optional.ofNullable(submittedAt);
  }

  /**
   * Delivery receipt time.
   *
   * @return the time, if delivered
   */
  public Optional<Instant> deliveredAt() {
    return Optional.ofNullable(deliveredAt);
  }

  @Override
  public boolean equals(Object other) {
    if (this == other) {
      return true;
    }
    if (!(other instanceof Message)) {
      return false;
    }
    Message that = (Message) other;
    return segments == that.segments
        && creditsCharged == that.creditsCharged
        && id.equals(that.id)
        && Objects.equals(reference, that.reference)
        && recipient.equals(that.recipient)
        && Objects.equals(sender, that.sender)
        && encoding == that.encoding
        && status == that.status
        && Objects.equals(campaignId, that.campaignId)
        && Objects.equals(providerMessageId, that.providerMessageId)
        && Objects.equals(errorCode, that.errorCode)
        && Objects.equals(errorMessage, that.errorMessage)
        && createdAt.equals(that.createdAt)
        && Objects.equals(submittedAt, that.submittedAt)
        && Objects.equals(deliveredAt, that.deliveredAt);
  }

  @Override
  public int hashCode() {
    return Objects.hash(id, recipient, status, segments, createdAt, deliveredAt);
  }

  /** Short description; the recipient is masked. */
  @Override
  public String toString() {
    return "Message(id="
        + id
        + ", recipient="
        + PhoneNumbers.mask(recipient)
        + ", status="
        + status
        + ", segments="
        + segments
        + ", reference="
        + reference
        + ")";
  }
}

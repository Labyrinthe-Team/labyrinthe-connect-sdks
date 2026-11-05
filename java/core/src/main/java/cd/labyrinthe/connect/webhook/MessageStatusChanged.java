package cd.labyrinthe.connect.webhook;

import cd.labyrinthe.connect.MessageStatus;
import cd.labyrinthe.connect.PhoneNumbers;
import java.time.Instant;
import java.util.Map;
import java.util.Objects;
import java.util.Optional;

/** {@code message.status_changed}: the status of a message changed. */
public final class MessageStatusChanged extends WebhookEvent {

  private final Data data;

  MessageStatusChanged(
      String id, String type, Instant timestamp, Map<String, Object> raw, Data data) {
    super(id, type, timestamp, raw);
    this.data = Objects.requireNonNull(data, "data");
  }

  @Override
  public WebhookEventType eventType() {
    return WebhookEventType.MESSAGE_STATUS_CHANGED;
  }

  /**
   * The changed message.
   *
   * @return the data
   */
  public Data data() {
    return data;
  }

  /** Content of a {@code message.status_changed} event. */
  public static final class Data {
    private final String messageId;
    private final String recipient;
    private final MessageStatus status;
    private final String providerMessageId;
    private final String campaignId;

    Data(
        String messageId,
        String recipient,
        MessageStatus status,
        String providerMessageId,
        String campaignId) {
      this.messageId = Objects.requireNonNull(messageId, "messageId");
      this.recipient = Objects.requireNonNull(recipient, "recipient");
      this.status = Objects.requireNonNull(status, "status");
      this.providerMessageId = providerMessageId;
      this.campaignId = campaignId;
    }

    /**
     * Id of the message (as returned by {@code sms().send()}).
     *
     * @return the message id
     */
    public String messageId() {
      return messageId;
    }

    /**
     * Recipient, international format without "+". Personal data: mask it in your logs.
     *
     * @return the recipient
     */
    public String recipient() {
      return recipient;
    }

    /**
     * New status.
     *
     * @return the status
     */
    public MessageStatus status() {
      return status;
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
     * Campaign of origin.
     *
     * @return the campaign id, if any
     */
    public Optional<String> campaignId() {
      return Optional.ofNullable(campaignId);
    }

    /** The recipient is masked. */
    @Override
    public String toString() {
      return "MessageStatusChanged.Data(messageId="
          + messageId
          + ", recipient="
          + PhoneNumbers.mask(recipient)
          + ", status="
          + status
          + ")";
    }
  }
}

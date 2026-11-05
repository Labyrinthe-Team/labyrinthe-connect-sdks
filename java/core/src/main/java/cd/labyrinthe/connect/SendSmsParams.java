package cd.labyrinthe.connect;

import java.util.Optional;

/**
 * Parameters of {@code sms().send()}: one recipient.
 *
 * <pre>{@code
 * SendSmsParams.builder().to("0812345678").body("Your order 1042 is ready.").build()
 * }</pre>
 */
public final class SendSmsParams {

  private final String to;
  private final String body;
  private final String sender;
  private final String reference;
  private final String idempotencyKey;

  private SendSmsParams(Builder builder) {
    this.to = Checks.required(builder.to, "to");
    this.body = Checks.body(builder.body);
    this.sender = Checks.sender(builder.sender);
    this.reference = Checks.reference(builder.reference);
    this.idempotencyKey = Checks.idempotencyKey(builder.idempotencyKey);
  }

  /**
   * A new builder.
   *
   * @return the builder
   */
  public static Builder builder() {
    return new Builder();
  }

  /**
   * Recipient, in any accepted format (normalised by the SDK).
   *
   * @return the recipient
   */
  public String to() {
    return to;
  }

  /**
   * Text of the SMS.
   *
   * @return the body
   */
  public String body() {
    return body;
  }

  /**
   * Approved Sender ID.
   *
   * @return the sender, if set
   */
  public Optional<String> sender() {
    return Optional.ofNullable(sender);
  }

  /**
   * Your reference.
   *
   * @return the reference, if set
   */
  public Optional<String> reference() {
    return Optional.ofNullable(reference);
  }

  /**
   * Your idempotency key.
   *
   * @return the key, if set
   */
  public Optional<String> idempotencyKey() {
    return Optional.ofNullable(idempotencyKey);
  }

  /** The recipient is masked and the body is not shown. */
  @Override
  public String toString() {
    return "SendSmsParams(to=" + PhoneNumbers.mask(to) + ", bodyLength=" + body.length() + ")";
  }

  /** Builder of {@link SendSmsParams}. */
  public static final class Builder {
    private String to;
    private String body;
    private String sender;
    private String reference;
    private String idempotencyKey;

    private Builder() {}

    /**
     * Recipient: {@code +243…}, {@code 00243…}, {@code 243…}, {@code 0812…}, {@code 812…} or any
     * international number. Required.
     *
     * @param to the recipient
     * @return this builder
     */
    public Builder to(String to) {
      this.to = to;
      return this;
    }

    /**
     * Text of the SMS, 1 to {@value Limits#MAX_BODY_LENGTH} characters. Required.
     *
     * @param body the text
     * @return this builder
     */
    public Builder body(String body) {
      this.body = body;
      return this;
    }

    /**
     * An approved Sender ID ({@code client.senderIds().list()}); defaults to the client's default
     * sender, then to the platform sender.
     *
     * @param sender the sender, at most {@value Limits#MAX_SENDER_LENGTH} characters
     * @return this builder
     */
    public Builder sender(String sender) {
      this.sender = sender;
      return this;
    }

    /**
     * Your own reference (order number…), returned in {@link Message#reference()} and filterable.
     *
     * @param reference at most {@value Limits#MAX_REFERENCE_LENGTH} characters
     * @return this builder
     */
    public Builder reference(String reference) {
      this.reference = reference;
      return this;
    }

    /**
     * Your idempotency key, derived from your business event (e.g. {@code "order-1042-ready"}). A
     * replay within 24 hours returns the original messages without sending again. When unset, the
     * SDK generates a UUID per call.
     *
     * @param idempotencyKey 1 to {@value Limits#MAX_IDEMPOTENCY_KEY_LENGTH} characters
     * @return this builder
     */
    public Builder idempotencyKey(String idempotencyKey) {
      this.idempotencyKey = idempotencyKey;
      return this;
    }

    /**
     * Builds the parameters.
     *
     * @return the parameters
     * @throws cd.labyrinthe.connect.exception.InvalidArgumentException if a value is invalid
     */
    public SendSmsParams build() {
      return new SendSmsParams(this);
    }
  }
}

package cd.labyrinthe.connect;

import cd.labyrinthe.connect.exception.InvalidArgumentException;
import java.util.ArrayList;
import java.util.Collection;
import java.util.Collections;
import java.util.List;
import java.util.Optional;

/**
 * Parameters of {@code sms().sendMany()}: the same SMS to up to {@value Limits#MAX_RECIPIENTS}
 * recipients, all or nothing.
 */
public final class SendManySmsParams {

  private final List<String> recipients;
  private final String body;
  private final String sender;
  private final String reference;
  private final String idempotencyKey;
  private final boolean stripAccents;

  private SendManySmsParams(Builder builder) {
    if (builder.recipients.isEmpty()) {
      throw new InvalidArgumentException("At least one recipient is required.");
    }
    if (builder.recipients.size() > Limits.MAX_RECIPIENTS) {
      throw new InvalidArgumentException(
          "At most " + Limits.MAX_RECIPIENTS + " recipients per request.");
    }
    this.recipients = Collections.unmodifiableList(new ArrayList<>(builder.recipients));
    this.body = Checks.body(builder.body);
    this.sender = Checks.sender(builder.sender);
    this.reference = Checks.reference(builder.reference);
    this.idempotencyKey = Checks.idempotencyKey(builder.idempotencyKey);
    this.stripAccents = builder.stripAccents;
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
   * Recipients, in any accepted format (normalised by the SDK).
   *
   * @return unmodifiable list
   */
  public List<String> recipients() {
    return recipients;
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
   * Your reference (shared by every message of the send).
   *
   * @return the reference, if set
   */
  public Optional<String> reference() {
    return Optional.ofNullable(reference);
  }

  /**
   * Whether accents and typographic characters are replaced by ASCII before sending.
   *
   * @return true when requested
   */
  public boolean stripAccents() {
    return stripAccents;
  }

  /**
   * Your idempotency key.
   *
   * @return the key, if set
   */
  public Optional<String> idempotencyKey() {
    return Optional.ofNullable(idempotencyKey);
  }

  /** Neither the recipients nor the body are shown. */
  @Override
  public String toString() {
    return "SendManySmsParams(recipients="
        + recipients.size()
        + ", bodyLength="
        + body.length()
        + ")";
  }

  /** Builder of {@link SendManySmsParams}. */
  public static final class Builder {
    private final List<String> recipients = new ArrayList<>();
    private String body;
    private String sender;
    private String reference;
    private String idempotencyKey;
    private boolean stripAccents;

    private Builder() {}

    /**
     * Replaces the recipients. Required (1 to {@value Limits#MAX_RECIPIENTS}).
     *
     * @param recipients numbers in any accepted format
     * @return this builder
     */
    public Builder recipients(Collection<String> recipients) {
      this.recipients.clear();
      this.recipients.addAll(recipients);
      return this;
    }

    /**
     * Adds a recipient.
     *
     * @param recipient number in any accepted format
     * @return this builder
     */
    public Builder addRecipient(String recipient) {
      this.recipients.add(recipient);
      return this;
    }

    /**
     * Text of the SMS. Required.
     *
     * @param body the text
     * @return this builder
     */
    public Builder body(String body) {
      this.body = body;
      return this;
    }

    /**
     * An approved Sender ID.
     *
     * @param sender the sender
     * @return this builder
     */
    public Builder sender(String sender) {
      this.sender = sender;
      return this;
    }

    /**
     * Your own reference.
     *
     * @param reference the reference
     * @return this builder
     */
    public Builder reference(String reference) {
      this.reference = reference;
      return this;
    }

    /**
     * Your idempotency key (see {@link SendSmsParams.Builder#idempotencyKey(String)}).
     *
     * @param idempotencyKey the key
     * @return this builder
     */
    public Builder idempotencyKey(String idempotencyKey) {
      this.idempotencyKey = idempotencyKey;
      return this;
    }

    /**
     * Replaces accents and typographic characters (quotes, long dashes…) by their ASCII equivalent
     * before sending: the message becomes GSM-7, often half the price. Never applied without this
     * option; the transliteration depends on the server ({@code Café} may become {@code Cafe} or
     * {@code Caf'e}): do not rely on it for an exact text.
     *
     * @param stripAccents true to strip (default false)
     * @return this builder
     */
    public Builder stripAccents(boolean stripAccents) {
      this.stripAccents = stripAccents;
      return this;
    }

    /**
     * Builds the parameters.
     *
     * @return the parameters
     * @throws InvalidArgumentException if a value is invalid
     */
    public SendManySmsParams build() {
      return new SendManySmsParams(this);
    }
  }
}

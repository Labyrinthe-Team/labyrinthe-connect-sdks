package cd.labyrinthe.connect;

import cd.labyrinthe.connect.exception.InvalidArgumentException;
import java.util.ArrayList;
import java.util.Collection;
import java.util.Collections;
import java.util.List;

/**
 * Parameters of {@code sms().estimate()}. Recipients are counted like a send: normalised,
 * duplicates merged; invalid numbers are ignored by the API, not refused.
 */
public final class EstimateSmsParams {

  private final String body;
  private final List<String> recipients;
  private final boolean stripAccents;

  private EstimateSmsParams(Builder builder) {
    this.body = Checks.body(builder.body);
    if (builder.recipients.isEmpty()) {
      throw new InvalidArgumentException("At least one recipient is required.");
    }
    this.recipients = Collections.unmodifiableList(new ArrayList<>(builder.recipients));
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
   * Text to estimate.
   *
   * @return the body
   */
  public String body() {
    return body;
  }

  /**
   * Recipients.
   *
   * @return unmodifiable list
   */
  public List<String> recipients() {
    return recipients;
  }

  /**
   * Whether accents and typographic characters are replaced by ASCII before counting.
   *
   * @return true when requested
   */
  public boolean stripAccents() {
    return stripAccents;
  }

  @Override
  public String toString() {
    return "EstimateSmsParams(recipients="
        + recipients.size()
        + ", bodyLength="
        + body.length()
        + ")";
  }

  /** Builder of {@link EstimateSmsParams}. */
  public static final class Builder {
    private String body;
    private final List<String> recipients = new ArrayList<>();
    private boolean stripAccents;

    private Builder() {}

    /**
     * Text to estimate. Required.
     *
     * @param body the text
     * @return this builder
     */
    public Builder body(String body) {
      this.body = body;
      return this;
    }

    /**
     * Replaces the recipients. At least one is required.
     *
     * @param recipients numbers in any format
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
     * @param recipient number in any format
     * @return this builder
     */
    public Builder addRecipient(String recipient) {
      this.recipients.add(recipient);
      return this;
    }

    /**
     * Replaces accents and typographic characters (quotes, long dashes…) by their ASCII equivalent
     * before counting, as {@code send()} would with the same option. Never applied without this
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
    public EstimateSmsParams build() {
      return new EstimateSmsParams(this);
    }
  }
}

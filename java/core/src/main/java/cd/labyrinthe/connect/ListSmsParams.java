package cd.labyrinthe.connect;

import cd.labyrinthe.connect.exception.InvalidArgumentException;
import java.time.Instant;
import java.time.LocalDate;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.time.format.DateTimeFormatter;
import java.time.temporal.ChronoUnit;
import java.util.Optional;

/**
 * Filters and pagination of {@code sms().list()}. Every filter is optional.
 *
 * <pre>{@code
 * ListSmsParams.builder().status(MessageStatus.FAILED).from(LocalDate.of(2026, 11, 1)).build()
 * }</pre>
 */
public final class ListSmsParams {

  private static final DateTimeFormatter UTC_SECONDS =
      DateTimeFormatter.ofPattern("yyyy-MM-dd'T'HH:mm:ss'Z'").withZone(ZoneOffset.UTC);

  private final MessageStatus status;
  private final String recipient;
  private final String reference;
  private final String from;
  private final String to;
  private final int page;
  private final int perPage;

  private ListSmsParams(Builder builder) {
    if (builder.status == MessageStatus.UNKNOWN) {
      throw new InvalidArgumentException("status cannot be UNKNOWN.");
    }
    if (builder.page < 1) {
      throw new InvalidArgumentException("page must be >= 1.");
    }
    if (builder.perPage < 1 || builder.perPage > Limits.MAX_PER_PAGE) {
      throw new InvalidArgumentException(
          "perPage must be between 1 and " + Limits.MAX_PER_PAGE + ".");
    }
    this.status = builder.status;
    this.recipient = builder.recipient;
    this.reference = Checks.reference(builder.reference);
    this.from = builder.from;
    this.to = builder.to;
    this.page = builder.page;
    this.perPage = builder.perPage;
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
   * Default parameters: first page, {@value Limits#DEFAULT_PER_PAGE} items, no filter.
   *
   * @return the parameters
   */
  public static ListSmsParams defaults() {
    return builder().build();
  }

  /**
   * Status filter.
   *
   * @return the status, if set
   */
  public Optional<MessageStatus> status() {
    return Optional.ofNullable(status);
  }

  /**
   * Recipient filter (any format, normalised by the SDK).
   *
   * @return the recipient, if set
   */
  public Optional<String> recipient() {
    return Optional.ofNullable(recipient);
  }

  /**
   * Reference filter (exact match).
   *
   * @return the reference, if set
   */
  public Optional<String> reference() {
    return Optional.ofNullable(reference);
  }

  /**
   * Start of the period, as sent to the API.
   *
   * @return the ISO 8601 date or date-time, if set
   */
  public Optional<String> from() {
    return Optional.ofNullable(from);
  }

  /**
   * End of the period, as sent to the API.
   *
   * @return the ISO 8601 date or date-time, if set
   */
  public Optional<String> to() {
    return Optional.ofNullable(to);
  }

  /**
   * First page to fetch.
   *
   * @return the page number (from 1)
   */
  public int page() {
    return page;
  }

  /**
   * Page size.
   *
   * @return items per page
   */
  public int perPage() {
    return perPage;
  }

  /** The recipient filter is masked. */
  @Override
  public String toString() {
    return "ListSmsParams(status="
        + status
        + ", recipient="
        + (recipient == null ? null : PhoneNumbers.mask(recipient))
        + ", reference="
        + reference
        + ", from="
        + from
        + ", to="
        + to
        + ", page="
        + page
        + ", perPage="
        + perPage
        + ")";
  }

  /** Builder of {@link ListSmsParams}. */
  public static final class Builder {
    private MessageStatus status;
    private String recipient;
    private String reference;
    private String from;
    private String to;
    private int page = 1;
    private int perPage = Limits.DEFAULT_PER_PAGE;

    private Builder() {}

    /**
     * Only messages with this status.
     *
     * @param status the status
     * @return this builder
     */
    public Builder status(MessageStatus status) {
      this.status = status;
      return this;
    }

    /**
     * Only messages to this number.
     *
     * @param recipient number in any format
     * @return this builder
     */
    public Builder recipient(String recipient) {
      this.recipient = recipient;
      return this;
    }

    /**
     * Only messages sent with this reference (exact match).
     *
     * @param reference the reference
     * @return this builder
     */
    public Builder reference(String reference) {
      this.reference = reference;
      return this;
    }

    /**
     * From this day, included.
     *
     * @param from the first day
     * @return this builder
     */
    public Builder from(LocalDate from) {
      this.from = from == null ? null : from.toString();
      return this;
    }

    /**
     * From this instant, included (sent in UTC, to the second).
     *
     * @param from the start
     * @return this builder
     */
    public Builder from(Instant from) {
      this.from = format(from);
      return this;
    }

    /**
     * From this date-time, included.
     *
     * @param from the start
     * @return this builder
     */
    public Builder from(OffsetDateTime from) {
      return from(from == null ? null : from.toInstant());
    }

    /**
     * Until this day, included (the whole day).
     *
     * @param to the last day
     * @return this builder
     */
    public Builder to(LocalDate to) {
      this.to = to == null ? null : to.toString();
      return this;
    }

    /**
     * Until this instant, included (sent in UTC, to the second).
     *
     * @param to the end
     * @return this builder
     */
    public Builder to(Instant to) {
      this.to = format(to);
      return this;
    }

    /**
     * Until this date-time, included.
     *
     * @param to the end
     * @return this builder
     */
    public Builder to(OffsetDateTime to) {
      return to(to == null ? null : to.toInstant());
    }

    /**
     * First page to fetch (default 1).
     *
     * @param page page number, from 1
     * @return this builder
     */
    public Builder page(int page) {
      this.page = page;
      return this;
    }

    /**
     * Page size (default {@value Limits#DEFAULT_PER_PAGE}, max {@value Limits#MAX_PER_PAGE}).
     *
     * @param perPage items per page
     * @return this builder
     */
    public Builder perPage(int perPage) {
      this.perPage = perPage;
      return this;
    }

    /**
     * Builds the parameters.
     *
     * @return the parameters
     * @throws InvalidArgumentException if a value is invalid
     */
    public ListSmsParams build() {
      return new ListSmsParams(this);
    }

    private static String format(Instant instant) {
      return instant == null ? null : UTC_SECONDS.format(instant.truncatedTo(ChronoUnit.SECONDS));
    }
  }
}

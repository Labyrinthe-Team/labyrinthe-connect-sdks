package cd.labyrinthe.connect;

import java.util.Objects;
import java.util.OptionalLong;

/** Cost of a send, computed by the API without sending anything. Immutable. */
public final class Estimate {

  private final Encoding encoding;
  private final int characters;
  private final int segmentsPerMessage;
  private final int recipientsCount;
  private final long totalCredits;
  private final Long balanceAfter;

  Estimate(
      Encoding encoding,
      int characters,
      int segmentsPerMessage,
      int recipientsCount,
      long totalCredits,
      Long balanceAfter) {
    this.encoding = Objects.requireNonNull(encoding, "encoding");
    this.characters = characters;
    this.segmentsPerMessage = segmentsPerMessage;
    this.recipientsCount = recipientsCount;
    this.totalCredits = totalCredits;
    this.balanceAfter = balanceAfter;
  }

  /**
   * Encoding of the text: any non-ASCII character switches to UCS-2.
   *
   * @return the encoding
   */
  public Encoding encoding() {
    return encoding;
  }

  /**
   * Length of the text.
   *
   * @return the number of characters
   */
  public int characters() {
    return characters;
  }

  /**
   * Segments (credits) per recipient.
   *
   * @return the segments
   */
  public int segmentsPerMessage() {
    return segmentsPerMessage;
  }

  /**
   * Valid recipients only (invalid numbers are ignored, not rejected).
   *
   * @return the count
   */
  public int recipientsCount() {
    return recipientsCount;
  }

  /**
   * Credits needed for the whole send.
   *
   * @return the credits
   */
  public long totalCredits() {
    return totalCredits;
  }

  /**
   * Balance after the send (negative when insufficient).
   *
   * @return the balance, or empty if the account never had credits
   */
  public OptionalLong balanceAfter() {
    return balanceAfter == null ? OptionalLong.empty() : OptionalLong.of(balanceAfter);
  }

  /**
   * Whether the current balance covers the send ({@code balanceAfter >= 0}).
   *
   * @return true when affordable
   */
  public boolean isAffordable() {
    return balanceAfter != null && balanceAfter >= 0;
  }

  @Override
  public boolean equals(Object other) {
    if (this == other) {
      return true;
    }
    if (!(other instanceof Estimate)) {
      return false;
    }
    Estimate that = (Estimate) other;
    return characters == that.characters
        && segmentsPerMessage == that.segmentsPerMessage
        && recipientsCount == that.recipientsCount
        && totalCredits == that.totalCredits
        && encoding == that.encoding
        && Objects.equals(balanceAfter, that.balanceAfter);
  }

  @Override
  public int hashCode() {
    return Objects.hash(
        encoding, characters, segmentsPerMessage, recipientsCount, totalCredits, balanceAfter);
  }

  @Override
  public String toString() {
    return "Estimate(encoding="
        + encoding
        + ", characters="
        + characters
        + ", segmentsPerMessage="
        + segmentsPerMessage
        + ", recipientsCount="
        + recipientsCount
        + ", totalCredits="
        + totalCredits
        + ", balanceAfter="
        + balanceAfter
        + ")";
  }
}

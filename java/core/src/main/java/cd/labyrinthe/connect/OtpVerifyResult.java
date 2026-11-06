package cd.labyrinthe.connect;

import java.util.Objects;
import java.util.Optional;
import java.util.OptionalInt;

/** Result of {@code otp().verify()}. A wrong code is not an exception. Immutable. */
public final class OtpVerifyResult {

  private static final OtpVerifyResult VERIFIED = new OtpVerifyResult(true, null, null);

  private final boolean verified;
  private final OtpFailureReason reason;
  private final Integer attemptsRemaining;

  private OtpVerifyResult(boolean verified, OtpFailureReason reason, Integer attemptsRemaining) {
    this.verified = verified;
    this.reason = reason;
    this.attemptsRemaining = attemptsRemaining;
  }

  /**
   * A successful verification.
   *
   * @return the result
   */
  public static OtpVerifyResult verified() {
    return VERIFIED;
  }

  /**
   * A failed verification, without the number of remaining attempts.
   *
   * @param reason why it failed
   * @return the result
   */
  public static OtpVerifyResult failed(OtpFailureReason reason) {
    return failed(reason, null);
  }

  /**
   * A failed verification.
   *
   * @param reason why it failed
   * @param attemptsRemaining attempts still possible, or {@code null} when unknown
   * @return the result
   */
  public static OtpVerifyResult failed(OtpFailureReason reason, Integer attemptsRemaining) {
    if (attemptsRemaining != null && attemptsRemaining < 0) {
      throw new IllegalArgumentException("attemptsRemaining cannot be negative");
    }
    return new OtpVerifyResult(false, Objects.requireNonNull(reason, "reason"), attemptsRemaining);
  }

  /**
   * Whether the code is correct: log the user in.
   *
   * @return true when verified
   */
  public boolean isVerified() {
    return verified;
  }

  /**
   * Why the verification failed.
   *
   * @return the reason, empty when {@link #isVerified()} is true
   */
  public Optional<OtpFailureReason> reason() {
    return Optional.ofNullable(reason);
  }

  /**
   * Attempts still possible for this code: a number after {@link OtpFailureReason#INVALID_CODE},
   * {@code 0} with {@link OtpFailureReason#MAX_ATTEMPTS_REACHED}, empty when the code is verified,
   * already used or expired (and with an API older than 1.2.0).
   *
   * @return the remaining attempts, if known
   */
  public OptionalInt attemptsRemaining() {
    return attemptsRemaining == null ? OptionalInt.empty() : OptionalInt.of(attemptsRemaining);
  }

  @Override
  public boolean equals(Object other) {
    if (!(other instanceof OtpVerifyResult)) {
      return false;
    }
    OtpVerifyResult that = (OtpVerifyResult) other;
    return that.verified == verified
        && that.reason == reason
        && Objects.equals(that.attemptsRemaining, attemptsRemaining);
  }

  @Override
  public int hashCode() {
    return Objects.hash(verified, reason, attemptsRemaining);
  }

  @Override
  public String toString() {
    if (verified) {
      return "OtpVerifyResult(verified)";
    }
    return "OtpVerifyResult(failed: "
        + reason
        + (attemptsRemaining == null ? "" : ", attemptsRemaining=" + attemptsRemaining)
        + ")";
  }
}

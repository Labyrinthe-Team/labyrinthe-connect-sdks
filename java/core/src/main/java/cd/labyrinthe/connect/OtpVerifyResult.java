package cd.labyrinthe.connect;

import java.util.Objects;
import java.util.Optional;

/** Result of {@code otp().verify()}. A wrong code is not an exception. Immutable. */
public final class OtpVerifyResult {

  private static final OtpVerifyResult VERIFIED = new OtpVerifyResult(true, null);

  private final boolean verified;
  private final OtpFailureReason reason;

  private OtpVerifyResult(boolean verified, OtpFailureReason reason) {
    this.verified = verified;
    this.reason = reason;
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
   * A failed verification.
   *
   * @param reason why it failed
   * @return the result
   */
  public static OtpVerifyResult failed(OtpFailureReason reason) {
    return new OtpVerifyResult(false, Objects.requireNonNull(reason, "reason"));
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

  @Override
  public boolean equals(Object other) {
    return other instanceof OtpVerifyResult
        && ((OtpVerifyResult) other).verified == verified
        && ((OtpVerifyResult) other).reason == reason;
  }

  @Override
  public int hashCode() {
    return Objects.hash(verified, reason);
  }

  @Override
  public String toString() {
    return verified ? "OtpVerifyResult(verified)" : "OtpVerifyResult(failed: " + reason + ")";
  }
}

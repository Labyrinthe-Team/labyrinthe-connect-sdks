package cd.labyrinthe.connect;

import java.time.Instant;
import java.util.Objects;
import java.util.Optional;

/**
 * Result of an OTP send. Immutable.
 *
 * <p>Store {@link #otpId()} server-side (session, cache…) and pass it to {@code otp().verify()}
 * with the code typed by the user.
 */
public final class OtpSendResult {

  private final String otpId;
  private final Instant expiresAt;
  private final int maxAttempts;
  private final String testCode;

  OtpSendResult(String otpId, Instant expiresAt, int maxAttempts, String testCode) {
    this.otpId = Objects.requireNonNull(otpId, "otpId");
    this.expiresAt = Objects.requireNonNull(expiresAt, "expiresAt");
    this.maxAttempts = maxAttempts;
    this.testCode = testCode;
  }

  /**
   * Identifier of this code, to pass to {@code otp().verify()}.
   *
   * @return the OTP id
   */
  public String otpId() {
    return otpId;
  }

  /**
   * Expiry time of the code.
   *
   * @return the expiry time
   */
  public Instant expiresAt() {
    return expiresAt;
  }

  /**
   * Number of verification attempts allowed.
   *
   * @return the attempts
   */
  public int maxAttempts() {
    return maxAttempts;
  }

  /**
   * The code, <b>only</b> with a test key ({@code lsk_test_…}).
   *
   * <p>Nothing is sent by SMS in test mode, so the API returns the code to let you test {@code
   * verify()}. Always empty with a live key. Never log it, never show it to end users. It is
   * excluded from {@link #toString()}, {@link #equals(Object)} and {@link #hashCode()}.
   *
   * @return the code in test mode, empty otherwise
   */
  public Optional<String> testCode() {
    return Optional.ofNullable(testCode);
  }

  @Override
  public boolean equals(Object other) {
    if (this == other) {
      return true;
    }
    if (!(other instanceof OtpSendResult)) {
      return false;
    }
    OtpSendResult that = (OtpSendResult) other;
    return maxAttempts == that.maxAttempts
        && otpId.equals(that.otpId)
        && expiresAt.equals(that.expiresAt);
  }

  @Override
  public int hashCode() {
    return Objects.hash(otpId, expiresAt, maxAttempts);
  }

  /** The test code is never shown. */
  @Override
  public String toString() {
    return "OtpSendResult(otpId="
        + otpId
        + ", expiresAt="
        + expiresAt
        + ", maxAttempts="
        + maxAttempts
        + ", testCode="
        + (testCode == null ? "none" : "[redacted]")
        + ")";
  }
}

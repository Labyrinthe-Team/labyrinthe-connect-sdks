package cd.labyrinthe.connect.webhook;

import cd.labyrinthe.connect.OtpFailureReason;
import cd.labyrinthe.connect.PhoneNumbers;
import java.time.Instant;
import java.util.Objects;
import java.util.Optional;

/** Content of the {@code otp.verified} and {@code otp.failed} events. Never contains the code. */
public final class OtpEventData {

  private final String otpId;
  private final String phone;
  private final OtpEventStatus status;
  private final OtpFailureReason reason;
  private final Instant verifiedAt;

  OtpEventData(
      String otpId,
      String phone,
      OtpEventStatus status,
      OtpFailureReason reason,
      Instant verifiedAt) {
    this.otpId = Objects.requireNonNull(otpId, "otpId");
    this.phone = Objects.requireNonNull(phone, "phone");
    this.status = Objects.requireNonNull(status, "status");
    this.reason = reason;
    this.verifiedAt = verifiedAt;
  }

  /**
   * Id of the OTP (as returned by {@code otp().send()}).
   *
   * @return the OTP id
   */
  public String otpId() {
    return otpId;
  }

  /**
   * Verified number, international format without "+". Personal data: mask it in your logs.
   *
   * @return the phone number
   */
  public String phone() {
    return phone;
  }

  /**
   * Outcome: {@code verified} with {@code otp.verified}; {@code failed} or {@code expired} with
   * {@code otp.failed}.
   *
   * @return the status
   */
  public OtpEventStatus status() {
    return status;
  }

  /**
   * Why the verification failed: {@link OtpFailureReason#MAX_ATTEMPTS_REACHED} or {@link
   * OtpFailureReason#EXPIRED}.
   *
   * @return the reason, empty for {@code otp.verified}
   */
  public Optional<OtpFailureReason> reason() {
    return Optional.ofNullable(reason);
  }

  /**
   * Time of the verification.
   *
   * @return the time, empty for {@code otp.failed}
   */
  public Optional<Instant> verifiedAt() {
    return Optional.ofNullable(verifiedAt);
  }

  /** The phone number is masked. */
  @Override
  public String toString() {
    return "OtpEventData(otpId="
        + otpId
        + ", phone="
        + PhoneNumbers.mask(phone)
        + ", status="
        + status
        + ", reason="
        + reason
        + ")";
  }
}

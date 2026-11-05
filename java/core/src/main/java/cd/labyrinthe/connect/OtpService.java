package cd.labyrinthe.connect;

import cd.labyrinthe.connect.internal.Transport;

/**
 * One-time passwords by SMS ({@code client.otp()}). Thread-safe.
 *
 * <ol>
 *   <li>{@link #send(String)} and keep {@link OtpSendResult#otpId()} server-side (session);
 *   <li>the user types the code received by SMS;
 *   <li>{@link #verify(String, String)}: log the user in when {@link OtpVerifyResult#isVerified()}.
 * </ol>
 */
public final class OtpService {

  private final Transport transport;
  private final ApiMapper mapper;

  OtpService(Transport transport, ApiMapper mapper) {
    this.transport = transport;
    this.mapper = mapper;
  }

  /**
   * Sends a code with the account's OTP settings. See {@link #send(SendOtpParams, RequestOptions)}.
   *
   * @param phone number to verify, in any accepted format
   * @return the OTP id and expiry
   */
  public OtpSendResult send(String phone) {
    return send(SendOtpParams.builder().phone(phone).build());
  }

  /**
   * Sends a code. See {@link #send(SendOtpParams, RequestOptions)}.
   *
   * @param params phone, template and locale
   * @return the OTP id and expiry
   */
  public OtpSendResult send(SendOtpParams params) {
    return send(params, RequestOptions.none());
  }

  /**
   * Generates a code and sends it by SMS (billed like any SMS).
   *
   * <p>A new send to the same number invalidates the previous code. Never retried automatically,
   * except on 429 {@code TOO_MANY_REQUESTS} (refused before processing): a second success would
   * send a second, billed SMS. With a test key, nothing is sent and {@link
   * OtpSendResult#testCode()} holds the code.
   *
   * @param params phone, template and locale
   * @param options per-request options
   * @return the OTP id and expiry
   */
  public OtpSendResult send(SendOtpParams params, RequestOptions options) {
    return ApiMapper.otpSend(transport.send(mapper.otpSend(params, options)));
  }

  /**
   * Checks a code. See {@link #verify(String, String, RequestOptions)}.
   *
   * @param otpId the id returned by {@link #send(String)}
   * @param code the code typed by the user
   * @return the result
   */
  public OtpVerifyResult verify(String otpId, String code) {
    return verify(otpId, code, RequestOptions.none());
  }

  /**
   * Checks a code. A wrong code is <b>not</b> an exception: {@link OtpVerifyResult#isVerified()} is
   * false with a {@link OtpVerifyResult#reason()}. One attempt is consumed per wrong code. Free.
   *
   * @param otpId the id returned by {@link #send(String)}
   * @param code the code typed by the user (never logged)
   * @param options per-request options
   * @return the result
   * @throws cd.labyrinthe.connect.exception.NotFoundException for an unknown {@code otpId}
   */
  public OtpVerifyResult verify(String otpId, String code, RequestOptions options) {
    return ApiMapper.otpVerify(transport.send(ApiMapper.otpVerify(otpId, code, options)));
  }

  @Override
  public String toString() {
    return "OtpService(" + transport + ")";
  }
}

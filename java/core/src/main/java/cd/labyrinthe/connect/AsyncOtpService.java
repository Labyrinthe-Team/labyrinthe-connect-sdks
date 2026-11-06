package cd.labyrinthe.connect;

import cd.labyrinthe.connect.exception.LabyrintheException;
import cd.labyrinthe.connect.internal.Transport;
import java.util.concurrent.CompletableFuture;

/**
 * One-time passwords by SMS, asynchronously ({@code client.async().otp()}). See {@link OtpService}.
 */
public final class AsyncOtpService {

  private final Transport transport;
  private final ApiMapper mapper;

  AsyncOtpService(Transport transport, ApiMapper mapper) {
    this.transport = transport;
    this.mapper = mapper;
  }

  /**
   * Sends a code with the account's OTP settings. See {@link OtpService#send(SendOtpParams,
   * RequestOptions)}.
   *
   * @param phone number to verify
   * @return the OTP id and expiry
   */
  public CompletableFuture<OtpSendResult> send(String phone) {
    try {
      return send(SendOtpParams.builder().phone(phone).build());
    } catch (LabyrintheException e) {
      return CompletableFuture.failedFuture(e);
    }
  }

  /**
   * Sends a code. See {@link OtpService#send(SendOtpParams, RequestOptions)}.
   *
   * @param params phone, template and locale
   * @return the OTP id and expiry
   */
  public CompletableFuture<OtpSendResult> send(SendOtpParams params) {
    return send(params, RequestOptions.none());
  }

  /**
   * Sends a code. See {@link OtpService#send(SendOtpParams, RequestOptions)}.
   *
   * @param params phone, template and locale
   * @param options per-request options
   * @return the OTP id and expiry
   */
  public CompletableFuture<OtpSendResult> send(SendOtpParams params, RequestOptions options) {
    try {
      ApiMapper.Dispatch dispatch = mapper.otpSend(params, options);
      return transport
          .sendAsync(dispatch.request)
          .thenApply(response -> ApiMapper.otpSend(response, dispatch.idempotencyKey));
    } catch (LabyrintheException e) {
      return CompletableFuture.failedFuture(e);
    }
  }

  /**
   * Checks a code. See {@link OtpService#verify(String, String, RequestOptions)}.
   *
   * @param otpId the OTP id
   * @param code the code typed by the user
   * @return the result
   */
  public CompletableFuture<OtpVerifyResult> verify(String otpId, String code) {
    return verify(otpId, code, RequestOptions.none());
  }

  /**
   * Checks a code. See {@link OtpService#verify(String, String, RequestOptions)}.
   *
   * @param otpId the OTP id
   * @param code the code typed by the user
   * @param options per-request options
   * @return the result
   */
  public CompletableFuture<OtpVerifyResult> verify(
      String otpId, String code, RequestOptions options) {
    try {
      return transport
          .sendAsync(ApiMapper.otpVerify(otpId, code, options))
          .thenApply(ApiMapper::otpVerify);
    } catch (LabyrintheException e) {
      return CompletableFuture.failedFuture(e);
    }
  }

  @Override
  public String toString() {
    return "AsyncOtpService(" + transport + ")";
  }
}

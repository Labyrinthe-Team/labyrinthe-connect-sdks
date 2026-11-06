package cd.labyrinthe.connect.examples;

import cd.labyrinthe.connect.LabyrintheClient;
import cd.labyrinthe.connect.OtpFailureReason;
import cd.labyrinthe.connect.OtpSendResult;
import cd.labyrinthe.connect.OtpVerifyResult;
import cd.labyrinthe.connect.exception.OtpRateLimitedException;
import cd.labyrinthe.connect.exception.OtpResendTooSoonException;
import java.io.BufferedReader;
import java.io.IOException;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;
import java.time.Duration;

/**
 * Phone number verification by OTP, as in a login or sign-up flow.
 *
 * <ol>
 *   <li>{@code otp().send(phone)}: keep {@code otpId} SERVER-SIDE (session), never in the browser;
 *   <li>the user types the code received by SMS;
 *   <li>{@code otp().verify(otpId, code)}: log the user in when verified.
 * </ol>
 *
 * <p>With a {@code lsk_test_} key no SMS is sent: the code is in {@code testCode()}.
 */
public final class OtpLogin {

  private OtpLogin() {}

  /**
   * Entry point.
   *
   * @param args the phone number (optional)
   * @throws IOException if the console cannot be read
   */
  public static void main(String[] args) throws IOException {
    String phone = args.length > 0 ? args[0] : "0812345678";
    LabyrintheClient client = LabyrintheClient.fromEnv();

    OtpSendResult otp;
    try {
      otp = client.otp().send(phone);
    } catch (OtpResendTooSoonException e) {
      long seconds = e.retryAfter().map(Duration::getSeconds).orElse(60L);
      System.err.println("A code was just sent. Try again in " + seconds + " s.");
      return;
    } catch (OtpRateLimitedException e) {
      System.err.println("Too many codes for this " + e.scope() + ". Try again later.");
      return;
    }
    // In a web application: session.setAttribute("otpId", otp.otpId());
    System.out.println(
        "A "
            + otp.codeLength()
            + "-character "
            + otp.codeType()
            + " code was sent, valid until "
            + otp.expiresAt()
            + "; a new one can be requested in "
            + otp.resendAfterSeconds()
            + " s.");
    // Test mode only: never print or log the code in production.
    otp.testCode().ifPresent(code -> System.out.println("(test mode) the code is " + code));

    BufferedReader console =
        new BufferedReader(new InputStreamReader(System.in, StandardCharsets.UTF_8));
    for (int attempt = 1; attempt <= otp.maxAttempts(); attempt++) {
      System.out.print("Code: ");
      String typed = console.readLine();
      if (typed == null) {
        return;
      }
      OtpVerifyResult result = client.otp().verify(otp.otpId(), typed);
      if (result.isVerified()) {
        System.out.println("Verified: log the user in.");
        return;
      }
      OtpFailureReason reason = result.reason().orElse(OtpFailureReason.UNKNOWN);
      if (reason != OtpFailureReason.INVALID_CODE) {
        System.out.println("Cannot verify (" + reason + "): ask for a new code.");
        return;
      }
      System.out.println(
          "Wrong code, "
              + (result.attemptsRemaining().isPresent()
                  ? result.attemptsRemaining().getAsInt() + " attempt(s) left."
                  : "try again."));
    }
  }
}

package cd.labyrinthe.connect;

import cd.labyrinthe.connect.internal.Transport;

/**
 * Asynchronous view of a {@link LabyrintheClient} ({@code client.async()}): same services, same
 * rules (retries, idempotency, errors), returning {@link java.util.concurrent.CompletableFuture}s.
 *
 * <pre>{@code
 * client.async().sms().send("0812345678", "Hello!")
 *     .thenAccept(message -> System.out.println(message.status()));
 * }</pre>
 *
 * <p>Futures fail with a {@link cd.labyrinthe.connect.exception.LabyrintheException}; {@code
 * join()} wraps it in a {@link java.util.concurrent.CompletionException} and {@code get()} in an
 * {@link java.util.concurrent.ExecutionException}. Invalid arguments also give a failed future.
 */
public final class LabyrintheAsyncClient {

  private final LabyrintheClient client;
  private final AsyncSmsService sms;
  private final AsyncBalanceService balance;
  private final AsyncSenderIdService senderIds;
  private final AsyncOtpService otp;

  LabyrintheAsyncClient(LabyrintheClient client, Transport transport, ApiMapper mapper) {
    this.client = client;
    this.sms = new AsyncSmsService(transport, mapper);
    this.balance = new AsyncBalanceService(transport);
    this.senderIds = new AsyncSenderIdService(transport);
    this.otp = new AsyncOtpService(transport, mapper);
  }

  /**
   * Send and track SMS.
   *
   * @return the SMS service
   */
  public AsyncSmsService sms() {
    return sms;
  }

  /**
   * Credit balance.
   *
   * @return the balance service
   */
  public AsyncBalanceService balance() {
    return balance;
  }

  /**
   * Approved Sender IDs.
   *
   * @return the Sender ID service
   */
  public AsyncSenderIdService senderIds() {
    return senderIds;
  }

  /**
   * One-time passwords by SMS.
   *
   * @return the OTP service
   */
  public AsyncOtpService otp() {
    return otp;
  }

  /**
   * Whether the client uses a sandbox key ({@code lsk_test_…}).
   *
   * @return true in test mode
   */
  public boolean isTestMode() {
    return client.isTestMode();
  }

  @Override
  public String toString() {
    return "LabyrintheAsyncClient(" + client + ")";
  }
}

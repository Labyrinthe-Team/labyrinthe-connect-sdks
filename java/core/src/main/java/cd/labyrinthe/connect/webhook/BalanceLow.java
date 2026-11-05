package cd.labyrinthe.connect.webhook;

import java.time.Instant;
import java.util.Map;
import java.util.Objects;

/**
 * {@code balance.low}: the balance went below the account's low-balance threshold. Sent once, then
 * again only after the balance went back above it.
 */
public final class BalanceLow extends WebhookEvent {

  private final Data data;

  BalanceLow(String id, String type, Instant timestamp, Map<String, Object> raw, Data data) {
    super(id, type, timestamp, raw);
    this.data = Objects.requireNonNull(data, "data");
  }

  @Override
  public WebhookEventType eventType() {
    return WebhookEventType.BALANCE_LOW;
  }

  /**
   * Balance and threshold.
   *
   * @return the data
   */
  public Data data() {
    return data;
  }

  /** Content of a {@code balance.low} event. */
  public static final class Data {
    private final long balance;
    private final long threshold;

    Data(long balance, long threshold) {
      this.balance = balance;
      this.threshold = threshold;
    }

    /**
     * Balance after the operation that crossed the threshold.
     *
     * @return the credits
     */
    public long balance() {
      return balance;
    }

    /**
     * Low-balance threshold of the account.
     *
     * @return the credits
     */
    public long threshold() {
      return threshold;
    }

    @Override
    public String toString() {
      return "BalanceLow.Data(balance=" + balance + ", threshold=" + threshold + ")";
    }
  }
}

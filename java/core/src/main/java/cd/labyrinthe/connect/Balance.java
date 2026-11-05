package cd.labyrinthe.connect;

/** Credit balance, in SMS credits (1 credit = 1 segment). Immutable. */
public final class Balance {

  private final long balance;
  private final long held;

  Balance(long balance, long held) {
    this.balance = balance;
    this.held = held;
  }

  /**
   * Available credits.
   *
   * @return the credits
   */
  public long balance() {
    return balance;
  }

  /**
   * Credits held by running campaigns.
   *
   * @return the credits
   */
  public long held() {
    return held;
  }

  @Override
  public boolean equals(Object other) {
    return other instanceof Balance
        && ((Balance) other).balance == balance
        && ((Balance) other).held == held;
  }

  @Override
  public int hashCode() {
    return Long.hashCode(balance) * 31 + Long.hashCode(held);
  }

  @Override
  public String toString() {
    return "Balance(balance=" + balance + ", held=" + held + ")";
  }
}

package cd.labyrinthe.connect;

import cd.labyrinthe.connect.internal.Transport;

/** Credit balance ({@code client.balance()}). Thread-safe. */
public final class BalanceService {

  private final Transport transport;

  BalanceService(Transport transport) {
    this.transport = transport;
  }

  /**
   * Current balance.
   *
   * @return the balance
   */
  public Balance get() {
    return get(RequestOptions.none());
  }

  /**
   * Current balance.
   *
   * @param options per-request options
   * @return the balance
   */
  public Balance get(RequestOptions options) {
    return ApiMapper.balance(transport.send(ApiMapper.balance(options)));
  }

  @Override
  public String toString() {
    return "BalanceService(" + transport + ")";
  }
}

package cd.labyrinthe.connect;

import cd.labyrinthe.connect.internal.Transport;
import java.util.concurrent.CompletableFuture;

/** Credit balance, asynchronously ({@code client.async().balance()}). */
public final class AsyncBalanceService {

  private final Transport transport;

  AsyncBalanceService(Transport transport) {
    this.transport = transport;
  }

  /**
   * Current balance.
   *
   * @return the balance
   */
  public CompletableFuture<Balance> get() {
    return get(RequestOptions.none());
  }

  /**
   * Current balance.
   *
   * @param options per-request options
   * @return the balance
   */
  public CompletableFuture<Balance> get(RequestOptions options) {
    return transport.sendAsync(ApiMapper.balance(options)).thenApply(ApiMapper::balance);
  }

  @Override
  public String toString() {
    return "AsyncBalanceService(" + transport + ")";
  }
}

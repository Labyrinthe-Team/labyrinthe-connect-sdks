package cd.labyrinthe.connect;

import cd.labyrinthe.connect.internal.Transport;
import java.util.List;
import java.util.concurrent.CompletableFuture;

/** Approved Sender IDs, asynchronously ({@code client.async().senderIds()}). */
public final class AsyncSenderIdService {

  private final Transport transport;

  AsyncSenderIdService(Transport transport) {
    this.transport = transport;
  }

  /**
   * Sender IDs with the {@code approved} status.
   *
   * @return unmodifiable list
   */
  public CompletableFuture<List<SenderId>> list() {
    return list(RequestOptions.none());
  }

  /**
   * Sender IDs with the {@code approved} status.
   *
   * @param options per-request options
   * @return unmodifiable list
   */
  public CompletableFuture<List<SenderId>> list(RequestOptions options) {
    return transport.sendAsync(ApiMapper.senderIds(options)).thenApply(ApiMapper::senderIds);
  }

  @Override
  public String toString() {
    return "AsyncSenderIdService(" + transport + ")";
  }
}

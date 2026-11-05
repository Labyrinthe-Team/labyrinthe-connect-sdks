package cd.labyrinthe.connect;

import cd.labyrinthe.connect.internal.Transport;
import java.util.List;

/** Approved Sender IDs ({@code client.senderIds()}). Thread-safe. */
public final class SenderIdService {

  private final Transport transport;

  SenderIdService(Transport transport) {
    this.transport = transport;
  }

  /**
   * Sender IDs with the {@code approved} status, usable as {@code sender}.
   *
   * @return unmodifiable list
   */
  public List<SenderId> list() {
    return list(RequestOptions.none());
  }

  /**
   * Sender IDs with the {@code approved} status, usable as {@code sender}.
   *
   * @param options per-request options
   * @return unmodifiable list
   */
  public List<SenderId> list(RequestOptions options) {
    return ApiMapper.senderIds(transport.send(ApiMapper.senderIds(options)));
  }

  @Override
  public String toString() {
    return "SenderIdService(" + transport + ")";
  }
}

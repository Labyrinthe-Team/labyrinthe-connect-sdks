package cd.labyrinthe.connect;

import cd.labyrinthe.connect.exception.InvalidArgumentException;
import java.time.Duration;
import java.util.Optional;

/** Per-request options, e.g. a shorter timeout for one call. Immutable. */
public final class RequestOptions {

  private static final RequestOptions NONE = new RequestOptions(null);

  private final Duration timeout;

  private RequestOptions(Duration timeout) {
    this.timeout = timeout;
  }

  /**
   * No override: the client's options apply.
   *
   * @return the default options
   */
  public static RequestOptions none() {
    return NONE;
  }

  /**
   * Overrides the timeout of each attempt of this request.
   *
   * @param timeout greater than zero
   * @return the options
   */
  public static RequestOptions timeout(Duration timeout) {
    if (timeout == null || timeout.isNegative() || timeout.isZero()) {
      throw new InvalidArgumentException("timeout must be greater than 0.");
    }
    return new RequestOptions(timeout);
  }

  /**
   * The timeout override.
   *
   * @return the timeout, if set
   */
  public Optional<Duration> timeoutOverride() {
    return Optional.ofNullable(timeout);
  }

  @Override
  public String toString() {
    return "RequestOptions(timeout=" + timeout + ")";
  }
}

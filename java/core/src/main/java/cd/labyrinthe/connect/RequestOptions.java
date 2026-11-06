package cd.labyrinthe.connect;

import cd.labyrinthe.connect.exception.InvalidArgumentException;
import java.time.Duration;
import java.util.Optional;
import java.util.function.Consumer;
import java.util.regex.Pattern;

/**
 * Per-request options: a shorter timeout, your own {@code X-Request-Id}, a callback reading the
 * response headers. Immutable.
 *
 * <pre>{@code
 * RequestOptions options =
 *     RequestOptions.builder()
 *         .requestId("order-1042")
 *         .onResponse(info -> log.info("request id {}", info.requestId().orElse("?")))
 *         .build();
 * client.sms().send(params, options);
 * }</pre>
 */
public final class RequestOptions {

  /** Pattern of an {@code X-Request-Id} accepted by the API: {@value}. */
  public static final String REQUEST_ID_PATTERN = "^[A-Za-z0-9._:-]{1,128}$";

  private static final Pattern REQUEST_ID = Pattern.compile(REQUEST_ID_PATTERN);

  private static final RequestOptions NONE = new RequestOptions(null, null, null);

  private final Duration timeout;
  private final String requestId;
  private final Consumer<ResponseInfo> onResponse;

  private RequestOptions(Duration timeout, String requestId, Consumer<ResponseInfo> onResponse) {
    this.timeout = timeout;
    this.requestId = requestId;
    this.onResponse = onResponse;
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
    return builder().timeout(timeout).build();
  }

  /**
   * Sends your own request identifier as {@code X-Request-Id} (on every retry of this call). The
   * API logs it and echoes it in the response ({@link ResponseInfo#requestId()}, {@link
   * cd.labyrinthe.connect.exception.ApiException#requestId()}).
   *
   * @param requestId 1 to 128 characters among {@code A-Z a-z 0-9 . _ : -}
   * @return the options
   */
  public static RequestOptions requestId(String requestId) {
    return builder().requestId(requestId).build();
  }

  /**
   * A new builder.
   *
   * @return the builder
   */
  public static Builder builder() {
    return new Builder();
  }

  /**
   * The timeout override.
   *
   * @return the timeout, if set
   */
  public Optional<Duration> timeoutOverride() {
    return Optional.ofNullable(timeout);
  }

  /**
   * Your request identifier.
   *
   * @return the identifier, if set
   */
  public Optional<String> requestIdOverride() {
    return Optional.ofNullable(requestId);
  }

  /**
   * The callback called with each successful response.
   *
   * @return the callback, if set
   */
  public Optional<Consumer<ResponseInfo>> onResponse() {
    return Optional.ofNullable(onResponse);
  }

  @Override
  public String toString() {
    return "RequestOptions(timeout="
        + timeout
        + ", requestId="
        + requestId
        + ", onResponse="
        + (onResponse != null)
        + ")";
  }

  /** Builder of {@link RequestOptions}. */
  public static final class Builder {
    private Duration timeout;
    private String requestId;
    private Consumer<ResponseInfo> onResponse;

    private Builder() {}

    /**
     * Overrides the timeout of each attempt of this request.
     *
     * @param timeout greater than zero
     * @return this builder
     */
    public Builder timeout(Duration timeout) {
      if (timeout == null || timeout.isNegative() || timeout.isZero()) {
        throw new InvalidArgumentException("timeout must be greater than 0.");
      }
      this.timeout = timeout;
      return this;
    }

    /**
     * Your own request identifier, sent as {@code X-Request-Id} on every attempt. Without it, the
     * API generates one (a UUID).
     *
     * @param requestId 1 to 128 characters among {@code A-Z a-z 0-9 . _ : -}
     * @return this builder
     */
    public Builder requestId(String requestId) {
      if (requestId == null || !REQUEST_ID.matcher(requestId).matches()) {
        throw new InvalidArgumentException(
            "requestId must be 1 to 128 characters among A-Z a-z 0-9 . _ : -");
      }
      this.requestId = requestId;
      return this;
    }

    /**
     * Called with the headers of each successful response (request id, API version, rate limit,
     * idempotent replay), before the result is returned; for paginated lists, once per page. Errors
     * carry the same headers ({@link cd.labyrinthe.connect.exception.ApiException#headers()}).
     *
     * @param onResponse the callback (an exception it throws reaches the caller)
     * @return this builder
     */
    public Builder onResponse(Consumer<ResponseInfo> onResponse) {
      this.onResponse = onResponse;
      return this;
    }

    /**
     * Builds the options.
     *
     * @return the options
     */
    public RequestOptions build() {
      return new RequestOptions(timeout, requestId, onResponse);
    }
  }
}

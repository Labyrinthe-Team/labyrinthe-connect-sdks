package cd.labyrinthe.connect;

import cd.labyrinthe.connect.exception.InvalidArgumentException;
import cd.labyrinthe.connect.internal.Redact;
import cd.labyrinthe.connect.internal.Transport;
import java.net.http.HttpClient;
import java.time.Duration;
import java.util.Objects;
import java.util.function.UnaryOperator;

/**
 * Labyrinthe Connect API client. Thread-safe: create one per application and share it.
 *
 * <pre>{@code
 * LabyrintheClient client = LabyrintheClient.builder().apiKey("lsk_test_…").build();
 * Message message = client.sms().send("0812345678", "Hello from Labyrinthe Connect!");
 * System.out.println(message.status());   // queued
 * }</pre>
 *
 * <p>Keys starting with {@code lsk_test_} use the sandbox: nothing is sent, no credit is used,
 * responses have the same shape as in production. Keep keys server-side, never in a mobile app or a
 * browser.
 *
 * <p>{@link #async()} gives the same API with {@link java.util.concurrent.CompletableFuture}s.
 */
public final class LabyrintheClient {

  /** Production API root: {@value}. */
  public static final String DEFAULT_BASE_URL = "https://api.labyrinthe-rdc.com/api/public/v1";

  /** Default timeout of each attempt: 30 seconds. */
  public static final Duration DEFAULT_TIMEOUT = Duration.ofSeconds(30);

  /** Default number of automatic retries: {@value}. */
  public static final int DEFAULT_MAX_RETRIES = 2;

  /** Default longest wait accepted before a retry ({@code Retry-After}): 10 seconds. */
  public static final Duration DEFAULT_MAX_RETRY_WAIT = Duration.ofSeconds(10);

  /** Environment variable read for the API key: {@value}. */
  public static final String ENV_API_KEY = "LABYRINTHE_API_KEY";

  /** Environment variable read for the API root: {@value}. */
  public static final String ENV_BASE_URL = "LABYRINTHE_BASE_URL";

  private final String apiKey;
  private final String baseUrl;
  private final Transport transport;
  private final SmsService sms;
  private final BalanceService balance;
  private final SenderIdService senderIds;
  private final OtpService otp;
  private final LabyrintheAsyncClient async;

  private LabyrintheClient(Builder builder) {
    String key = builder.apiKey != null ? builder.apiKey : builder.environment.apply(ENV_API_KEY);
    key = key == null ? "" : key.trim();
    if (key.isEmpty()) {
      throw new InvalidArgumentException(
          "A Labyrinthe Connect API key is required (lsk_live_… or lsk_test_…): call apiKey(…)"
              + " or set LABYRINTHE_API_KEY.");
    }
    if (builder.timeout == null || builder.timeout.isNegative() || builder.timeout.isZero()) {
      throw new InvalidArgumentException("timeout must be greater than 0.");
    }
    if (builder.maxRetries < 0 || builder.maxRetries > Transport.MAX_RETRIES_LIMIT) {
      throw new InvalidArgumentException(
          "maxRetries must be between 0 and " + Transport.MAX_RETRIES_LIMIT + ".");
    }
    if (builder.maxRetryWait == null || builder.maxRetryWait.isNegative()) {
      throw new InvalidArgumentException("maxRetryWait cannot be negative.");
    }
    PhoneNumbers.assertCountryCode(builder.defaultCountryCode);
    String sender =
        builder.defaultSender == null || builder.defaultSender.isEmpty()
            ? null
            : Checks.sender(builder.defaultSender);

    String url = builder.baseUrl;
    if (url == null || url.trim().isEmpty()) {
      url = builder.environment.apply(ENV_BASE_URL);
    }
    if (url == null || url.trim().isEmpty()) {
      url = DEFAULT_BASE_URL;
    }

    HttpClient http = builder.httpClient;
    if (http == null) {
      http =
          HttpClient.newBuilder()
              .followRedirects(HttpClient.Redirect.NEVER)
              .connectTimeout(builder.timeout)
              .build();
    } else if (http.followRedirects() != HttpClient.Redirect.NEVER) {
      // Une redirection renverrait l'en-tête Authorization vers une autre URL.
      throw new InvalidArgumentException(
          "httpClient must not follow redirects (HttpClient.Redirect.NEVER, the default).");
    }

    this.apiKey = key;
    this.baseUrl = Transport.normalizeBaseUrl(url);
    this.transport =
        new Transport(
            key,
            this.baseUrl,
            builder.timeout,
            builder.maxRetries,
            builder.maxRetryWait,
            http,
            builder.scheduler,
            Version.userAgent());
    ApiMapper mapper = new ApiMapper(builder.defaultCountryCode, sender);
    this.sms = new SmsService(transport, mapper);
    this.balance = new BalanceService(transport);
    this.senderIds = new SenderIdService(transport);
    this.otp = new OtpService(transport, mapper);
    this.async = new LabyrintheAsyncClient(this, transport, mapper);
  }

  /**
   * A new builder. Without {@link Builder#apiKey(String)}, the key is read from {@value
   * #ENV_API_KEY}; without {@link Builder#baseUrl(String)}, the root is read from {@value
   * #ENV_BASE_URL}, then defaults to production.
   *
   * @return the builder
   */
  public static Builder builder() {
    return new Builder();
  }

  /**
   * A client configured from {@value #ENV_API_KEY} and {@value #ENV_BASE_URL}, with default
   * options.
   *
   * @return the client
   * @throws InvalidArgumentException when {@value #ENV_API_KEY} is not set
   */
  public static LabyrintheClient fromEnv() {
    return builder().build();
  }

  /**
   * Send and track SMS.
   *
   * @return the SMS service
   */
  public SmsService sms() {
    return sms;
  }

  /**
   * Credit balance.
   *
   * @return the balance service
   */
  public BalanceService balance() {
    return balance;
  }

  /**
   * Approved Sender IDs.
   *
   * @return the Sender ID service
   */
  public SenderIdService senderIds() {
    return senderIds;
  }

  /**
   * One-time passwords by SMS.
   *
   * @return the OTP service
   */
  public OtpService otp() {
    return otp;
  }

  /**
   * The same API, returning {@link java.util.concurrent.CompletableFuture}s (same connections, same
   * options).
   *
   * @return the asynchronous client
   */
  public LabyrintheAsyncClient async() {
    return async;
  }

  /**
   * Whether this client uses a sandbox key ({@code lsk_test_…}): nothing is sent, no credit is
   * used.
   *
   * @return true in test mode
   */
  public boolean isTestMode() {
    return apiKey.startsWith("lsk_test_");
  }

  /**
   * Whether this client uses a production key ({@code lsk_live_…}).
   *
   * @return true in live mode
   */
  public boolean isLiveMode() {
    return apiKey.startsWith("lsk_live_");
  }

  /**
   * The API root used by this client.
   *
   * @return e.g. {@code https://api.labyrinthe-rdc.com/api/public/v1}
   */
  public String baseUrl() {
    return baseUrl;
  }

  /** The API key is masked ({@code lsk_test_****}). */
  @Override
  public String toString() {
    return "LabyrintheClient(apiKey="
        + Redact.apiKey(apiKey)
        + ", baseUrl="
        + baseUrl
        + ", testMode="
        + isTestMode()
        + ")";
  }

  /** Builder of {@link LabyrintheClient}. Not thread-safe. */
  public static final class Builder {
    private String apiKey;
    private String baseUrl;
    private Duration timeout = DEFAULT_TIMEOUT;
    private int maxRetries = DEFAULT_MAX_RETRIES;
    private Duration maxRetryWait = DEFAULT_MAX_RETRY_WAIT;
    private String defaultCountryCode = PhoneNumbers.DEFAULT_COUNTRY_CODE;
    private String defaultSender;
    private HttpClient httpClient;
    private UnaryOperator<String> environment = System::getenv;
    private Transport.Scheduler scheduler = Transport.Scheduler.system();

    private Builder() {}

    /**
     * API key: {@code lsk_live_…} or {@code lsk_test_…}. Defaults to {@value #ENV_API_KEY}.
     *
     * @param apiKey the key (keep it server-side)
     * @return this builder
     */
    public Builder apiKey(String apiKey) {
      this.apiKey = apiKey;
      return this;
    }

    /**
     * API root. Defaults to {@value #ENV_BASE_URL}, then to {@value #DEFAULT_BASE_URL}. A bare host
     * ({@code http://localhost:8000}) is completed with {@code /api/public/v1}.
     *
     * @param baseUrl absolute http(s) URL
     * @return this builder
     */
    public Builder baseUrl(String baseUrl) {
      this.baseUrl = baseUrl;
      return this;
    }

    /**
     * Timeout of each attempt (default 30 s), also used as connect timeout.
     *
     * @param timeout greater than zero
     * @return this builder
     */
    public Builder timeout(Duration timeout) {
      this.timeout = timeout;
      return this;
    }

    /**
     * Automatic retries, only when they are safe (default {@value #DEFAULT_MAX_RETRIES}; 0 disables
     * them; max 10).
     *
     * @param maxRetries 0 to 10
     * @return this builder
     */
    public Builder maxRetries(int maxRetries) {
      this.maxRetries = maxRetries;
      return this;
    }

    /**
     * Longest wait accepted before a retry (default 10 s). When the API asks to wait longer ({@code
     * Retry-After}), the SDK throws a {@link cd.labyrinthe.connect.exception.RateLimitException}
     * instead of waiting.
     *
     * @param maxRetryWait zero or more
     * @return this builder
     */
    public Builder maxRetryWait(Duration maxRetryWait) {
      this.maxRetryWait = maxRetryWait;
      return this;
    }

    /**
     * Country code added to local numbers (default {@code "243"}: {@code 0812345678} → {@code
     * 243812345678}).
     *
     * @param defaultCountryCode 1 to 3 digits, without "+"
     * @return this builder
     */
    public Builder defaultCountryCode(String defaultCountryCode) {
      this.defaultCountryCode = defaultCountryCode;
      return this;
    }

    /**
     * Sender ID used when a send has none.
     *
     * @param defaultSender an approved Sender ID
     * @return this builder
     */
    public Builder defaultSender(String defaultSender) {
      this.defaultSender = defaultSender;
      return this;
    }

    /**
     * Your own {@link HttpClient} (proxy, TLS, executor…). It must not follow redirects ({@link
     * HttpClient.Redirect#NEVER}, the default of {@code HttpClient.newBuilder()}).
     *
     * @param httpClient the client
     * @return this builder
     */
    public Builder httpClient(HttpClient httpClient) {
      this.httpClient = httpClient;
      return this;
    }

    /** Variables d'environnement (tests). */
    Builder environment(UnaryOperator<String> environment) {
      this.environment = Objects.requireNonNull(environment);
      return this;
    }

    /** Attente entre deux tentatives (tests). */
    Builder scheduler(Transport.Scheduler scheduler) {
      this.scheduler = Objects.requireNonNull(scheduler);
      return this;
    }

    /**
     * Builds the client.
     *
     * @return the client
     * @throws InvalidArgumentException if an option is invalid or no API key is available
     */
    public LabyrintheClient build() {
      return new LabyrintheClient(this);
    }
  }
}

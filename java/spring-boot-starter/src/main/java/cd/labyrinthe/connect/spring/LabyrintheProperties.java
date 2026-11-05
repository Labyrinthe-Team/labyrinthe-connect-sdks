package cd.labyrinthe.connect.spring;

import java.time.Duration;
import org.springframework.boot.context.properties.ConfigurationProperties;

/**
 * Configuration of the Labyrinthe Connect client and webhook endpoint ({@code labyrinthe.*}).
 *
 * <pre>
 * labyrinthe.api-key=${LABYRINTHE_API_KEY}
 * labyrinthe.webhook.secret=${LABYRINTHE_WEBHOOK_SECRET}
 * </pre>
 */
@ConfigurationProperties(prefix = "labyrinthe")
public class LabyrintheProperties {

  /**
   * API key ({@code lsk_live_…} or {@code lsk_test_…}). Defaults to the {@code LABYRINTHE_API_KEY}
   * environment variable. The client bean is only created when a key is available.
   */
  private String apiKey;

  /** API root. Defaults to {@code LABYRINTHE_BASE_URL}, then to production. */
  private String baseUrl;

  /** Timeout of each attempt. */
  private Duration timeout = Duration.ofSeconds(30);

  /** Automatic retries, only when they are safe (0 to 10). */
  private int maxRetries = 2;

  /** Longest Retry-After the SDK waits for before giving up. */
  private Duration maxRetryWait = Duration.ofSeconds(10);

  /** Country code added to local numbers. */
  private String defaultCountryCode = "243";

  /** Sender ID used when a send has none. */
  private String defaultSender;

  /** Signed webhook endpoint. */
  private final Webhook webhook = new Webhook();

  /**
   * @return the API key
   */
  public String getApiKey() {
    return apiKey;
  }

  /**
   * @param apiKey the API key
   */
  public void setApiKey(String apiKey) {
    this.apiKey = apiKey;
  }

  /**
   * @return the API root
   */
  public String getBaseUrl() {
    return baseUrl;
  }

  /**
   * @param baseUrl the API root
   */
  public void setBaseUrl(String baseUrl) {
    this.baseUrl = baseUrl;
  }

  /**
   * @return the timeout of each attempt
   */
  public Duration getTimeout() {
    return timeout;
  }

  /**
   * @param timeout the timeout of each attempt
   */
  public void setTimeout(Duration timeout) {
    this.timeout = timeout;
  }

  /**
   * @return the number of automatic retries
   */
  public int getMaxRetries() {
    return maxRetries;
  }

  /**
   * @param maxRetries the number of automatic retries
   */
  public void setMaxRetries(int maxRetries) {
    this.maxRetries = maxRetries;
  }

  /**
   * @return the longest wait accepted before a retry
   */
  public Duration getMaxRetryWait() {
    return maxRetryWait;
  }

  /**
   * @param maxRetryWait the longest wait accepted before a retry
   */
  public void setMaxRetryWait(Duration maxRetryWait) {
    this.maxRetryWait = maxRetryWait;
  }

  /**
   * @return the default country code
   */
  public String getDefaultCountryCode() {
    return defaultCountryCode;
  }

  /**
   * @param defaultCountryCode the default country code
   */
  public void setDefaultCountryCode(String defaultCountryCode) {
    this.defaultCountryCode = defaultCountryCode;
  }

  /**
   * @return the default Sender ID
   */
  public String getDefaultSender() {
    return defaultSender;
  }

  /**
   * @param defaultSender the default Sender ID
   */
  public void setDefaultSender(String defaultSender) {
    this.defaultSender = defaultSender;
  }

  /**
   * @return the webhook settings
   */
  public Webhook getWebhook() {
    return webhook;
  }

  /** Settings of the signed webhook endpoint ({@code labyrinthe.webhook.*}). */
  public static class Webhook {

    /** Whether the webhook endpoint is registered (when a secret is set). */
    private boolean enabled = true;

    /** Path of the webhook endpoint (POST). */
    private String path = "/webhooks/labyrinthe";

    /**
     * Endpoint secret (client area, Developers, Webhooks). The endpoint is only registered when it
     * is set.
     */
    private String secret;

    /** Maximum age of a delivery (replay protection); 0 disables the check. */
    private Duration tolerance = Duration.ofMinutes(5);

    /**
     * @return whether the endpoint is enabled
     */
    public boolean isEnabled() {
      return enabled;
    }

    /**
     * @param enabled whether the endpoint is enabled
     */
    public void setEnabled(boolean enabled) {
      this.enabled = enabled;
    }

    /**
     * @return the endpoint path
     */
    public String getPath() {
      return path;
    }

    /**
     * @param path the endpoint path
     */
    public void setPath(String path) {
      this.path = path;
    }

    /**
     * @return the endpoint secret
     */
    public String getSecret() {
      return secret;
    }

    /**
     * @param secret the endpoint secret
     */
    public void setSecret(String secret) {
      this.secret = secret;
    }

    /**
     * @return the timestamp tolerance
     */
    public Duration getTolerance() {
      return tolerance;
    }

    /**
     * @param tolerance the timestamp tolerance
     */
    public void setTolerance(Duration tolerance) {
      this.tolerance = tolerance;
    }

    /** The secret is never shown. */
    @Override
    public String toString() {
      return "Webhook(enabled="
          + enabled
          + ", path="
          + path
          + ", secret="
          + (secret == null ? "unset" : "****")
          + ")";
    }
  }

  /** The API key is never shown. */
  @Override
  public String toString() {
    return "LabyrintheProperties(apiKey="
        + (apiKey == null ? "unset" : "****")
        + ", baseUrl="
        + baseUrl
        + ", webhook="
        + webhook
        + ")";
  }
}

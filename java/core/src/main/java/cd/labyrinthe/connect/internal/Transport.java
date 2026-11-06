package cd.labyrinthe.connect.internal;

import cd.labyrinthe.connect.exception.ApiConnectionException;
import cd.labyrinthe.connect.exception.ApiException;
import cd.labyrinthe.connect.exception.ApiTimeoutException;
import cd.labyrinthe.connect.exception.InvalidArgumentException;
import cd.labyrinthe.connect.exception.LabyrintheException;
import cd.labyrinthe.connect.exception.UnexpectedResponseException;
import java.io.IOException;
import java.lang.System.Logger.Level;
import java.math.BigDecimal;
import java.net.URI;
import java.net.URISyntaxException;
import java.net.http.HttpClient;
import java.net.http.HttpHeaders;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.net.http.HttpTimeoutException;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Optional;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.CompletionException;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.Executor;
import java.util.concurrent.ThreadLocalRandom;
import java.util.concurrent.TimeUnit;
import java.util.function.Consumer;
import java.util.function.Function;

/**
 * Envoi des requêtes, politique de relance et conversion des erreurs. Usage interne.
 *
 * <p>Mêmes règles que les SDK PHP, Node et Python ({@code python/src/labyrinthe_connect/
 * _transport.py}) : le cœur des décisions est commun, seules les boucles synchrone et asynchrone
 * diffèrent.
 */
public final class Transport {

  /** Racine de l'API publique sous un hôte. */
  public static final String API_PATH = "/api/public/v1";

  /** Plafond du nombre de relances. */
  public static final int MAX_RETRIES_LIMIT = 10;

  private static final System.Logger LOG = System.getLogger("cd.labyrinthe.connect");
  private static final Duration BASE_DELAY = Duration.ofMillis(500);
  private static final Duration MAX_BACKOFF = Duration.ofSeconds(8);
  private static final String IN_PROGRESS = "IDEMPOTENCY_REQUEST_IN_PROGRESS";

  /**
   * Quand une requête peut être renvoyée automatiquement.
   *
   * <ul>
   *   <li>{@code SAFE} : le rejeu ne peut pas produire deux fois un effet (GET, estimate, POST /sms
   *       et POST /otp/send avec Idempotency-Key). Relancée sur erreur réseau, 5xx, 429
   *       TOO_MANY_REQUESTS et 409 IDEMPOTENCY_REQUEST_IN_PROGRESS.
   *   <li>{@code THROTTLED_ONLY} : POST /otp/verify. Relancée seulement sur 429 TOO_MANY_REQUESTS
   *       (refusée avant traitement), jamais sur erreur réseau ni 5xx (risque d'une tentative
   *       consommée).
   * </ul>
   */
  public enum RetryMode {
    /** Rejeu sans effet de bord. */
    SAFE,
    /** Rejeu seulement sur 429 TOO_MANY_REQUESTS. */
    THROTTLED_ONLY
  }

  /** Attente entre deux tentatives (remplacée dans les tests). */
  public interface Scheduler {
    /**
     * Attente bloquante.
     *
     * @param delay durée
     * @throws InterruptedException si le fil est interrompu
     */
    void sleep(Duration delay) throws InterruptedException;

    /**
     * Exécuteur qui lance ses tâches après {@code delay}.
     *
     * @param delay durée
     * @return l'exécuteur
     */
    Executor delayed(Duration delay);

    /**
     * L'horloge réelle.
     *
     * @return le planificateur système
     */
    static Scheduler system() {
      return SystemScheduler.INSTANCE;
    }
  }

  private enum SystemScheduler implements Scheduler {
    INSTANCE;

    @Override
    public void sleep(Duration delay) throws InterruptedException {
      Thread.sleep(delay.toMillis());
    }

    @Override
    public Executor delayed(Duration delay) {
      return CompletableFuture.delayedExecutor(delay.toMillis(), TimeUnit.MILLISECONDS);
    }
  }

  /** Une requête à l'API. */
  public static final class Request {
    final String method;
    final String path;
    final RetryMode mode;
    final List<Map.Entry<String, String>> query;
    final Map<String, Object> body;
    final Map<String, String> headers;
    final Duration timeout;
    final Consumer<Response> onResponse;

    /**
     * @param method méthode HTTP
     * @param path chemin sous la racine de l'API, par ex. {@code /sms}
     * @param mode politique de relance
     * @param query paramètres (valeurs nulles ignorées), ou {@code null}
     * @param body corps JSON, ou {@code null}
     * @param headers en-têtes supplémentaires, ou {@code null}
     * @param timeout délai de cette requête, ou {@code null} pour celui du client
     */
    public Request(
        String method,
        String path,
        RetryMode mode,
        Map<String, ?> query,
        Map<String, Object> body,
        Map<String, String> headers,
        Duration timeout) {
      this(method, path, mode, query, body, headers, timeout, null);
    }

    /**
     * @param method méthode HTTP
     * @param path chemin sous la racine de l'API, par ex. {@code /sms}
     * @param mode politique de relance
     * @param query paramètres (valeurs nulles ignorées), ou {@code null}
     * @param body corps JSON, ou {@code null}
     * @param headers en-têtes supplémentaires, ou {@code null}
     * @param timeout délai de cette requête, ou {@code null} pour celui du client
     * @param onResponse appelé avec la réponse 2xx avant son décodage, ou {@code null}
     */
    public Request(
        String method,
        String path,
        RetryMode mode,
        Map<String, ?> query,
        Map<String, Object> body,
        Map<String, String> headers,
        Duration timeout,
        Consumer<Response> onResponse) {
      this.method = method;
      this.path = path;
      this.mode = mode;
      List<Map.Entry<String, String>> params = new ArrayList<>();
      if (query != null) {
        query.forEach(
            (name, value) -> {
              if (value != null) {
                params.add(Map.entry(name, String.valueOf(value)));
              }
            });
      }
      this.query = Collections.unmodifiableList(params);
      this.body = body;
      this.headers = headers == null ? Collections.emptyMap() : headers;
      this.timeout = timeout;
      this.onResponse = onResponse;
    }
  }

  /** Réponse 2xx décodée. */
  public static final class Response {
    private final int status;
    private final Map<String, Object> body;
    private final Map<String, String> headers;

    Response(int status, Map<String, Object> body, Map<String, String> headers) {
      this.status = status;
      this.body = body;
      this.headers = headers;
    }

    /**
     * @return le statut HTTP
     */
    public int status() {
      return status;
    }

    /**
     * @return le corps JSON (objet)
     */
    public Map<String, Object> body() {
      return body;
    }

    /**
     * @param name nom en minuscules
     * @return la valeur de l'en-tête, ou {@code null}
     */
    public String header(String name) {
      return headers.get(name);
    }

    /**
     * @return tous les en-têtes (noms en minuscules)
     */
    public Map<String, String> headers() {
      return headers;
    }
  }

  /** Une requête préparée : mêmes octets pour chaque tentative. */
  private static final class Call {
    final HttpRequest request;
    final String method;
    final String logPath;
    final RetryMode mode;
    final Duration timeout;
    final Consumer<Response> onResponse;

    Call(
        HttpRequest request,
        String method,
        String logPath,
        RetryMode mode,
        Duration timeout,
        Consumer<Response> onResponse) {
      this.request = request;
      this.method = method;
      this.logPath = logPath;
      this.mode = mode;
      this.timeout = timeout;
      this.onResponse = onResponse;
    }
  }

  /** Résultat d'une tentative : la réponse, ou le délai avant la suivante. */
  private static final class Outcome {
    final Response response;
    final Duration delay;

    Outcome(Response response, Duration delay) {
      this.response = response;
      this.delay = delay;
    }
  }

  private final String apiKey;
  private final String baseUrl;
  private final Duration timeout;
  private final int maxRetries;
  private final Duration maxRetryWait;
  private final HttpClient http;
  private final Scheduler scheduler;
  private final String userAgent;

  /**
   * @param apiKey clé API
   * @param baseUrl racine normalisée ({@link #normalizeBaseUrl})
   * @param timeout délai par tentative
   * @param maxRetries relances automatiques
   * @param maxRetryWait attente maximale acceptée avant une relance
   * @param http client HTTP
   * @param scheduler attente entre deux tentatives
   * @param userAgent valeur de l'en-tête User-Agent
   */
  public Transport(
      String apiKey,
      String baseUrl,
      Duration timeout,
      int maxRetries,
      Duration maxRetryWait,
      HttpClient http,
      Scheduler scheduler,
      String userAgent) {
    this.apiKey = apiKey;
    this.baseUrl = baseUrl;
    this.timeout = timeout;
    this.maxRetries = maxRetries;
    this.maxRetryWait = maxRetryWait;
    this.http = http;
    this.scheduler = scheduler;
    this.userAgent = userAgent;
  }

  /**
   * Racine de l'API : URL absolue http(s) ; un hôte seul ({@code http://localhost:8000}) est
   * complété par {@value #API_PATH}.
   *
   * @param baseUrl URL saisie
   * @return l'URL normalisée, sans barre finale
   */
  public static String normalizeBaseUrl(String baseUrl) {
    String trimmed = baseUrl.trim();
    while (trimmed.endsWith("/")) {
      trimmed = trimmed.substring(0, trimmed.length() - 1);
    }
    URI uri;
    try {
      uri = new URI(trimmed);
    } catch (URISyntaxException e) {
      uri = null;
    }
    String scheme = uri == null ? null : uri.getScheme();
    if (uri == null
        || scheme == null
        || !("http".equalsIgnoreCase(scheme) || "https".equalsIgnoreCase(scheme))
        || uri.getHost() == null
        || uri.getRawQuery() != null
        || uri.getRawFragment() != null) {
      throw new InvalidArgumentException(
          "baseUrl must be an absolute http(s) URL, e.g."
              + " https://api.labyrinthe-rdc.com/api/public/v1.");
    }
    String path = uri.getRawPath();
    return path == null || path.isEmpty() ? trimmed + API_PATH : trimmed;
  }

  /**
   * Envoi synchrone, avec relances.
   *
   * @param request requête
   * @return la réponse 2xx
   */
  public Response send(Request request) {
    Call call = prepare(request);
    for (int attempt = 0; ; attempt++) {
      logAttempt(call, attempt);
      long started = System.nanoTime();
      Outcome outcome;
      HttpResponse<byte[]> response = null;
      String failure = null;
      boolean timedOut = false;
      try {
        response = http.send(call.request, HttpResponse.BodyHandlers.ofByteArray());
      } catch (HttpTimeoutException e) {
        timedOut = true;
        failure = "timeout";
      } catch (IOException e) {
        failure = describe(e);
      } catch (InterruptedException e) {
        Thread.currentThread().interrupt();
        throw interrupted(call);
      }
      // Hors du bloc catch : l'exception d'origine n'est jamais chaînée à l'erreur levée.
      outcome =
          response == null
              ? afterNetworkFailure(call, attempt, timedOut, failure)
              : afterResponse(call, attempt, response, started);
      if (outcome.response != null) {
        return outcome.response;
      }
      try {
        scheduler.sleep(outcome.delay);
      } catch (InterruptedException e) {
        Thread.currentThread().interrupt();
        throw interrupted(call);
      }
    }
  }

  /**
   * Envoi asynchrone, avec les mêmes relances. Le futur échoue avec une {@link LabyrintheException}
   * (enveloppée dans une {@link CompletionException} par {@code join()}).
   *
   * @param request requête
   * @return la réponse 2xx
   */
  public CompletableFuture<Response> sendAsync(Request request) {
    Call call;
    try {
      call = prepare(request);
    } catch (LabyrintheException e) {
      return CompletableFuture.failedFuture(e);
    }
    return attemptAsync(call, 0);
  }

  private CompletableFuture<Response> attemptAsync(Call call, int attempt) {
    logAttempt(call, attempt);
    long started = System.nanoTime();
    CompletableFuture<HttpResponse<byte[]>> sent;
    try {
      sent = http.sendAsync(call.request, HttpResponse.BodyHandlers.ofByteArray());
    } catch (RuntimeException e) {
      sent = CompletableFuture.failedFuture(e);
    }
    return sent.handle(
            (response, error) -> {
              Outcome outcome;
              try {
                if (error != null) {
                  Throwable cause = unwrap(error);
                  boolean timedOut = cause instanceof HttpTimeoutException;
                  outcome =
                      afterNetworkFailure(
                          call, attempt, timedOut, timedOut ? "timeout" : describe(cause));
                } else {
                  outcome = afterResponse(call, attempt, response, started);
                }
              } catch (LabyrintheException e) {
                return CompletableFuture.<Response>failedFuture(e);
              }
              if (outcome.response != null) {
                return CompletableFuture.completedFuture(outcome.response);
              }
              return CompletableFuture.runAsync(() -> {}, scheduler.delayed(outcome.delay))
                  .thenCompose(ignored -> attemptAsync(call, attempt + 1));
            })
        .thenCompose(Function.identity());
  }

  // -- préparation --------------------------------------------------------------------------------

  private Call prepare(Request request) {
    Duration callTimeout = request.timeout != null ? request.timeout : timeout;
    if (callTimeout.isNegative() || callTimeout.isZero()) {
      throw new InvalidArgumentException("timeout must be greater than 0.");
    }
    StringBuilder url = new StringBuilder(baseUrl).append(request.path);
    if (!request.query.isEmpty()) {
      url.append('?');
      boolean first = true;
      for (Map.Entry<String, String> entry : request.query) {
        if (!first) {
          url.append('&');
        }
        first = false;
        url.append(Redact.encode(entry.getKey()))
            .append('=')
            .append(Redact.encode(entry.getValue()));
      }
    }
    HttpRequest.Builder builder =
        HttpRequest.newBuilder(URI.create(url.toString()))
            .timeout(callTimeout)
            .header("Authorization", "Bearer " + apiKey)
            .header("Accept", "application/json")
            .header("User-Agent", userAgent);
    request.headers.forEach(builder::header);
    if (request.body != null) {
      // Sérialisé une seule fois : chaque relance renvoie exactement les mêmes octets.
      builder
          .header("Content-Type", "application/json")
          .method(
              request.method,
              HttpRequest.BodyPublishers.ofString(
                  Json.write(request.body), StandardCharsets.UTF_8));
    } else {
      builder.method(request.method, HttpRequest.BodyPublishers.noBody());
    }
    return new Call(
        builder.build(),
        request.method,
        Redact.path(request.path, request.query),
        request.mode,
        callTimeout,
        request.onResponse);
  }

  // -- décisions ----------------------------------------------------------------------------------

  private Outcome afterNetworkFailure(Call call, int attempt, boolean timedOut, String reason) {
    if (call.mode == RetryMode.SAFE && attempt < maxRetries) {
      Duration delay = backoff(attempt);
      LOG.log(
          Level.WARNING,
          () ->
              String.format(
                  Locale.ROOT,
                  "Labyrinthe Connect network error, retrying %s %s (attempt %d, in %d ms,"
                      + " timeout=%s)",
                  call.method,
                  call.logPath,
                  attempt + 1,
                  delay.toMillis(),
                  timedOut));
      return new Outcome(null, delay);
    }
    // DEBUG : l'erreur est levée vers l'appelant, qui décide de la journaliser.
    LOG.log(
        Level.DEBUG,
        () ->
            String.format(
                Locale.ROOT,
                "Labyrinthe Connect network error on %s %s (timeout=%s)",
                call.method,
                call.logPath,
                timedOut));
    String suffix =
        call.mode == RetryMode.THROTTLED_ONLY
            ? " The request may have been processed; it was not retried automatically."
            : "";
    if (timedOut) {
      throw new ApiTimeoutException(
          "The Labyrinthe Connect API did not answer within "
              + seconds(call.timeout)
              + " s ("
              + call.method
              + " "
              + call.logPath
              + ")."
              + suffix);
    }
    throw new ApiConnectionException(
        "Could not reach the Labyrinthe Connect API ("
            + call.method
            + " "
            + call.logPath
            + "): "
            + reason
            + "."
            + suffix);
  }

  private Outcome afterResponse(
      Call call, int attempt, HttpResponse<byte[]> response, long started) {
    int status = response.statusCode();
    Map<String, String> headers = lowerCase(response.headers());
    String text = new String(response.body(), StandardCharsets.UTF_8);
    LOG.log(
        Level.DEBUG,
        () ->
            String.format(
                Locale.ROOT,
                "Labyrinthe Connect response %s %s: HTTP %d in %d ms%s",
                call.method,
                call.logPath,
                status,
                TimeUnit.NANOSECONDS.toMillis(System.nanoTime() - started),
                "true".equals(headers.get("idempotent-replayed")) ? " (idempotent replay)" : ""));
    if (status >= 200 && status < 300) {
      Response success = new Response(status, decode(text, status), headers);
      if (call.onResponse != null) {
        call.onResponse.accept(success);
      }
      return new Outcome(success, null);
    }
    ApiException error = ErrorMapper.fromResponse(status, text, headers);
    if (attempt < maxRetries && isRetryable(call.mode, error)) {
      Optional<Duration> delay = retryDelay(error, attempt);
      if (delay.isPresent()) {
        LOG.log(
            Level.WARNING,
            () ->
                String.format(
                    Locale.ROOT,
                    "Labyrinthe Connect request failed, retrying %s %s: HTTP %d %s (attempt %d, in"
                        + " %d ms)",
                    call.method,
                    call.logPath,
                    status,
                    error.code(),
                    attempt + 1,
                    delay.get().toMillis()));
        return new Outcome(null, delay.get());
      }
    }
    LOG.log(
        Level.DEBUG,
        () ->
            String.format(
                Locale.ROOT,
                "Labyrinthe Connect API error on %s %s: HTTP %d %s",
                call.method,
                call.logPath,
                status,
                error.code()));
    throw error;
  }

  private static boolean isRetryable(RetryMode mode, ApiException error) {
    // Seulement la limite générique : OTP_RESEND_TOO_SOON / OTP_RATE_LIMITED sont des limites
    // métier.
    if (error.statusCode() == 429) {
      return "TOO_MANY_REQUESTS".equals(error.code());
    }
    // La requête d'origine (même Idempotency-Key) est encore en cours : la relance est un rejeu.
    if (IN_PROGRESS.equals(error.code())) {
      return mode == RetryMode.SAFE;
    }
    return mode == RetryMode.SAFE && error.statusCode() >= 500;
  }

  /** Délai avant la prochaine tentative ; vide pour abandonner (attente demandée trop longue). */
  private Optional<Duration> retryDelay(ApiException error, int attempt) {
    if (error.statusCode() == 429 || IN_PROGRESS.equals(error.code())) {
      Optional<Duration> retryAfter = RetryAfter.of(error.headers(), error.details());
      if (retryAfter.isPresent()) {
        return retryAfter.get().compareTo(maxRetryWait) <= 0 ? retryAfter : Optional.empty();
      }
    }
    return Optional.of(backoff(attempt));
  }

  /** Backoff exponentiel avec jitter : 0,5 s, 1 s, 2 s… (50 à 100 %), plafonné. */
  Duration backoff(int attempt) {
    long base = BASE_DELAY.toMillis() << Math.min(attempt, 20);
    long capped = Math.min(MAX_BACKOFF.toMillis(), base);
    double jitter = 0.5 + ThreadLocalRandom.current().nextDouble() * 0.5;
    long delay = Math.round(capped * jitter);
    return Duration.ofMillis(Math.min(maxRetryWait.toMillis(), delay));
  }

  private static void logAttempt(Call call, int attempt) {
    LOG.log(
        Level.DEBUG,
        () ->
            String.format(
                Locale.ROOT,
                "Labyrinthe Connect request %s %s (attempt %d)",
                call.method,
                call.logPath,
                attempt + 1));
  }

  // -- utilitaires --------------------------------------------------------------------------------

  private static Map<String, Object> decode(String text, int status) {
    if (text.trim().isEmpty()) {
      return Collections.emptyMap();
    }
    Object decoded;
    try {
      decoded = Json.parse(text);
    } catch (Json.JsonException e) {
      throw new UnexpectedResponseException(
          "Unexpected API response: the body is not valid JSON.", status);
    }
    if (!(decoded instanceof Map)) {
      throw new UnexpectedResponseException(
          "Unexpected API response: a JSON object was expected.", status);
    }
    @SuppressWarnings("unchecked")
    Map<String, Object> map = (Map<String, Object>) decoded;
    return map;
  }

  private static Map<String, String> lowerCase(HttpHeaders headers) {
    Map<String, String> map = new LinkedHashMap<>();
    headers
        .map()
        .forEach(
            (name, values) -> {
              if (!values.isEmpty()) {
                map.put(name.toLowerCase(Locale.ROOT), values.get(0));
              }
            });
    return Collections.unmodifiableMap(map);
  }

  private static Throwable unwrap(Throwable error) {
    Throwable current = error;
    while ((current instanceof CompletionException || current instanceof ExecutionException)
        && current.getCause() != null) {
      current = current.getCause();
    }
    return current;
  }

  /** Message court et sans URL : l'exception d'origine n'est jamais chaînée. */
  static String describe(Throwable error) {
    String name = error.getClass().getSimpleName();
    String message = error.getMessage();
    message = message == null ? "" : Redact.stripQueryStrings(message).trim();
    return message.isEmpty() ? name : name + ": " + message;
  }

  private static ApiConnectionException interrupted(Call call) {
    return new ApiConnectionException(
        "Interrupted while calling the Labyrinthe Connect API ("
            + call.method
            + " "
            + call.logPath
            + ").");
  }

  private static String seconds(Duration duration) {
    return BigDecimal.valueOf(duration.toMillis(), 3).stripTrailingZeros().toPlainString();
  }

  @Override
  public String toString() {
    return "Transport(apiKey="
        + Redact.apiKey(apiKey)
        + ", baseUrl="
        + baseUrl
        + ", maxRetries="
        + maxRetries
        + ")";
  }
}

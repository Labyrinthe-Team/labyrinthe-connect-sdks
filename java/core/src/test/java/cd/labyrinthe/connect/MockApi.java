package cd.labyrinthe.connect;

import cd.labyrinthe.connect.internal.Json;
import cd.labyrinthe.connect.internal.Transport;
import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetAddress;
import java.net.InetSocketAddress;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.ArrayDeque;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Deque;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.concurrent.CopyOnWriteArrayList;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/** Serveur HTTP simulé (JDK) : réponses en file, requêtes enregistrées. */
final class MockApi implements AutoCloseable {

  static final String KEY = "lsk_test_s3cr3tK3yValue42";

  /** Une réponse programmée. */
  static final class Reply {
    final int status;
    final String body;
    final Map<String, String> headers = new LinkedHashMap<>();
    long delayMillis;
    boolean disconnect;

    Reply(int status, String body) {
      this.status = status;
      this.body = body;
    }

    Reply header(String name, String value) {
      headers.put(name, value);
      return this;
    }

    Reply delay(long millis) {
      this.delayMillis = millis;
      return this;
    }
  }

  /** Une requête reçue. */
  static final class Recorded {
    final String method;
    final String path;
    final String rawQuery;
    final Map<String, String> headers;
    final String body;

    Recorded(
        String method, String path, String rawQuery, Map<String, String> headers, String body) {
      this.method = method;
      this.path = path;
      this.rawQuery = rawQuery;
      this.headers = headers;
      this.body = body;
    }

    String header(String name) {
      return headers.get(name.toLowerCase(Locale.ROOT));
    }

    Map<String, String> query() {
      Map<String, String> query = new LinkedHashMap<>();
      if (rawQuery != null) {
        for (String pair : rawQuery.split("&")) {
          String[] parts = pair.split("=", 2);
          query.put(
              URLDecoder.decode(parts[0], StandardCharsets.UTF_8),
              parts.length > 1 ? URLDecoder.decode(parts[1], StandardCharsets.UTF_8) : "");
        }
      }
      return query;
    }

    @SuppressWarnings("unchecked")
    Map<String, Object> json() {
      return (Map<String, Object>) Json.parse(body);
    }
  }

  /** Planificateur de test : enregistre les attentes sans attendre. */
  static final class RecordingScheduler implements Transport.Scheduler {
    final List<Duration> sleeps = new CopyOnWriteArrayList<>();

    @Override
    public void sleep(Duration delay) {
      sleeps.add(delay);
    }

    @Override
    public java.util.concurrent.Executor delayed(Duration delay) {
      sleeps.add(delay);
      return Runnable::run;
    }
  }

  private final HttpServer server;
  private final ExecutorService executor = Executors.newCachedThreadPool();
  private final Deque<Reply> replies = new ArrayDeque<>();
  final List<Recorded> requests = new CopyOnWriteArrayList<>();
  final RecordingScheduler scheduler = new RecordingScheduler();

  MockApi() {
    try {
      server = HttpServer.create(new InetSocketAddress(InetAddress.getLoopbackAddress(), 0), 0);
    } catch (IOException e) {
      throw new IllegalStateException(e);
    }
    server.setExecutor(executor);
    server.createContext("/", this::handle);
    server.start();
  }

  // -- fabrique de réponses -----------------------------------------------------------------------

  static Reply json(int status, String body) {
    return new Reply(status, body).header("Content-Type", "application/json");
  }

  static Reply data(int status, String dataJson) {
    return json(status, "{\"data\":" + dataJson + "}");
  }

  static Reply error(int status, String code, String detailsJson) {
    return json(
        status,
        "{\"error\":{\"code\":\""
            + code
            + "\",\"message\":\"Message de l'API.\",\"details\":"
            + detailsJson
            + "}}");
  }

  static Reply error(int status, String code) {
    return error(status, code, "{}");
  }

  static Reply disconnect() {
    Reply reply = new Reply(0, "");
    reply.disconnect = true;
    return reply;
  }

  static String message() {
    return message("queued", "243812345678");
  }

  static String message(String status, String recipient) {
    return "{\"id\":\"9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10\",\"reference\":\"order-1042\","
        + "\"recipient\":\""
        + recipient
        + "\",\"sender\":\"MYSHOP\",\"encoding\":\"gsm7\",\"status\":\""
        + status
        + "\",\"segments\":1,\"credits_charged\":1,\"campaign_id\":null,"
        + "\"provider_message_id\":null,\"error_code\":null,\"error_message\":null,"
        + "\"created_at\":\"2026-11-05T09:39:11.000000Z\",\"submitted_at\":null,"
        + "\"delivered_at\":null}";
  }

  static Reply balance() {
    return data(200, "{\"balance\":5,\"held\":0}");
  }

  static Reply page(int current, int last, int total, String... messages) {
    return json(
        200,
        "{\"data\":["
            + String.join(",", messages)
            + "],\"links\":{\"first\":null,\"last\":null,\"prev\":null,\"next\":null},"
            + "\"meta\":{\"current_page\":"
            + current
            + ",\"from\":1,\"last_page\":"
            + last
            + ",\"path\":\"http://x/api/public/v1/sms\",\"per_page\":15,\"to\":1,\"total\":"
            + total
            + "}}");
  }

  // -- utilisation --------------------------------------------------------------------------------

  MockApi enqueue(Reply... list) {
    synchronized (replies) {
      Collections.addAll(replies, list);
    }
    return this;
  }

  String url() {
    return "http://127.0.0.1:" + server.getAddress().getPort();
  }

  LabyrintheClient.Builder builder() {
    return LabyrintheClient.builder()
        .apiKey(KEY)
        .baseUrl(url())
        .environment(name -> null)
        .scheduler(scheduler);
  }

  LabyrintheClient client() {
    return builder().build();
  }

  Recorded last() {
    return requests.get(requests.size() - 1);
  }

  private void handle(HttpExchange exchange) throws IOException {
    String body;
    try (InputStream in = exchange.getRequestBody()) {
      body = new String(in.readAllBytes(), StandardCharsets.UTF_8);
    }
    Map<String, String> headers = new LinkedHashMap<>();
    exchange
        .getRequestHeaders()
        .forEach((name, values) -> headers.put(name.toLowerCase(Locale.ROOT), values.get(0)));
    requests.add(
        new Recorded(
            exchange.getRequestMethod(),
            exchange.getRequestURI().getRawPath(),
            exchange.getRequestURI().getRawQuery(),
            headers,
            body));
    Reply reply;
    synchronized (replies) {
      reply = replies.pollFirst();
    }
    if (reply == null) {
      reply = error(500, "NO_REPLY_QUEUED");
    }
    if (reply.delayMillis > 0) {
      try {
        Thread.sleep(reply.delayMillis);
      } catch (InterruptedException e) {
        Thread.currentThread().interrupt();
      }
    }
    if (reply.disconnect) {
      // Fermeture sans réponse : le client voit une erreur réseau.
      exchange.getHttpContext().getServer();
      exchange.close();
      return;
    }
    byte[] bytes = reply.body.getBytes(StandardCharsets.UTF_8);
    reply.headers.forEach((name, value) -> exchange.getResponseHeaders().add(name, value));
    exchange.sendResponseHeaders(reply.status, bytes.length == 0 ? -1 : bytes.length);
    if (bytes.length > 0) {
      try (OutputStream out = exchange.getResponseBody()) {
        out.write(bytes);
      }
    }
    exchange.close();
  }

  List<String> paths() {
    List<String> paths = new ArrayList<>();
    for (Recorded r : requests) {
      paths.add(r.method + " " + r.path);
    }
    return paths;
  }

  @Override
  public void close() {
    server.stop(0);
    executor.shutdownNow();
  }
}

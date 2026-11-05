package cd.labyrinthe.connect.examples;

import cd.labyrinthe.connect.PhoneNumbers;
import cd.labyrinthe.connect.exception.InvalidWebhookPayloadException;
import cd.labyrinthe.connect.exception.SignatureVerificationException;
import cd.labyrinthe.connect.webhook.BalanceLow;
import cd.labyrinthe.connect.webhook.CampaignCompleted;
import cd.labyrinthe.connect.webhook.MessageStatusChanged;
import cd.labyrinthe.connect.webhook.WebhookEvent;
import cd.labyrinthe.connect.webhook.Webhooks;
import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;

/**
 * Webhook receiver with the JDK HTTP server, no framework.
 *
 * <pre>
 * LABYRINTHE_WEBHOOK_SECRET=… java … cd.labyrinthe.connect.examples.WebhookServer
 * # then POST http://localhost:3000/webhooks/labyrinthe (client area: Developers, Webhooks, Test)
 * </pre>
 */
public final class WebhookServer {

  private WebhookServer() {}

  /**
   * Entry point.
   *
   * @param args unused
   * @throws IOException if the port is not available
   */
  public static void main(String[] args) throws IOException {
    String secret = System.getenv("LABYRINTHE_WEBHOOK_SECRET");
    if (secret == null || secret.isEmpty()) {
      System.err.println("Set LABYRINTHE_WEBHOOK_SECRET (client area: Developers, Webhooks).");
      return;
    }
    HttpServer server = HttpServer.create(new InetSocketAddress(3000), 0);
    server.createContext("/webhooks/labyrinthe", exchange -> handle(exchange, secret));
    server.start();
    System.out.println("Listening on http://localhost:3000/webhooks/labyrinthe");
  }

  private static void handle(HttpExchange exchange, String secret) throws IOException {
    if (!"POST".equals(exchange.getRequestMethod())) {
      reply(exchange, 405, "{\"error\":\"method not allowed\"}");
      return;
    }
    byte[] body;
    try (InputStream in = exchange.getRequestBody()) {
      body = in.readAllBytes(); // the RAW body: never parse and re-serialise it before verifying
    }
    WebhookEvent event;
    try {
      event =
          Webhooks.constructEvent(
              body, exchange.getRequestHeaders().getFirst(Webhooks.SIGNATURE_HEADER), secret);
    } catch (SignatureVerificationException | InvalidWebhookPayloadException e) {
      reply(exchange, 400, "{\"error\":\"" + e.code() + "\"}");
      return;
    }

    // Deliveries are retried and may arrive out of order: deduplicate on event.id().
    if (event instanceof MessageStatusChanged) {
      MessageStatusChanged changed = (MessageStatusChanged) event;
      System.out.println(
          "Message "
              + changed.data().messageId()
              + " to "
              + PhoneNumbers.mask(changed.data().recipient())
              + ": "
              + changed.data().status());
    } else if (event instanceof CampaignCompleted) {
      System.out.println("Campaign done: " + ((CampaignCompleted) event).data().messagesByStatus());
    } else if (event instanceof BalanceLow) {
      System.out.println("Low balance: " + ((BalanceLow) event).data().balance() + " credits");
    } else {
      System.out.println("Event " + event.type() + " (" + event.id() + ")");
    }
    // Answer 2xx within 10 seconds; queue slow work.
    reply(exchange, 200, "{\"received\":true}");
  }

  private static void reply(HttpExchange exchange, int status, String json) throws IOException {
    byte[] bytes = json.getBytes(StandardCharsets.UTF_8);
    exchange.getResponseHeaders().set("Content-Type", "application/json");
    exchange.sendResponseHeaders(status, bytes.length);
    try (OutputStream out = exchange.getResponseBody()) {
      out.write(bytes);
    }
  }
}

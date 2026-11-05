package cd.labyrinthe.connect.spring;

import cd.labyrinthe.connect.exception.InvalidWebhookPayloadException;
import cd.labyrinthe.connect.exception.SignatureVerificationException;
import cd.labyrinthe.connect.webhook.WebhookEvent;
import cd.labyrinthe.connect.webhook.Webhooks;
import java.time.Clock;
import java.time.Duration;
import java.util.Objects;
import org.springframework.context.ApplicationEventPublisher;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RestController;

/**
 * Signed webhook endpoint: {@code POST ${labyrinthe.webhook.path}} (default {@code
 * /webhooks/labyrinthe}).
 *
 * <p>The signature is verified on the raw body; a verified event is published with {@link
 * ApplicationEventPublisher#publishEvent(Object)}, so you listen to the type you need:
 *
 * <pre>{@code
 * @EventListener
 * void onStatus(MessageStatusChanged event) {
 *   orders.updateSmsStatus(event.data().messageId(), event.data().status());
 * }
 * }</pre>
 *
 * <p>Answers {@code 200 {"received":true}}, or {@code 400} for a wrong signature, an expired
 * timestamp or an invalid body. A listener that throws gives a {@code 500}: Labyrinthe Connect
 * retries the delivery (up to 5 attempts) — deduplicate on {@link WebhookEvent#id()}. Answer within
 * 10 seconds: hand slow work over to {@code @Async} listeners or a queue.
 */
@RestController
public final class LabyrintheWebhookController {

  private final String secret;
  private final Duration tolerance;
  private final ApplicationEventPublisher publisher;
  private final Clock clock;

  /**
   * Creates the endpoint.
   *
   * @param secret the endpoint secret
   * @param tolerance max age of a delivery
   * @param publisher publisher of the verified events
   */
  public LabyrintheWebhookController(
      String secret, Duration tolerance, ApplicationEventPublisher publisher) {
    this(secret, tolerance, publisher, Clock.systemUTC());
  }

  LabyrintheWebhookController(
      String secret, Duration tolerance, ApplicationEventPublisher publisher, Clock clock) {
    if (secret == null || secret.isEmpty()) {
      throw new IllegalArgumentException("labyrinthe.webhook.secret cannot be empty.");
    }
    this.secret = secret;
    this.tolerance = tolerance == null ? Webhooks.DEFAULT_TOLERANCE : tolerance;
    this.publisher = Objects.requireNonNull(publisher, "publisher");
    this.clock = Objects.requireNonNull(clock, "clock");
  }

  /**
   * Receives a delivery.
   *
   * @param body raw request body
   * @param signature {@code X-Signature} header
   * @return 200 when verified and handled, 400 otherwise
   */
  @PostMapping(
      path = "${labyrinthe.webhook.path:/webhooks/labyrinthe}",
      produces = MediaType.APPLICATION_JSON_VALUE)
  public ResponseEntity<String> receive(
      @RequestBody(required = false) byte[] body,
      @RequestHeader(name = Webhooks.SIGNATURE_HEADER, required = false) String signature) {
    WebhookEvent event;
    try {
      event =
          Webhooks.constructEvent(
              body == null ? new byte[0] : body, signature, secret, tolerance, clock);
    } catch (SignatureVerificationException | InvalidWebhookPayloadException e) {
      return ResponseEntity.badRequest()
          .contentType(MediaType.APPLICATION_JSON)
          .body("{\"error\":\"" + e.code() + "\"}");
    }
    publisher.publishEvent(event);
    return ResponseEntity.ok().contentType(MediaType.APPLICATION_JSON).body("{\"received\":true}");
  }

  @Override
  public String toString() {
    return "LabyrintheWebhookController(secret=****, tolerance=" + tolerance + ")";
  }
}

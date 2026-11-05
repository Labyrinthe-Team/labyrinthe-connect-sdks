package cd.labyrinthe.connect.examples;

import cd.labyrinthe.connect.Estimate;
import cd.labyrinthe.connect.EstimateSmsParams;
import cd.labyrinthe.connect.LabyrintheClient;
import cd.labyrinthe.connect.Message;
import cd.labyrinthe.connect.SendSmsParams;
import cd.labyrinthe.connect.exception.InsufficientCreditsException;
import cd.labyrinthe.connect.exception.LabyrintheException;

/**
 * Sends one SMS and prints its status.
 *
 * <pre>
 * LABYRINTHE_API_KEY=lsk_test_… java … cd.labyrinthe.connect.examples.SendSms 0812345678
 * </pre>
 *
 * <p>With a {@code lsk_test_} key nothing is sent and nothing is billed.
 */
public final class SendSms {

  private SendSms() {}

  /**
   * Entry point.
   *
   * @param args the recipient (optional)
   */
  public static void main(String[] args) {
    String to = args.length > 0 ? args[0] : "0812345678";
    LabyrintheClient client = LabyrintheClient.fromEnv(); // LABYRINTHE_API_KEY, LABYRINTHE_BASE_URL
    System.out.println("Test mode: " + client.isTestMode());

    String body = "Your order 1042 is ready.";
    Estimate estimate =
        client.sms().estimate(EstimateSmsParams.builder().body(body).addRecipient(to).build());
    System.out.println(
        "Cost: " + estimate.totalCredits() + " credit(s), encoding " + estimate.encoding());

    try {
      Message message =
          client
              .sms()
              .send(
                  SendSmsParams.builder()
                      .to(to)
                      .body(body)
                      .reference("order-1042")
                      // Derived from the business event: a retry of this program never sends twice.
                      .idempotencyKey("order-1042-ready")
                      .build());
      System.out.println("Message " + message.id() + ": " + message.status());

      Message latest = client.sms().get(message.id());
      System.out.println("Now: " + latest.status() + (latest.status().isFinal() ? " (final)" : ""));
    } catch (InsufficientCreditsException e) {
      System.err.println(
          "Not enough credits: "
              + e.available().orElse(0)
              + " available, "
              + e.required().orElse(0)
              + " required.");
    } catch (LabyrintheException e) {
      System.err.println("Labyrinthe Connect error " + e.code() + ": " + e.getMessage());
    }
  }
}

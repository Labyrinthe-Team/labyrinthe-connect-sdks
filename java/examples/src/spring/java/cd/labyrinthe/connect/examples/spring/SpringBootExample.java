package cd.labyrinthe.connect.examples.spring;

import cd.labyrinthe.connect.LabyrintheClient;
import cd.labyrinthe.connect.Message;
import cd.labyrinthe.connect.OtpSendResult;
import cd.labyrinthe.connect.OtpVerifyResult;
import cd.labyrinthe.connect.webhook.BalanceLow;
import cd.labyrinthe.connect.webhook.MessageStatusChanged;
import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.context.event.EventListener;
import org.springframework.stereotype.Component;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

/**
 * Spring Boot application using the starter.
 *
 * <pre>
 * # application.properties
 * labyrinthe.api-key=${LABYRINTHE_API_KEY}
 * labyrinthe.webhook.secret=${LABYRINTHE_WEBHOOK_SECRET}
 * </pre>
 *
 * <p>The starter creates the {@link LabyrintheClient} bean and the signed endpoint {@code POST
 * /webhooks/labyrinthe}; verified events are Spring application events.
 */
@SpringBootApplication
public class SpringBootExample {

  /**
   * Entry point.
   *
   * @param args Spring Boot arguments
   */
  public static void main(String[] args) {
    SpringApplication.run(SpringBootExample.class, args);
  }

  /** Sends SMS and OTP codes with the auto-configured client. */
  @RestController
  static class Controller {
    private final LabyrintheClient labyrinthe;

    Controller(LabyrintheClient labyrinthe) {
      this.labyrinthe = labyrinthe;
    }

    @PostMapping("/orders/ready")
    String orderReady(@RequestParam String phone, @RequestParam String order) {
      Message message = labyrinthe.sms().send(phone, "Your order " + order + " is ready.");
      return message.id();
    }

    @PostMapping("/login/otp")
    String sendCode(@RequestParam String phone) {
      OtpSendResult otp = labyrinthe.otp().send(phone);
      return otp.otpId(); // in a real app: keep it in the server-side session
    }

    @PostMapping("/login/verify")
    boolean verify(@RequestParam String otpId, @RequestParam String code) {
      OtpVerifyResult result = labyrinthe.otp().verify(otpId, code);
      return result.isVerified();
    }
  }

  /** Webhook events published by the starter. */
  @Component
  static class Events {
    @EventListener
    void onStatus(MessageStatusChanged event) {
      System.out.println(event.data().messageId() + " is now " + event.data().status());
    }

    @EventListener
    void onLowBalance(BalanceLow event) {
      System.out.println("Only " + event.data().balance() + " credits left: top up.");
    }
  }
}

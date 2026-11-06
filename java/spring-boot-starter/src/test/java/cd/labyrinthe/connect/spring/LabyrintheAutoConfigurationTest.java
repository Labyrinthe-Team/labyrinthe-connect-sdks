package cd.labyrinthe.connect.spring;

import static org.assertj.core.api.Assertions.assertThat;
import static org.junit.jupiter.api.Assumptions.assumeTrue;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import cd.labyrinthe.connect.LabyrintheClient;
import cd.labyrinthe.connect.MessageStatus;
import cd.labyrinthe.connect.webhook.MessageStatusChanged;
import cd.labyrinthe.connect.webhook.OtpFailed;
import cd.labyrinthe.connect.webhook.OtpVerified;
import cd.labyrinthe.connect.webhook.TestPing;
import cd.labyrinthe.connect.webhook.WebhookEvent;
import cd.labyrinthe.connect.webhook.Webhooks;
import java.time.Instant;
import java.util.List;
import java.util.concurrent.CopyOnWriteArrayList;
import org.junit.jupiter.api.Test;
import org.springframework.boot.autoconfigure.AutoConfigurations;
import org.springframework.boot.autoconfigure.http.HttpMessageConvertersAutoConfiguration;
import org.springframework.boot.autoconfigure.web.servlet.DispatcherServletAutoConfiguration;
import org.springframework.boot.autoconfigure.web.servlet.WebMvcAutoConfiguration;
import org.springframework.boot.test.context.runner.ApplicationContextRunner;
import org.springframework.boot.test.context.runner.WebApplicationContextRunner;
import org.springframework.context.annotation.Configuration;
import org.springframework.context.event.EventListener;
import org.springframework.test.web.servlet.MockMvc;
import org.springframework.test.web.servlet.setup.MockMvcBuilders;

class LabyrintheAutoConfigurationTest {

  private static final String SECRET = "whsec_spring_test";

  private final ApplicationContextRunner runner =
      new ApplicationContextRunner()
          .withConfiguration(AutoConfigurations.of(LabyrintheAutoConfiguration.class));

  private final WebApplicationContextRunner web =
      new WebApplicationContextRunner()
          .withConfiguration(
              AutoConfigurations.of(
                  LabyrintheAutoConfiguration.class,
                  DispatcherServletAutoConfiguration.class,
                  WebMvcAutoConfiguration.class,
                  HttpMessageConvertersAutoConfiguration.class))
          .withUserConfiguration(Listener.class);

  /** Collecte les événements publiés. */
  @Configuration(proxyBeanMethods = false)
  static class Listener {
    final List<WebhookEvent> all = new CopyOnWriteArrayList<>();
    final List<MessageStatusChanged> statuses = new CopyOnWriteArrayList<>();
    final List<OtpVerified> verified = new CopyOnWriteArrayList<>();
    final List<OtpFailed> failed = new CopyOnWriteArrayList<>();

    @EventListener
    void any(WebhookEvent event) {
      all.add(event);
    }

    @EventListener
    void status(MessageStatusChanged event) {
      statuses.add(event);
    }

    @EventListener
    void otpVerified(OtpVerified event) {
      verified.add(event);
    }

    @EventListener
    void otpFailed(OtpFailed event) {
      failed.add(event);
    }
  }

  @Test
  void createsTheClientFromProperties() {
    runner
        .withPropertyValues(
            "labyrinthe.api-key=lsk_test_spring",
            "labyrinthe.base-url=http://localhost:8000",
            "labyrinthe.timeout=5s",
            "labyrinthe.max-retries=0",
            "labyrinthe.default-sender=MYSHOP")
        .run(
            context -> {
              assertThat(context).hasSingleBean(LabyrintheClient.class);
              LabyrintheClient client = context.getBean(LabyrintheClient.class);
              assertThat(client.isTestMode()).isTrue();
              assertThat(client.baseUrl()).isEqualTo("http://localhost:8000/api/public/v1");
              assertThat(context.getBean(LabyrintheProperties.class).toString())
                  .doesNotContain("lsk_test_spring");
            });
  }

  @Test
  void noKeyMeansNoClient() {
    assumeTrue(System.getenv("LABYRINTHE_API_KEY") == null, "LABYRINTHE_API_KEY is set");
    runner.run(context -> assertThat(context).doesNotHaveBean(LabyrintheClient.class));
  }

  @Test
  void invalidPropertiesFailTheStartup() {
    runner
        .withPropertyValues("labyrinthe.api-key=lsk_test_x", "labyrinthe.default-country-code=+243")
        .run(context -> assertThat(context).hasFailed());
  }

  @Test
  void appliesCustomizersAndBacksOffForAUserClient() {
    runner
        .withPropertyValues("labyrinthe.api-key=lsk_test_spring")
        .withBean(
            LabyrintheClientBuilderCustomizer.class,
            () -> builder -> builder.baseUrl("https://custom.test"))
        .run(
            context ->
                assertThat(context.getBean(LabyrintheClient.class).baseUrl())
                    .isEqualTo("https://custom.test/api/public/v1"));
    LabyrintheClient mine = LabyrintheClient.builder().apiKey("lsk_live_mine").build();
    runner
        .withPropertyValues("labyrinthe.api-key=lsk_test_spring")
        .withBean(LabyrintheClient.class, () -> mine)
        .run(context -> assertThat(context.getBean(LabyrintheClient.class)).isSameAs(mine));
  }

  @Test
  void webhookEndpointOnlyWithASecretInAWebApplication() {
    web.run(context -> assertThat(context).doesNotHaveBean(LabyrintheWebhookController.class));
    web.withPropertyValues(
            "labyrinthe.webhook.secret=" + SECRET, "labyrinthe.webhook.enabled=false")
        .run(context -> assertThat(context).doesNotHaveBean(LabyrintheWebhookController.class));
    runner
        .withPropertyValues("labyrinthe.webhook.secret=" + SECRET)
        .run(context -> assertThat(context).doesNotHaveBean(LabyrintheWebhookController.class));
    web.withPropertyValues("labyrinthe.webhook.secret=" + SECRET)
        .run(context -> assertThat(context).hasSingleBean(LabyrintheWebhookController.class));
  }

  private static String delivery(String event, String data, long timestamp) {
    return "{\"id\":\"evt-1\",\"event\":\""
        + event
        + "\",\"data\":"
        + data
        + ",\"timestamp\":"
        + timestamp
        + "}";
  }

  @Test
  void verifiesAndPublishesWebhookEvents() {
    web.withPropertyValues(
            "labyrinthe.webhook.secret=" + SECRET, "labyrinthe.webhook.path=/hooks/sms")
        .run(
            context -> {
              MockMvc mvc = MockMvcBuilders.webAppContextSetup(context).build();
              Listener listener = context.getBean(Listener.class);
              long now = Instant.now().getEpochSecond();
              String body =
                  delivery(
                      "message.status_changed",
                      "{\"message_id\":\"m1\",\"recipient\":\"243812345678\",\"status\":\"delivered\","
                          + "\"provider_message_id\":null,\"campaign_id\":null}",
                      now);

              mvc.perform(
                      post("/hooks/sms")
                          .contentType("application/json")
                          .header("X-Signature", Webhooks.sign(body, SECRET))
                          .content(body))
                  .andExpect(status().isOk())
                  .andExpect(content().json("{\"received\":true}"));
              assertThat(listener.statuses).hasSize(1);
              assertThat(listener.statuses.get(0).data().status())
                  .isEqualTo(MessageStatus.DELIVERED);

              String ping = delivery("test.ping", "{}", now);
              mvc.perform(
                      post("/hooks/sms")
                          .contentType("application/json")
                          .header("X-Signature", Webhooks.sign(ping, SECRET))
                          .content(ping))
                  .andExpect(status().isOk());
              assertThat(listener.all).hasSize(2);
              assertThat(listener.all.get(1)).isInstanceOf(TestPing.class);
              assertThat(listener.statuses).hasSize(1);

              // Événements OTP (API 1.2.0) : publiés avec leur type.
              String otpOk =
                  delivery(
                      "otp.verified",
                      "{\"otp_id\":\"o1\",\"phone\":\"243812345678\",\"status\":\"verified\","
                          + "\"reason\":null,\"verified_at\":\"2026-11-06T09:41:02+00:00\"}",
                      now);
              String otpKo =
                  delivery(
                      "otp.failed",
                      "{\"otp_id\":\"o2\",\"phone\":\"243812345678\",\"status\":\"failed\","
                          + "\"reason\":\"max_attempts_reached\",\"verified_at\":null}",
                      now);
              for (String otp : List.of(otpOk, otpKo)) {
                mvc.perform(
                        post("/hooks/sms")
                            .contentType("application/json")
                            .header("X-Signature", Webhooks.sign(otp, SECRET))
                            .content(otp))
                    .andExpect(status().isOk());
              }
              assertThat(listener.verified).hasSize(1);
              assertThat(listener.verified.get(0).data().otpId()).isEqualTo("o1");
              assertThat(listener.failed).hasSize(1);
              assertThat(listener.all).hasSize(4);

              // Signature fausse, absente, corps modifié, horodatage expiré : 400, rien de publié.
              mvc.perform(
                      post("/hooks/sms")
                          .contentType("application/json")
                          .header("X-Signature", Webhooks.sign(body, "wrong"))
                          .content(body))
                  .andExpect(status().isBadRequest())
                  .andExpect(content().json("{\"error\":\"SIGNATURE_VERIFICATION_FAILED\"}"));
              mvc.perform(post("/hooks/sms").contentType("application/json").content(body))
                  .andExpect(status().isBadRequest());
              mvc.perform(
                      post("/hooks/sms")
                          .contentType("application/json")
                          .header("X-Signature", Webhooks.sign(body, SECRET))
                          .content(body.replace("delivered", "failed")))
                  .andExpect(status().isBadRequest());
              String old = delivery("test.ping", "{}", now - 3600);
              mvc.perform(
                      post("/hooks/sms")
                          .contentType("application/json")
                          .header("X-Signature", Webhooks.sign(old, SECRET))
                          .content(old))
                  .andExpect(status().isBadRequest());
              String notEnvelope = "[1,2,3]";
              mvc.perform(
                      post("/hooks/sms")
                          .contentType("application/json")
                          .header("X-Signature", Webhooks.sign(notEnvelope, SECRET))
                          .content(notEnvelope))
                  .andExpect(status().isBadRequest())
                  .andExpect(content().json("{\"error\":\"INVALID_WEBHOOK_PAYLOAD\"}"));
              assertThat(listener.all).hasSize(4);
            });
  }

  @Test
  void usesTheDefaultPath() {
    web.withPropertyValues("labyrinthe.webhook.secret=" + SECRET)
        .run(
            context -> {
              MockMvc mvc = MockMvcBuilders.webAppContextSetup(context).build();
              String ping = delivery("test.ping", "{}", Instant.now().getEpochSecond());
              mvc.perform(
                      post("/webhooks/labyrinthe")
                          .contentType("application/json")
                          .header("X-Signature", Webhooks.sign(ping, SECRET))
                          .content(ping))
                  .andExpect(status().isOk());
            });
  }

  @Test
  void neverShowsTheSecret() {
    assertThat(new LabyrintheWebhookController(SECRET, null, event -> {}).toString())
        .doesNotContain(SECRET);
  }
}

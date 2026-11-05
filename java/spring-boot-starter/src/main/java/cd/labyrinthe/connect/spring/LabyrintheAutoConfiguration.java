package cd.labyrinthe.connect.spring;

import cd.labyrinthe.connect.LabyrintheClient;
import org.springframework.beans.factory.ObjectProvider;
import org.springframework.boot.autoconfigure.AutoConfiguration;
import org.springframework.boot.autoconfigure.condition.ConditionOutcome;
import org.springframework.boot.autoconfigure.condition.ConditionalOnClass;
import org.springframework.boot.autoconfigure.condition.ConditionalOnMissingBean;
import org.springframework.boot.autoconfigure.condition.ConditionalOnWebApplication;
import org.springframework.boot.autoconfigure.condition.SpringBootCondition;
import org.springframework.boot.context.properties.EnableConfigurationProperties;
import org.springframework.context.ApplicationEventPublisher;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.ConditionContext;
import org.springframework.context.annotation.Conditional;
import org.springframework.context.annotation.Configuration;
import org.springframework.core.env.Environment;
import org.springframework.core.type.AnnotatedTypeMetadata;
import org.springframework.util.StringUtils;

/**
 * Auto-configuration of Labyrinthe Connect.
 *
 * <ul>
 *   <li>a {@link LabyrintheClient} bean, when {@code labyrinthe.api-key} (or the {@code
 *       LABYRINTHE_API_KEY} environment variable) is set and no client bean exists;
 *   <li>a signed webhook endpoint ({@code POST labyrinthe.webhook.path}, default {@code
 *       /webhooks/labyrinthe}) in Spring MVC applications, when {@code labyrinthe.webhook.secret}
 *       is set. Each verified event is published as a Spring application event.
 * </ul>
 */
@AutoConfiguration
@EnableConfigurationProperties(LabyrintheProperties.class)
public class LabyrintheAutoConfiguration {

  /** Creates the auto-configuration. */
  public LabyrintheAutoConfiguration() {}

  /**
   * The client, configured from {@code labyrinthe.*}.
   *
   * @param properties the properties
   * @param customizers builder customizers, applied in order
   * @return the client
   */
  @Bean
  @ConditionalOnMissingBean
  @Conditional(OnApiKeyCondition.class)
  public LabyrintheClient labyrintheClient(
      LabyrintheProperties properties,
      ObjectProvider<LabyrintheClientBuilderCustomizer> customizers) {
    LabyrintheClient.Builder builder =
        LabyrintheClient.builder()
            .timeout(properties.getTimeout())
            .maxRetries(properties.getMaxRetries())
            .maxRetryWait(properties.getMaxRetryWait())
            .defaultCountryCode(properties.getDefaultCountryCode());
    if (StringUtils.hasText(properties.getApiKey())) {
      builder.apiKey(properties.getApiKey());
    }
    if (StringUtils.hasText(properties.getBaseUrl())) {
      builder.baseUrl(properties.getBaseUrl());
    }
    if (StringUtils.hasText(properties.getDefaultSender())) {
      builder.defaultSender(properties.getDefaultSender());
    }
    customizers.orderedStream().forEach(customizer -> customizer.customize(builder));
    return builder.build();
  }

  /** The webhook endpoint (Spring MVC applications only). */
  @Configuration(proxyBeanMethods = false)
  @ConditionalOnWebApplication(type = ConditionalOnWebApplication.Type.SERVLET)
  @ConditionalOnClass(name = "org.springframework.web.servlet.DispatcherServlet")
  @Conditional(OnWebhookSecretCondition.class)
  static class WebhookConfiguration {

    @Bean
    @ConditionalOnMissingBean
    LabyrintheWebhookController labyrintheWebhookController(
        LabyrintheProperties properties, ApplicationEventPublisher publisher) {
      return new LabyrintheWebhookController(
          properties.getWebhook().getSecret(), properties.getWebhook().getTolerance(), publisher);
    }
  }

  /**
   * Une clé API est disponible : propriété {@code labyrinthe.api-key} ou variable d'environnement.
   */
  static final class OnApiKeyCondition extends SpringBootCondition {
    @Override
    public ConditionOutcome getMatchOutcome(
        ConditionContext context, AnnotatedTypeMetadata metadata) {
      Environment env = context.getEnvironment();
      if (StringUtils.hasText(env.getProperty("labyrinthe.api-key"))) {
        return ConditionOutcome.match("labyrinthe.api-key is set");
      }
      if (StringUtils.hasText(env.getProperty(LabyrintheClient.ENV_API_KEY))) {
        return ConditionOutcome.match(LabyrintheClient.ENV_API_KEY + " is set");
      }
      return ConditionOutcome.noMatch("no Labyrinthe Connect API key");
    }
  }

  /** Un secret de webhook est configuré et le point d'entrée n'est pas désactivé. */
  static final class OnWebhookSecretCondition extends SpringBootCondition {
    @Override
    public ConditionOutcome getMatchOutcome(
        ConditionContext context, AnnotatedTypeMetadata metadata) {
      Environment env = context.getEnvironment();
      if ("false".equalsIgnoreCase(env.getProperty("labyrinthe.webhook.enabled"))) {
        return ConditionOutcome.noMatch("labyrinthe.webhook.enabled is false");
      }
      return StringUtils.hasText(env.getProperty("labyrinthe.webhook.secret"))
          ? ConditionOutcome.match("labyrinthe.webhook.secret is set")
          : ConditionOutcome.noMatch("labyrinthe.webhook.secret is not set");
    }
  }
}

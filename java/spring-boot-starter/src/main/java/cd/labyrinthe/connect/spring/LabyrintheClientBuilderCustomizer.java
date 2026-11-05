package cd.labyrinthe.connect.spring;

import cd.labyrinthe.connect.LabyrintheClient;

/**
 * Customises the {@link LabyrintheClient.Builder} used for the auto-configured client, after the
 * {@code labyrinthe.*} properties were applied (e.g. to pass your own {@code HttpClient}).
 *
 * <pre>{@code
 * @Bean
 * LabyrintheClientBuilderCustomizer proxy() {
 *   return builder -> builder.httpClient(HttpClient.newBuilder().proxy(myProxy).build());
 * }
 * }</pre>
 */
@FunctionalInterface
public interface LabyrintheClientBuilderCustomizer {

  /**
   * Customises the builder.
   *
   * @param builder the builder, already configured from the properties
   */
  void customize(LabyrintheClient.Builder builder);
}

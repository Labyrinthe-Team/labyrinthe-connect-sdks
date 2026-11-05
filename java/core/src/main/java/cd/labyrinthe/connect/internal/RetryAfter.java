package cd.labyrinthe.connect.internal;

import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.ZonedDateTime;
import java.time.format.DateTimeFormatter;
import java.time.format.DateTimeParseException;
import java.util.Map;
import java.util.Optional;

/** Lecture du délai d'attente d'une réponse 429. Usage interne. */
public final class RetryAfter {

  private RetryAfter() {}

  /**
   * Délai demandé : en-tête {@code Retry-After} (secondes, éventuellement décimales, ou date HTTP),
   * sinon {@code details.retry_after_seconds}.
   *
   * @param headers en-têtes (noms en minuscules)
   * @param details détails de l'erreur
   * @return le délai, s'il est connu
   */
  public static Optional<Duration> of(Map<String, String> headers, Map<String, Object> details) {
    return of(headers, details, Clock.systemUTC());
  }

  static Optional<Duration> of(
      Map<String, String> headers, Map<String, Object> details, Clock clock) {
    String header = headers.get("retry-after");
    if (header != null && !header.trim().isEmpty()) {
      String value = header.trim();
      try {
        double seconds = Double.parseDouble(value);
        if (seconds >= 0 && !Double.isInfinite(seconds) && !Double.isNaN(seconds)) {
          return Optional.of(Duration.ofMillis(Math.round(seconds * 1000)));
        }
      } catch (NumberFormatException notANumber) {
        try {
          Instant at = ZonedDateTime.parse(value, DateTimeFormatter.RFC_1123_DATE_TIME).toInstant();
          Duration wait = Duration.between(clock.instant(), at);
          return Optional.of(wait.isNegative() ? Duration.ZERO : wait);
        } catch (DateTimeParseException notADate) {
          // en-tête illisible : on se rabat sur details.retry_after_seconds
        }
      }
    }
    Long seconds = JsonReader.toLong(details.get("retry_after_seconds"));
    return seconds == null || seconds < 0
        ? Optional.empty()
        : Optional.of(Duration.ofSeconds(seconds));
  }
}

package cd.labyrinthe.connect.internal;

import cd.labyrinthe.connect.exception.LabyrintheException;
import java.math.BigDecimal;
import java.math.BigInteger;
import java.time.Instant;
import java.time.LocalDateTime;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.time.format.DateTimeFormatter;
import java.time.format.DateTimeParseException;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Map;
import java.util.function.Function;
import java.util.regex.Pattern;

/**
 * Lecture typée des champs JSON de l'API (ou d'un webhook). Usage interne.
 *
 * <p>Tolérant sur la représentation (entiers en chaîne acceptés, {@code []} PHP pour un objet
 * vide), strict sur la présence des champs obligatoires.
 */
public final class JsonReader {

  private static final Pattern INTEGER = Pattern.compile("-?[0-9]+");

  private final String context;
  private final Function<String, ? extends LabyrintheException> error;

  /**
   * @param context début du message d'erreur, par ex. {@code "Unexpected API response"}
   * @param error construit l'exception à partir du message
   */
  public JsonReader(String context, Function<String, ? extends LabyrintheException> error) {
    this.context = context;
    this.error = error;
  }

  /**
   * L'erreur « champ invalide ».
   *
   * @param key nom du champ
   * @param expected type attendu
   * @return l'exception (à lever)
   */
  public LabyrintheException invalid(String key, String expected) {
    return error.apply(context + ": field \"" + key + "\" should be " + expected + ".");
  }

  /**
   * Une erreur libre.
   *
   * @param message message complet
   * @return l'exception (à lever)
   */
  public LabyrintheException failure(String message) {
    return error.apply(message);
  }

  /**
   * Convertit une valeur décodée en objet JSON.
   *
   * @param value valeur décodée
   * @param key nom du champ (pour le message d'erreur)
   * @return l'objet
   */
  @SuppressWarnings("unchecked")
  public Map<String, Object> asObject(Object value, String key) {
    if (value instanceof Map) {
      return (Map<String, Object>) value;
    }
    throw invalid(key, "an object");
  }

  public String string(Map<String, Object> data, String key) {
    Object value = data.get(key);
    if (value instanceof String) {
      return (String) value;
    }
    if (value instanceof Long || value instanceof BigInteger) {
      return value.toString();
    }
    throw invalid(key, "a string");
  }

  public String nullableString(Map<String, Object> data, String key) {
    return data.get(key) == null ? null : string(data, key);
  }

  public long longValue(Map<String, Object> data, String key) {
    Long value = toLong(data.get(key));
    if (value == null) {
      throw invalid(key, "an integer");
    }
    return value;
  }

  public Long nullableLong(Map<String, Object> data, String key) {
    return data.get(key) == null ? null : longValue(data, key);
  }

  public int intValue(Map<String, Object> data, String key) {
    long value = longValue(data, key);
    if (value < Integer.MIN_VALUE || value > Integer.MAX_VALUE) {
      throw invalid(key, "a 32-bit integer");
    }
    return (int) value;
  }

  public Integer nullableInt(Map<String, Object> data, String key) {
    return data.get(key) == null ? null : intValue(data, key);
  }

  public boolean bool(Map<String, Object> data, String key) {
    Object value = data.get(key);
    if (value instanceof Boolean) {
      return (Boolean) value;
    }
    Long number = value instanceof Long ? (Long) value : null;
    if (number != null && (number == 0L || number == 1L)) {
      return number == 1L;
    }
    throw invalid(key, "a boolean");
  }

  public Map<String, Object> object(Map<String, Object> data, String key) {
    return asObject(data.get(key), key);
  }

  /** Objet facultatif : absent, nul ou {@code []} (PHP) donnent un objet vide. */
  public Map<String, Object> optionalObject(Map<String, Object> data, String key) {
    Object value = data.get(key);
    if (value == null || (value instanceof List && ((List<?>) value).isEmpty())) {
      return Collections.emptyMap();
    }
    return asObject(value, key);
  }

  public List<Map<String, Object>> objects(Map<String, Object> data, String key) {
    Object value = data.get(key);
    if (!(value instanceof List)) {
      throw invalid(key, "an array");
    }
    List<Map<String, Object>> items = new ArrayList<>();
    for (Object item : (List<?>) value) {
      items.add(asObject(item, key + "[]"));
    }
    return Collections.unmodifiableList(items);
  }

  public Instant instant(Map<String, Object> data, String key) {
    Object value = data.get(key);
    Instant parsed = value instanceof String ? parseInstant((String) value) : null;
    if (parsed == null) {
      throw invalid(key, "an ISO 8601 date-time");
    }
    return parsed;
  }

  public Instant nullableInstant(Map<String, Object> data, String key) {
    return data.get(key) == null ? null : instant(data, key);
  }

  /**
   * ISO 8601 → {@link Instant}. Accepte {@code Z}, {@code +00:00}, 0 à 9 décimales, et une
   * date-heure sans fuseau (lue en UTC).
   *
   * @param text texte de la date
   * @return l'instant, ou {@code null} s'il est illisible
   */
  public static Instant parseInstant(String text) {
    String value = text.trim();
    try {
      return OffsetDateTime.parse(value, DateTimeFormatter.ISO_OFFSET_DATE_TIME).toInstant();
    } catch (DateTimeParseException withOffset) {
      try {
        return LocalDateTime.parse(value, DateTimeFormatter.ISO_LOCAL_DATE_TIME)
            .toInstant(ZoneOffset.UTC);
      } catch (DateTimeParseException withoutOffset) {
        return null;
      }
    }
  }

  /**
   * Entier JSON tolérant : nombre entier, décimal sans partie fractionnaire, ou chaîne d'entier.
   *
   * @param value valeur décodée
   * @return l'entier, ou {@code null}
   */
  public static Long toLong(Object value) {
    if (value instanceof Long) {
      return (Long) value;
    }
    if (value instanceof Double) {
      double d = (Double) value;
      return d == Math.rint(d) && Math.abs(d) < 9.0e15 ? (long) d : null;
    }
    if (value instanceof BigDecimal) {
      try {
        return ((BigDecimal) value).longValueExact();
      } catch (ArithmeticException e) {
        return null;
      }
    }
    if (value instanceof String && INTEGER.matcher(((String) value).trim()).matches()) {
      try {
        return Long.parseLong(((String) value).trim());
      } catch (NumberFormatException e) {
        return null;
      }
    }
    return null;
  }
}

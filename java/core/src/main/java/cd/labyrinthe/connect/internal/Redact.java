package cd.labyrinthe.connect.internal;

import cd.labyrinthe.connect.PhoneNumbers;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Masque les secrets et les données personnelles avant un log ou un toString. Usage interne. */
public final class Redact {

  /** Paramètres de requête dont la valeur est un numéro de téléphone. */
  static final Set<String> PHONE_PARAMS = Set.of("recipient", "phone");

  private static final Pattern KEY_PREFIX = Pattern.compile("^(lsk_(?:live|test)_)");
  private static final Pattern QUERY = Pattern.compile("\\?[^\\s\"']*");

  private Redact() {}

  /**
   * {@code "lsk_test_abc…"} → {@code "lsk_test_****"}. Ne révèle jamais un caractère de la partie
   * secrète.
   *
   * @param apiKey clé API
   * @return la clé masquée
   */
  public static String apiKey(String apiKey) {
    Matcher matcher = KEY_PREFIX.matcher(apiKey == null ? "" : apiKey);
    return matcher.find() ? matcher.group(1) + "****" : "****";
  }

  /**
   * Chemin + requête, numéros de téléphone masqués.
   *
   * @param path chemin
   * @param query paramètres
   * @return le chemin à journaliser
   */
  public static String path(String path, List<Map.Entry<String, String>> query) {
    if (query.isEmpty()) {
      return path;
    }
    StringBuilder out = new StringBuilder(path).append('?');
    boolean first = true;
    for (Map.Entry<String, String> entry : query) {
      if (!first) {
        out.append('&');
      }
      first = false;
      String value =
          PHONE_PARAMS.contains(entry.getKey())
              ? PhoneNumbers.mask(entry.getValue())
              : encode(entry.getValue());
      out.append(encode(entry.getKey())).append('=').append(value);
    }
    return out.toString();
  }

  /**
   * Retire la requête d'une URL citée dans un message d'erreur (elle peut contenir un numéro).
   *
   * @param text texte
   * @return le texte sans requête
   */
  public static String stripQueryStrings(String text) {
    return QUERY.matcher(text).replaceAll("?…");
  }

  /**
   * Encodage d'un composant d'URL ({@code %20} pour l'espace).
   *
   * @param value valeur
   * @return la valeur encodée
   */
  public static String encode(String value) {
    return URLEncoder.encode(value, StandardCharsets.UTF_8).replace("+", "%20");
  }
}

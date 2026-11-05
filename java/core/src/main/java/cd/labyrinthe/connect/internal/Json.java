package cd.labyrinthe.connect.internal;

import java.math.BigDecimal;
import java.math.BigInteger;
import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Lecteur et écrivain JSON minimal (RFC 8259), sans dépendance. Usage interne.
 *
 * <p>Pourquoi pas Jackson : le SDK n'échange que de petits objets JSON simples ; un parseur interne
 * évite d'imposer une version de Jackson aux applications (conflits classiques avec Spring Boot) et
 * réduit la surface d'attaque. Les valeurs lues sont : {@code Map<String, Object>} (ordre conservé,
 * non modifiable), {@code List<Object>} (non modifiable), {@code String}, {@code Long} ou {@code
 * BigInteger} (entiers), {@code Double} ou {@code BigDecimal} (décimaux), {@code Boolean} et {@code
 * null}.
 */
public final class Json {

  /** Profondeur maximale d'imbrication acceptée (protection contre les entrées hostiles). */
  static final int MAX_DEPTH = 256;

  private final String text;
  private int pos;

  private Json(String text) {
    this.text = text;
  }

  /**
   * Décode un document JSON complet.
   *
   * @param text texte JSON
   * @return la valeur décodée
   * @throws JsonException si le texte n'est pas du JSON valide
   */
  public static Object parse(String text) {
    Json parser = new Json(text);
    parser.skipWhitespace();
    Object value = parser.readValue(0);
    parser.skipWhitespace();
    if (parser.pos != text.length()) {
      throw parser.error("unexpected trailing characters");
    }
    return value;
  }

  /**
   * Encode une valeur ({@code Map}, {@code Iterable}, tableau d'objets, {@code CharSequence},
   * {@code Number}, {@code Boolean}, {@code Enum} ou {@code null}) en JSON compact.
   *
   * @param value valeur à encoder
   * @return le texte JSON
   */
  public static String write(Object value) {
    StringBuilder out = new StringBuilder();
    writeValue(out, value, 0);
    return out.toString();
  }

  // -- lecture ------------------------------------------------------------------------------------

  private Object readValue(int depth) {
    if (depth > MAX_DEPTH) {
      throw error("nesting too deep");
    }
    if (pos >= text.length()) {
      throw error("unexpected end of input");
    }
    char c = text.charAt(pos);
    switch (c) {
      case '{':
        return readObject(depth);
      case '[':
        return readArray(depth);
      case '"':
        return readString();
      case 't':
        expectWord("true");
        return Boolean.TRUE;
      case 'f':
        expectWord("false");
        return Boolean.FALSE;
      case 'n':
        expectWord("null");
        return null;
      default:
        if (c == '-' || (c >= '0' && c <= '9')) {
          return readNumber();
        }
        throw error("unexpected character");
    }
  }

  private Map<String, Object> readObject(int depth) {
    pos++; // {
    Map<String, Object> map = new LinkedHashMap<>();
    skipWhitespace();
    if (peek() == '}') {
      pos++;
      return Collections.unmodifiableMap(map);
    }
    while (true) {
      skipWhitespace();
      if (peek() != '"') {
        throw error("expected a string key");
      }
      String key = readString();
      skipWhitespace();
      expect(':');
      skipWhitespace();
      map.put(key, readValue(depth + 1));
      skipWhitespace();
      char c = next();
      if (c == '}') {
        return Collections.unmodifiableMap(map);
      }
      if (c != ',') {
        throw error("expected ',' or '}'");
      }
    }
  }

  private List<Object> readArray(int depth) {
    pos++; // [
    List<Object> list = new ArrayList<>();
    skipWhitespace();
    if (peek() == ']') {
      pos++;
      return Collections.unmodifiableList(list);
    }
    while (true) {
      skipWhitespace();
      list.add(readValue(depth + 1));
      skipWhitespace();
      char c = next();
      if (c == ']') {
        return Collections.unmodifiableList(list);
      }
      if (c != ',') {
        throw error("expected ',' or ']'");
      }
    }
  }

  private String readString() {
    pos++; // "
    StringBuilder out = new StringBuilder();
    while (true) {
      if (pos >= text.length()) {
        throw error("unterminated string");
      }
      char c = text.charAt(pos++);
      if (c == '"') {
        return out.toString();
      }
      if (c < 0x20) {
        throw error("control character in string");
      }
      if (c != '\\') {
        out.append(c);
        continue;
      }
      char escape = next();
      switch (escape) {
        case '"':
        case '\\':
        case '/':
          out.append(escape);
          break;
        case 'b':
          out.append('\b');
          break;
        case 'f':
          out.append('\f');
          break;
        case 'n':
          out.append('\n');
          break;
        case 'r':
          out.append('\r');
          break;
        case 't':
          out.append('\t');
          break;
        case 'u':
          out.append(readHex4());
          break;
        default:
          throw error("invalid escape sequence");
      }
    }
  }

  private char readHex4() {
    if (pos + 4 > text.length()) {
      throw error("invalid unicode escape");
    }
    int value = 0;
    for (int i = 0; i < 4; i++) {
      int digit = Character.digit(text.charAt(pos++), 16);
      if (digit < 0) {
        throw error("invalid unicode escape");
      }
      value = value * 16 + digit;
    }
    return (char) value;
  }

  private Object readNumber() {
    int start = pos;
    if (peek() == '-') {
      pos++;
    }
    if (peek() == '0') {
      pos++;
    } else if (isDigit(peek())) {
      while (isDigit(peek())) {
        pos++;
      }
    } else {
      throw error("invalid number");
    }
    boolean integral = true;
    if (peek() == '.') {
      integral = false;
      pos++;
      if (!isDigit(peek())) {
        throw error("invalid number");
      }
      while (isDigit(peek())) {
        pos++;
      }
    }
    if (peek() == 'e' || peek() == 'E') {
      integral = false;
      pos++;
      if (peek() == '+' || peek() == '-') {
        pos++;
      }
      if (!isDigit(peek())) {
        throw error("invalid number");
      }
      while (isDigit(peek())) {
        pos++;
      }
    }
    String literal = text.substring(start, pos);
    if (integral) {
      BigInteger big = new BigInteger(literal);
      return big.bitLength() < 64 ? (Object) big.longValue() : big;
    }
    double d = Double.parseDouble(literal);
    return Double.isInfinite(d) ? new BigDecimal(literal) : (Object) d;
  }

  private void expectWord(String word) {
    if (!text.startsWith(word, pos)) {
      throw error("unexpected literal");
    }
    pos += word.length();
  }

  private void expect(char c) {
    if (next() != c) {
      throw error("expected '" + c + "'");
    }
  }

  private char peek() {
    return pos < text.length() ? text.charAt(pos) : '\0';
  }

  private char next() {
    if (pos >= text.length()) {
      throw error("unexpected end of input");
    }
    return text.charAt(pos++);
  }

  private void skipWhitespace() {
    while (pos < text.length()) {
      char c = text.charAt(pos);
      if (c != ' ' && c != '\t' && c != '\n' && c != '\r') {
        return;
      }
      pos++;
    }
  }

  private static boolean isDigit(char c) {
    return c >= '0' && c <= '9';
  }

  private JsonException error(String reason) {
    // Jamais d'extrait du texte : il peut contenir des données personnelles.
    return new JsonException("Invalid JSON at offset " + pos + ": " + reason + ".");
  }

  // -- écriture -----------------------------------------------------------------------------------

  private static void writeValue(StringBuilder out, Object value, int depth) {
    if (depth > MAX_DEPTH) {
      throw new JsonException("Cannot encode JSON: nesting too deep.");
    }
    if (value == null) {
      out.append("null");
    } else if (value instanceof CharSequence) {
      writeString(out, value.toString());
    } else if (value instanceof Boolean) {
      out.append(value.toString());
    } else if (value instanceof Number) {
      writeNumber(out, (Number) value);
    } else if (value instanceof Enum) {
      writeString(out, value.toString());
    } else if (value instanceof Map) {
      out.append('{');
      boolean first = true;
      for (Map.Entry<?, ?> entry : ((Map<?, ?>) value).entrySet()) {
        if (!first) {
          out.append(',');
        }
        first = false;
        writeString(out, String.valueOf(entry.getKey()));
        out.append(':');
        writeValue(out, entry.getValue(), depth + 1);
      }
      out.append('}');
    } else if (value instanceof Iterable) {
      out.append('[');
      boolean first = true;
      for (Object item : (Iterable<?>) value) {
        if (!first) {
          out.append(',');
        }
        first = false;
        writeValue(out, item, depth + 1);
      }
      out.append(']');
    } else if (value instanceof Object[]) {
      writeValue(out, java.util.Arrays.asList((Object[]) value), depth);
    } else {
      throw new JsonException("Cannot encode a " + value.getClass().getName() + " as JSON.");
    }
  }

  private static void writeNumber(StringBuilder out, Number number) {
    if (number instanceof Double || number instanceof Float) {
      double d = number.doubleValue();
      if (Double.isNaN(d) || Double.isInfinite(d)) {
        throw new JsonException("Cannot encode NaN or infinity as JSON.");
      }
      if (d == Math.rint(d) && Math.abs(d) < 1e15) {
        out.append((long) d);
      } else {
        out.append(d);
      }
    } else if (number instanceof BigDecimal) {
      out.append(((BigDecimal) number).toString());
    } else {
      out.append(number.toString());
    }
  }

  private static void writeString(StringBuilder out, String s) {
    out.append('"');
    for (int i = 0; i < s.length(); i++) {
      char c = s.charAt(i);
      switch (c) {
        case '"':
          out.append("\\\"");
          break;
        case '\\':
          out.append("\\\\");
          break;
        case '\n':
          out.append("\\n");
          break;
        case '\r':
          out.append("\\r");
          break;
        case '\t':
          out.append("\\t");
          break;
        case '\b':
          out.append("\\b");
          break;
        case '\f':
          out.append("\\f");
          break;
        default:
          if (c < 0x20 || isLoneSurrogate(s, i) || c == ' ' || c == ' ') {
            out.append(String.format("\\u%04x", (int) c));
          } else {
            out.append(c);
          }
      }
    }
    out.append('"');
  }

  private static boolean isLoneSurrogate(String s, int i) {
    char c = s.charAt(i);
    if (Character.isHighSurrogate(c)) {
      return i + 1 >= s.length() || !Character.isLowSurrogate(s.charAt(i + 1));
    }
    if (Character.isLowSurrogate(c)) {
      return i == 0 || !Character.isHighSurrogate(s.charAt(i - 1));
    }
    return false;
  }

  /** Erreur de décodage ou d'encodage JSON. */
  public static final class JsonException extends RuntimeException {
    private static final long serialVersionUID = 1L;

    JsonException(String message) {
      super(message);
    }
  }
}

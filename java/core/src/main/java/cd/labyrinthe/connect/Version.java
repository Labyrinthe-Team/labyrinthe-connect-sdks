package cd.labyrinthe.connect;

import java.io.IOException;
import java.io.InputStream;
import java.util.Properties;

/** Version of the SDK. */
public final class Version {

  /** Version of this SDK, e.g. {@code "1.2.0"} (from the Maven build). */
  public static final String VERSION = load();

  private Version() {}

  /**
   * The {@code User-Agent} sent with every request.
   *
   * @return e.g. {@code "labyrinthe-java/1.2.0 Java/21.0.4"}
   */
  public static String userAgent() {
    return "labyrinthe-java/" + VERSION + " Java/" + System.getProperty("java.version", "unknown");
  }

  private static String load() {
    try (InputStream in = Version.class.getResourceAsStream("version.properties")) {
      if (in == null) {
        return "unknown";
      }
      Properties properties = new Properties();
      properties.load(in);
      String version = properties.getProperty("version", "unknown").trim();
      return version.isEmpty() || version.startsWith("${") ? "unknown" : version;
    } catch (IOException e) {
      return "unknown";
    }
  }
}

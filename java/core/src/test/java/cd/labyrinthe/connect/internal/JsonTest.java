package cd.labyrinthe.connect.internal;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.math.BigInteger;
import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.ZoneOffset;
import java.util.Arrays;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import org.junit.jupiter.api.Test;

class JsonTest {

  @Test
  void parsesEveryJsonType() {
    Object value =
        Json.parse(
            " {\"s\":\"a\\\"b\\\\c\\/\\n\\u00e9\\ud83d\\ude00\",\"i\":-12,\"big\":12345678901234567890,"
                + "\"d\":1.5e2,\"t\":true,\"f\":false,\"n\":null,\"a\":[1,[],{}],\"o\":{}} ");
    Map<?, ?> map = (Map<?, ?>) value;
    assertEquals("a\"b\\c/\né😀", map.get("s"));
    assertEquals(-12L, map.get("i"));
    assertEquals(new BigInteger("12345678901234567890"), map.get("big"));
    assertEquals(150.0, map.get("d"));
    assertEquals(true, map.get("t"));
    assertEquals(false, map.get("f"));
    assertNull(map.get("n"));
    assertTrue(map.containsKey("n"));
    assertEquals(Arrays.asList(1L, List.of(), Map.of()), map.get("a"));
    assertThrows(UnsupportedOperationException.class, map::clear);
  }

  @Test
  void rejectsInvalidJson() {
    for (String bad :
        new String[] {
          "",
          "{",
          "[1,]",
          "{\"a\":}",
          "{a:1}",
          "01",
          "1.",
          "-",
          "\"\\x\"",
          "\"a",
          "tru",
          "[1] 2",
          "\"\u0001\"",
          "{\"a\" 1}",
          "1e",
          "\"\\u12\""
        }) {
      assertThrows(Json.JsonException.class, () -> Json.parse(bad), bad);
    }
    assertThrows(Json.JsonException.class, () -> Json.parse("[".repeat(300) + "]".repeat(300)));
  }

  @Test
  void errorsNeverQuoteTheInput() {
    Json.JsonException error =
        assertThrows(Json.JsonException.class, () -> Json.parse("{\"phone\":243812345678x}"));
    assertTrue(!error.getMessage().contains("243812345678"), error.getMessage());
  }

  @Test
  void writesCompactJsonAndRoundTrips() {
    Map<String, Object> map = new LinkedHashMap<>();
    map.put("body", "Réunion \"demain\"\n\t😀\u2028");
    map.put("n", 3);
    map.put("d", 2.5);
    map.put("whole", 3.0);
    map.put("list", List.of("a", 1L, true));
    map.put("none", null);
    String json = Json.write(map);
    assertEquals(
        "{\"body\":\"Réunion \\\"demain\\\"\\n\\t😀\\u2028\",\"n\":3,\"d\":2.5,\"whole\":3,"
            + "\"list\":[\"a\",1,true],\"none\":null}",
        json);
    assertEquals("Réunion \"demain\"\n\t😀\u2028", ((Map<?, ?>) Json.parse(json)).get("body"));
    assertEquals("\"\\ud800\"", Json.write("\ud800"));
    assertThrows(Json.JsonException.class, () -> Json.write(new Object()));
    assertThrows(Json.JsonException.class, () -> Json.write(Double.NaN));
  }

  @Test
  void parsesDatesLeniently() {
    assertEquals(
        Instant.parse("2026-11-05T09:39:11Z"),
        JsonReader.parseInstant("2026-11-05T09:39:11.000000Z"));
    assertEquals(
        Instant.parse("2026-11-05T10:05:00Z"),
        JsonReader.parseInstant("2026-11-05T10:05:00+00:00"));
    assertEquals(
        Instant.parse("2026-11-05T09:05:00Z"),
        JsonReader.parseInstant("2026-11-05T10:05:00+01:00"));
    assertEquals(
        Instant.parse("2026-11-05T10:05:00Z"), JsonReader.parseInstant("2026-11-05T10:05:00"));
    assertNull(JsonReader.parseInstant("05/11/2026"));
  }

  @Test
  void toleratesIntegersAsStringsOrWholeDoubles() {
    assertEquals(3L, JsonReader.toLong("3"));
    assertEquals(3L, JsonReader.toLong(3.0));
    assertNull(JsonReader.toLong(3.5));
    assertNull(JsonReader.toLong("3.5"));
    assertNull(JsonReader.toLong(true));
    assertNull(JsonReader.toLong("99999999999999999999"));
  }

  @Test
  void retryAfter() {
    Clock clock = Clock.fixed(Instant.parse("2026-11-05T10:00:00Z"), ZoneOffset.UTC);
    assertEquals(
        Duration.ofMillis(1500),
        RetryAfter.of(Map.of("retry-after", "1.5"), Map.of(), clock).orElseThrow());
    assertEquals(
        Duration.ofSeconds(30),
        RetryAfter.of(Map.of("retry-after", "Thu, 05 Nov 2026 10:00:30 GMT"), Map.of(), clock)
            .orElseThrow());
    assertEquals(
        Duration.ZERO,
        RetryAfter.of(Map.of("retry-after", "Thu, 05 Nov 2026 09:00:00 GMT"), Map.of(), clock)
            .orElseThrow());
    assertEquals(
        Duration.ofSeconds(7),
        RetryAfter.of(Map.of("retry-after", "soon"), Map.of("retry_after_seconds", 7L), clock)
            .orElseThrow());
    assertTrue(RetryAfter.of(Map.of("retry-after", "-1"), Map.of(), clock).isEmpty());
  }
}

package cd.labyrinthe.connect;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import cd.labyrinthe.connect.exception.InvalidArgumentException;
import java.net.http.HttpClient;
import java.time.Duration;
import java.util.Map;
import org.junit.jupiter.api.Test;

class ClientTest {

  private static LabyrintheClient.Builder offline() {
    return LabyrintheClient.builder().environment(name -> null);
  }

  @Test
  void requiresAnApiKey() {
    InvalidArgumentException error =
        assertThrows(InvalidArgumentException.class, () -> offline().build());
    assertTrue(error.getMessage().contains("LABYRINTHE_API_KEY"));
    assertEquals("INVALID_ARGUMENT", error.code());
    assertEquals(0, error.statusCode());
    assertThrows(InvalidArgumentException.class, () -> offline().apiKey("   ").build());
  }

  @Test
  void readsTheKeyAndBaseUrlFromTheEnvironment() {
    Map<String, String> env =
        Map.of(
            "LABYRINTHE_API_KEY", " lsk_live_abc ", "LABYRINTHE_BASE_URL", "http://localhost:8000");
    LabyrintheClient client = LabyrintheClient.builder().environment(env::get).build();
    assertTrue(client.isLiveMode());
    assertFalse(client.isTestMode());
    assertEquals("http://localhost:8000/api/public/v1", client.baseUrl());
  }

  @Test
  void explicitOptionsWinOverTheEnvironment() {
    Map<String, String> env =
        Map.of("LABYRINTHE_API_KEY", "lsk_live_env", "LABYRINTHE_BASE_URL", "http://env:1");
    LabyrintheClient client =
        LabyrintheClient.builder()
            .environment(env::get)
            .apiKey("lsk_test_mine")
            .baseUrl("https://example.test/custom/")
            .build();
    assertTrue(client.isTestMode());
    assertTrue(client.async().isTestMode());
    assertEquals("https://example.test/custom", client.baseUrl());
  }

  @Test
  void defaultsToProduction() {
    assertEquals(
        "https://api.connect.labyrinthe-rdc.com/api/public/v1",
        offline().apiKey("lsk_test_x").build().baseUrl());
  }

  @Test
  void normalisesTheBaseUrl() {
    assertEquals(
        "http://localhost:8000/api/public/v1",
        offline().apiKey("k").baseUrl("http://localhost:8000/").build().baseUrl());
    for (String bad :
        new String[] {"localhost:8000", "ftp://x.test", "http://", "http://x.test?a=1"}) {
      assertThrows(
          InvalidArgumentException.class, () -> offline().apiKey("k").baseUrl(bad).build(), bad);
    }
  }

  @Test
  void validatesTheOptions() {
    assertThrows(
        InvalidArgumentException.class, () -> offline().apiKey("k").timeout(Duration.ZERO).build());
    assertThrows(
        InvalidArgumentException.class, () -> offline().apiKey("k").maxRetries(-1).build());
    assertThrows(
        InvalidArgumentException.class, () -> offline().apiKey("k").maxRetries(11).build());
    assertThrows(
        InvalidArgumentException.class,
        () -> offline().apiKey("k").maxRetryWait(Duration.ofSeconds(-1)).build());
    assertThrows(
        InvalidArgumentException.class,
        () -> offline().apiKey("k").defaultCountryCode("+243").build());
    assertThrows(
        InvalidArgumentException.class,
        () -> offline().apiKey("k").defaultSender("WAY_TOO_LONG_SENDER").build());
    offline().apiKey("k").maxRetries(0).maxRetryWait(Duration.ZERO).build();
    offline().apiKey("k").maxRetries(10).build();
  }

  @Test
  void refusesAnHttpClientThatFollowsRedirects() {
    HttpClient redirecting =
        HttpClient.newBuilder().followRedirects(HttpClient.Redirect.NORMAL).build();
    assertThrows(
        InvalidArgumentException.class,
        () -> offline().apiKey("k").httpClient(redirecting).build());
    offline().apiKey("k").httpClient(HttpClient.newHttpClient()).build();
  }

  @Test
  void sendsTheAuthenticationAndUserAgentHeaders() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.balance());
      Balance balance = api.client().balance().get();
      assertEquals(5, balance.balance());
      assertEquals(0, balance.held());
      MockApi.Recorded request = api.last();
      assertEquals("GET", request.method);
      assertEquals("/api/public/v1/balance", request.path);
      assertEquals("Bearer " + MockApi.KEY, request.header("Authorization"));
      assertEquals("application/json", request.header("Accept"));
      assertTrue(
          request.header("User-Agent").matches("labyrinthe-java/\\d+\\.\\d+\\.\\d+\\S* Java/\\S+"),
          request.header("User-Agent"));
    }
  }

  @Test
  void sendsYourRequestIdAndReportsTheResponseHeaders() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.balance()
              .header("X-Request-Id", "my-id.1")
              .header("X-Api-Version", "1")
              .header("X-RateLimit-Limit", "120")
              .header("X-RateLimit-Remaining", "119"),
          MockApi.balance());
      java.util.List<ResponseInfo> seen = new java.util.ArrayList<>();
      LabyrintheClient client = api.client();
      client
          .balance()
          .get(RequestOptions.builder().requestId("my-id.1").onResponse(seen::add).build());
      assertEquals("my-id.1", api.last().header("X-Request-Id"));
      ResponseInfo info = seen.get(0);
      assertEquals(200, info.status());
      assertEquals("my-id.1", info.requestId().orElseThrow());
      assertEquals("1", info.apiVersion().orElseThrow());
      assertEquals(120, info.rateLimitLimit().getAsLong());
      assertEquals(119, info.rateLimitRemaining().getAsLong());
      assertFalse(info.replayed());
      assertTrue(info.toString().contains("my-id.1"));

      client.balance().get();
      assertEquals(null, api.last().header("X-Request-Id"));
      assertEquals(1, seen.size());
    }
  }

  @Test
  void validatesTheRequestId() {
    for (String invalid : new String[] {"", "with space", "é", "a/b", "x".repeat(129)}) {
      assertThrows(
          InvalidArgumentException.class, () -> RequestOptions.requestId(invalid), invalid);
    }
    assertThrows(InvalidArgumentException.class, () -> RequestOptions.builder().requestId(null));
    assertEquals(
        "A-z_0.9:-", RequestOptions.requestId("A-z_0.9:-").requestIdOverride().orElseThrow());
    assertEquals(128, RequestOptions.requestId("x".repeat(128)).requestIdOverride().get().length());
    assertFalse(RequestOptions.none().onResponse().isPresent());
  }

  @Test
  void doesNotFollowRedirects() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.json(302, "").header("Location", "http://evil.test/steal"));
      assertThrows(
          cd.labyrinthe.connect.exception.ApiException.class, () -> api.client().balance().get());
      assertEquals(1, api.requests.size());
    }
  }

  @Test
  void versionComesFromTheBuild() {
    String expected = System.getProperty("labyrinthe.expectedVersion");
    if (expected != null) {
      assertEquals(expected, Version.VERSION);
    }
    assertTrue(Version.userAgent().startsWith("labyrinthe-java/" + Version.VERSION + " Java/"));
  }

  @Test
  void listsApprovedSenderIds() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.data(
              200,
              "[{\"id\":\"s1\",\"value\":\"MYSHOP\",\"status\":\"approved\",\"purpose\":null,"
                  + "\"rejection_reason\":null,\"is_default\":true,"
                  + "\"created_at\":\"2026-10-01T08:00:00+00:00\"},"
                  + "{\"id\":\"s2\",\"value\":\"OTHER\",\"status\":\"brand_new\"}]"));
      var senderIds = api.client().senderIds().list();
      assertEquals(2, senderIds.size());
      assertEquals("MYSHOP", senderIds.get(0).value());
      assertEquals(SenderIdStatus.APPROVED, senderIds.get(0).status());
      assertTrue(senderIds.get(0).isDefault());
      assertTrue(senderIds.get(0).createdAt().isPresent());
      assertEquals(SenderIdStatus.UNKNOWN, senderIds.get(1).status());
      assertFalse(senderIds.get(1).isDefault());
      assertEquals("/api/public/v1/sender-ids", api.last().path);
    }
  }
}

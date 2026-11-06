package cd.labyrinthe.connect;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertInstanceOf;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import cd.labyrinthe.connect.exception.ApiConnectionException;
import cd.labyrinthe.connect.exception.ApiTimeoutException;
import cd.labyrinthe.connect.exception.IdempotencyKeyUnresolvedException;
import cd.labyrinthe.connect.exception.InsufficientCreditsException;
import cd.labyrinthe.connect.exception.InvalidArgumentException;
import cd.labyrinthe.connect.exception.OtpResendTooSoonException;
import java.time.Duration;
import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.CompletionException;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.TimeUnit;
import org.junit.jupiter.api.Test;

class AsyncTest {

  private static Throwable failure(CompletableFuture<?> future) {
    CompletionException error = assertThrows(CompletionException.class, future::join);
    return error.getCause();
  }

  @Test
  void sameSurfaceAsTheSynchronousClient() throws Exception {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.balance(),
          MockApi.data(200, "[]"),
          MockApi.data(202, "[" + MockApi.message() + "]"),
          MockApi.data(202, "[" + MockApi.message() + "]"),
          MockApi.data(200, MockApi.message()),
          MockApi.data(
              200,
              "{\"encoding\":\"gsm7\",\"characters\":5,\"segments_per_message\":1,"
                  + "\"recipients_count\":1,\"total_credits\":1,\"balance_after\":9}"),
          MockApi.data(201, RetryTest.OTP_SENT),
          MockApi.data(200, RetryTest.VERIFIED));
      LabyrintheAsyncClient client = api.client().async();
      assertEquals(5, client.balance().get().get(5, TimeUnit.SECONDS).balance());
      assertTrue(client.senderIds().list().join().isEmpty());
      assertEquals("243812345678", client.sms().send("0812345678", "Hi").join().recipient());
      assertEquals(
          1,
          client
              .sms()
              .sendMany(SendManySmsParams.builder().addRecipient("0812345678").body("Hi").build())
              .join()
              .size());
      assertEquals(MessageStatus.QUEUED, client.sms().get("m1").join().status());
      assertTrue(
          client
              .sms()
              .estimate(EstimateSmsParams.builder().body("Hi").addRecipient("0812345678").build())
              .join()
              .isAffordable());
      assertEquals("otp-1", client.otp().send("0812345678").join().otpId());
      assertTrue(client.otp().verify("otp-1", "123456").join().isVerified());
      assertEquals(8, api.requests.size());
    }
  }

  @Test
  void neverRetriesAnUnresolvedIdempotencyKeyAsynchronously() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.error(409, "IDEMPOTENCY_KEY_UNRESOLVED"),
          MockApi.error(409, "IDEMPOTENCY_KEY_UNRESOLVED"),
          MockApi.data(201, RetryTest.OTP_SENT));
      LabyrintheAsyncClient client = api.builder().maxRetries(3).build().async();
      assertInstanceOf(
          IdempotencyKeyUnresolvedException.class,
          failure(client.sms().send("0812345678", "Hello")));
      assertInstanceOf(
          IdempotencyKeyUnresolvedException.class, failure(client.otp().send("0812345678")));
      assertEquals(2, api.requests.size());
      assertTrue(api.scheduler.sleeps.isEmpty());
    }
  }

  @Test
  void failsWithTypedExceptions() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.error(409, "INSUFFICIENT_CREDITS", "{\"available\":1,\"required\":2}"),
          MockApi.error(429, "OTP_RESEND_TOO_SOON", "{\"retry_after_seconds\":30}"));
      LabyrintheAsyncClient client = api.client().async();
      assertInstanceOf(
          InsufficientCreditsException.class, failure(client.sms().send("0812345678", "Hello")));
      ExecutionException viaGet =
          assertThrows(ExecutionException.class, () -> client.otp().send("0812345678").get());
      assertInstanceOf(OtpResendTooSoonException.class, viaGet.getCause());
      assertEquals(2, api.requests.size());
      assertTrue(api.scheduler.sleeps.isEmpty());
    }
  }

  @Test
  void invalidArgumentsGiveAFailedFuture() {
    try (MockApi api = new MockApi()) {
      LabyrintheAsyncClient client = api.client().async();
      assertInstanceOf(InvalidArgumentException.class, failure(client.sms().send("12", "Hello")));
      assertInstanceOf(
          InvalidArgumentException.class, failure(client.sms().send("0812345678", "")));
      assertInstanceOf(InvalidArgumentException.class, failure(client.otp().send("1")));
      assertInstanceOf(InvalidArgumentException.class, failure(client.otp().verify("x", "")));
      assertInstanceOf(InvalidArgumentException.class, failure(client.sms().get("")));
      assertTrue(api.requests.isEmpty());
    }
  }

  @Test
  void retriesWithTheSameRulesAndKey() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.disconnect(),
          MockApi.error(503, "HTTP_ERROR"),
          MockApi.data(202, "[" + MockApi.message() + "]"));
      api.client().async().sms().send("0812345678", "Hello").join();
      assertEquals(3, api.requests.size());
      assertEquals(2, api.scheduler.sleeps.size());
      assertEquals(
          api.requests.get(0).header("Idempotency-Key"),
          api.requests.get(2).header("Idempotency-Key"));
    }
  }

  @Test
  void neverRetriesOtpVerifyAfterANetworkErrorOrTimeout() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.disconnect(), MockApi.data(200, RetryTest.VERIFIED).delay(1000));
      LabyrintheClient client = api.builder().timeout(Duration.ofMillis(200)).build();
      assertInstanceOf(
          ApiConnectionException.class, failure(client.async().otp().verify("otp-1", "123456")));
      assertInstanceOf(
          ApiTimeoutException.class, failure(client.async().otp().verify("otp-1", "123456")));
      assertEquals(2, api.requests.size());
    }
  }

  @Test
  void retriesOtpSendWithTheSameKeyAfterANetworkErrorOrInProgress() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.disconnect(),
          MockApi.error(409, "IDEMPOTENCY_REQUEST_IN_PROGRESS", "{\"retry_after_seconds\":5}")
              .header("Retry-After", "5"),
          MockApi.data(201, RetryTest.OTP_SENT).header("Idempotent-Replayed", "true"));
      OtpSendResult otp =
          api.builder()
              .maxRetries(2)
              .build()
              .async()
              .otp()
              .send(SendOtpParams.builder().phone("0812345678").idempotencyKey("login-42").build())
              .join();
      assertTrue(otp.replayed());
      assertEquals("login-42", otp.idempotencyKey());
      assertEquals(3, api.requests.size());
      for (MockApi.Recorded request : api.requests) {
        assertEquals("login-42", request.header("Idempotency-Key"));
      }
      assertEquals(Duration.ofSeconds(5), api.scheduler.sleeps.get(1));
    }
  }

  @Test
  void callsOnResponseAndSendsTheRequestIdAsynchronously() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.balance().header("X-Request-Id", "req-async").header("X-Api-Version", "1"),
          MockApi.error(404, "NOT_FOUND").header("X-Request-Id", "req-404"));
      List<ResponseInfo> seen = new java.util.concurrent.CopyOnWriteArrayList<>();
      LabyrintheAsyncClient client = api.client().async();
      client
          .balance()
          .get(RequestOptions.builder().requestId("req-async").onResponse(seen::add).build())
          .join();
      assertEquals("req-async", api.last().header("X-Request-Id"));
      assertEquals("req-async", seen.get(0).requestId().orElseThrow());
      assertEquals("1", seen.get(0).apiVersion().orElseThrow());
      Throwable error = failure(client.sms().get("missing"));
      assertEquals(
          "req-404",
          ((cd.labyrinthe.connect.exception.ApiException) error).requestId().orElseThrow());
    }
  }

  @Test
  void paginatesAsynchronously() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.page(1, 2, 2, MockApi.message()),
          MockApi.page(2, 2, 2, MockApi.message("delivered", "243899999999")),
          MockApi.page(1, 2, 2, MockApi.message()),
          MockApi.page(2, 2, 2, MockApi.message("delivered", "243899999999")));
      LabyrintheAsyncClient client = api.client().async();
      List<MessageStatus> statuses = new ArrayList<>();
      client
          .sms()
          .list(ListSmsParams.builder().perPage(1).build())
          .thenCompose(page -> page.autoPagingForEach(m -> statuses.add(m.status())))
          .join();
      assertEquals(List.of(MessageStatus.QUEUED, MessageStatus.DELIVERED), statuses);
      AsyncPage<Message> first = client.sms().list().join();
      assertTrue(first.hasMore());
      assertEquals(2, first.autoPagingToList().join().size());
      assertEquals(4, api.requests.size());
    }
  }
}

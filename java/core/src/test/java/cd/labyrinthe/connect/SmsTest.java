package cd.labyrinthe.connect;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import cd.labyrinthe.connect.exception.InvalidArgumentException;
import cd.labyrinthe.connect.exception.UnexpectedResponseException;
import java.time.Instant;
import java.time.LocalDate;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import java.util.stream.Collectors;
import org.junit.jupiter.api.Test;

class SmsTest {

  @Test
  void sendsOneSmsWithANormalisedNumberAndAGeneratedIdempotencyKey() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.data(202, "[" + MockApi.message() + "]"));
      Message message = api.client().sms().send("+243 81 234 5678", "Hello");

      assertEquals("9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10", message.id());
      assertEquals("243812345678", message.recipient());
      assertEquals(MessageStatus.QUEUED, message.status());
      assertEquals(Encoding.GSM7, message.encoding().orElseThrow());
      assertEquals(1, message.segments());
      assertEquals(1, message.creditsCharged());
      assertEquals("order-1042", message.reference().orElseThrow());
      assertEquals("MYSHOP", message.sender().orElseThrow());
      assertEquals(Instant.parse("2026-11-05T09:39:11Z"), message.createdAt());
      assertFalse(message.submittedAt().isPresent());

      MockApi.Recorded request = api.last();
      assertEquals("POST", request.method);
      assertEquals("/api/public/v1/sms", request.path);
      assertEquals("application/json", request.header("Content-Type"));
      assertEquals(Map.of("recipient", "+243812345678", "body", "Hello"), request.json());
      UUID.fromString(request.header("Idempotency-Key"));
    }
  }

  @Test
  void sendsTheOptionalFieldsAndYourIdempotencyKey() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.data(202, "[" + MockApi.message() + "]"));
      api.client()
          .sms()
          .send(
              SendSmsParams.builder()
                  .to("0812345678")
                  .body("Réunion à 9h")
                  .sender("MYSHOP")
                  .reference("order-1042")
                  .idempotencyKey("order-1042-ready")
                  .build());
      Map<String, Object> body = api.last().json();
      assertEquals("+243812345678", body.get("recipient"));
      assertEquals("Réunion à 9h", body.get("body"));
      assertEquals("MYSHOP", body.get("sender"));
      assertEquals("order-1042", body.get("reference"));
      assertEquals("order-1042-ready", api.last().header("Idempotency-Key"));
      assertFalse(body.containsKey("strip_accents"));
    }
  }

  @Test
  void sendsStripAccentsForSendSendManyAndEstimate() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.data(202, "[" + MockApi.message() + "]"),
          MockApi.data(202, "[" + MockApi.message() + "]").header("X-Request-Id", "req-many"),
          MockApi.data(
              200,
              "{\"encoding\":\"gsm7\",\"characters\":12,\"segments_per_message\":1,"
                  + "\"recipients_count\":1,\"total_credits\":1,\"balance_after\":9}"));
      LabyrintheClient client = api.client();
      client
          .sms()
          .send(
              SendSmsParams.builder().to("33612345678").body("Réunion").stripAccents(true).build());
      assertEquals(true, api.last().json().get("strip_accents"));
      assertEquals("+33612345678", api.last().json().get("recipient"));
      MessageBatch batch =
          client
              .sms()
              .sendMany(
                  SendManySmsParams.builder()
                      .addRecipient("0812345678")
                      .body("Réunion")
                      .stripAccents(true)
                      .build());
      assertEquals(true, api.last().json().get("strip_accents"));
      assertEquals("req-many", batch.requestId().orElseThrow());
      Estimate estimate =
          client
              .sms()
              .estimate(
                  EstimateSmsParams.builder()
                      .body("Réunion à 9h")
                      .addRecipient("0812345678")
                      .stripAccents(true)
                      .build());
      assertEquals(true, api.last().json().get("strip_accents"));
      assertEquals(Encoding.GSM7, estimate.encoding());
      assertTrue(
          EstimateSmsParams.builder()
              .body("x")
              .addRecipient("1")
              .stripAccents(true)
              .build()
              .stripAccents());
    }
  }

  @Test
  void usesTheDefaultSenderAndCountryCode() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.data(202, "[" + MockApi.message() + "]"));
      api.builder()
          .defaultSender("DEFAULT")
          .defaultCountryCode("33")
          .build()
          .sms()
          .send("0612345678", "Bonjour");
      assertEquals("+33612345678", api.last().json().get("recipient"));
      assertEquals("DEFAULT", api.last().json().get("sender"));
    }
  }

  @Test
  void eachCallGetsItsOwnIdempotencyKey() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.data(202, "[" + MockApi.message() + "]"),
          MockApi.data(202, "[" + MockApi.message() + "]"));
      LabyrintheClient client = api.client();
      client.sms().send("0812345678", "Hello");
      client.sms().send("0812345678", "Hello");
      assertFalse(
          api.requests
              .get(0)
              .header("Idempotency-Key")
              .equals(api.requests.get(1).header("Idempotency-Key")));
    }
  }

  @Test
  void sendsToManyRecipientsAndReportsReplays() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.data(
                  202,
                  "[" + MockApi.message() + "," + MockApi.message("queued", "33612345678") + "]")
              .header("Idempotent-Replayed", "true"));
      MessageBatch batch =
          api.client()
              .sms()
              .sendMany(
                  SendManySmsParams.builder()
                      .recipients(List.of("0812345678", "+33 6 12 34 56 78"))
                      .body("Hello")
                      .idempotencyKey("k-1")
                      .build());
      assertEquals(2, batch.size());
      assertTrue(batch.replayed());
      assertEquals("k-1", batch.idempotencyKey());
      assertEquals(
          List.of("243812345678", "33612345678"),
          batch.messages().stream().map(Message::recipient).collect(Collectors.toList()));
      assertEquals(List.of("+243812345678", "+33612345678"), api.last().json().get("recipients"));
      assertFalse(api.last().json().containsKey("recipient"));
    }
  }

  @Test
  void refusesInvalidNumbersLocallyWithTheirIndexOnly() {
    try (MockApi api = new MockApi()) {
      InvalidArgumentException error =
          assertThrows(
              InvalidArgumentException.class,
              () ->
                  api.client()
                      .sms()
                      .sendMany(
                          SendManySmsParams.builder()
                              .recipients(List.of("0812345678", "12", "abc"))
                              .body("Hello")
                              .build()));
      assertTrue(error.getMessage().contains("index 1, 2"), error.getMessage());
      assertFalse(error.getMessage().contains("0812345678"));
      assertTrue(api.requests.isEmpty());
      assertThrows(InvalidArgumentException.class, () -> api.client().sms().send("123", "Hello"));
    }
  }

  @Test
  void validatesParametersBeforeAnyCall() {
    assertThrows(
        InvalidArgumentException.class, () -> SendSmsParams.builder().to("0812345678").build());
    assertThrows(
        InvalidArgumentException.class,
        () -> SendSmsParams.builder().to("0812345678").body("").build());
    assertThrows(
        InvalidArgumentException.class,
        () ->
            SendSmsParams.builder()
                .to("0812345678")
                .body("x".repeat(Limits.MAX_BODY_LENGTH + 1))
                .build());
    SendSmsParams.builder().to("0812345678").body("x".repeat(Limits.MAX_BODY_LENGTH)).build();
    assertThrows(
        InvalidArgumentException.class,
        () -> SendSmsParams.builder().to("0812345678").body("x").sender("TWELVE_CHARS").build());
    assertThrows(
        InvalidArgumentException.class,
        () -> SendSmsParams.builder().to("0812345678").body("x").reference("r".repeat(65)).build());
    assertThrows(
        InvalidArgumentException.class,
        () -> SendSmsParams.builder().to("0812345678").body("x").idempotencyKey("").build());
    assertThrows(InvalidArgumentException.class, () -> SendSmsParams.builder().body("x").build());
    assertThrows(
        InvalidArgumentException.class, () -> SendManySmsParams.builder().body("x").build());
    List<String> tooMany = new ArrayList<>(Collections.nCopies(1001, "0812345678"));
    assertThrows(
        InvalidArgumentException.class,
        () -> SendManySmsParams.builder().recipients(tooMany).body("x").build());
  }

  @Test
  void sendsEveryNumberInE164WithThePlus() {
    // BACKEND-TODO n° 32 : sans « + », l'API lirait « 2481234567 » comme un numéro local RDC.
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.data(202, "[" + MockApi.message() + "]"));
      api.enqueue(MockApi.data(202, "[" + MockApi.message() + "]"));
      api.enqueue(
          MockApi.data(
              200,
              "{\"encoding\":\"gsm7\",\"characters\":2,\"segments_per_message\":1,"
                  + "\"recipients_count\":2,\"total_credits\":2,\"balance_after\":10}"));
      api.enqueue(MockApi.page(1, 1, 0));
      LabyrintheClient client = api.client();

      client.sms().send("+2481234567", "Hi");
      assertEquals("+2481234567", api.last().json().get("recipient"));

      client
          .sms()
          .sendMany(
              SendManySmsParams.builder()
                  .recipients(List.of("+2481234567", "0812345678"))
                  .body("Hi")
                  .build());
      assertEquals(List.of("+2481234567", "+243812345678"), api.last().json().get("recipients"));

      client
          .sms()
          .estimate(
              EstimateSmsParams.builder()
                  .body("Hi")
                  .addRecipient("+2481234567")
                  .addRecipient("0812345678")
                  .build());
      assertEquals(List.of("+2481234567", "+243812345678"), api.last().json().get("recipients"));

      client.sms().list(ListSmsParams.builder().recipient("+2481234567").build());
      assertEquals("+2481234567", api.last().query().get("recipient"));
    }
  }

  @Test
  void estimatesACost() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.data(
              200,
              "{\"encoding\":\"ucs2\",\"characters\":19,\"segments_per_message\":1,"
                  + "\"recipients_count\":2,\"total_credits\":2,\"balance_after\":null}"));
      Estimate estimate =
          api.client()
              .sms()
              .estimate(
                  EstimateSmsParams.builder()
                      .body("Réunion demain à 9h")
                      .addRecipient("0812345678")
                      .addRecipient("not-a-number")
                      .build());
      assertEquals(Encoding.UCS2, estimate.encoding());
      assertEquals(19, estimate.characters());
      assertEquals(2, estimate.totalCredits());
      assertFalse(estimate.balanceAfter().isPresent());
      assertFalse(estimate.isAffordable());
      assertEquals(List.of("+243812345678", "not-a-number"), api.last().json().get("recipients"));
      assertEquals("/api/public/v1/sms/estimate", api.last().path);
    }
  }

  @Test
  void getsAMessageWithAnEncodedId() {
    try (MockApi api = new MockApi()) {
      api.enqueue(MockApi.data(200, MockApi.message("delivered", "243812345678")));
      Message message = api.client().sms().get("a b/c");
      assertEquals(MessageStatus.DELIVERED, message.status());
      assertTrue(message.status().isFinal());
      assertEquals("/api/public/v1/sms/a%20b%2Fc", api.last().path);
      assertThrows(InvalidArgumentException.class, () -> api.client().sms().get(" "));
    }
  }

  @Test
  void toleratesNullableFieldsAndUnknownValues() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.data(
              200,
              "{\"id\":\"m1\",\"reference\":null,\"recipient\":\"243812345678\",\"sender\":null,"
                  + "\"encoding\":null,\"status\":\"teleported\",\"segments\":\"2\","
                  + "\"credits_charged\":2,\"campaign_id\":null,\"provider_message_id\":\"DD-1\","
                  + "\"error_code\":null,\"error_message\":null,"
                  + "\"created_at\":\"2026-11-05T10:05:00+00:00\",\"submitted_at\":\"2026-11-05T10:05:01.123Z\","
                  + "\"delivered_at\":\"2026-11-05T10:05:02\",\"future_field\":[1,2]}"));
      Message message = api.client().sms().get("m1");
      assertEquals(MessageStatus.UNKNOWN, message.status());
      assertFalse(message.encoding().isPresent());
      assertFalse(message.sender().isPresent());
      assertEquals(2, message.segments());
      assertEquals(Instant.parse("2026-11-05T10:05:01.123Z"), message.submittedAt().orElseThrow());
      assertEquals(Instant.parse("2026-11-05T10:05:02Z"), message.deliveredAt().orElseThrow());
      assertEquals("DD-1", message.providerMessageId().orElseThrow());
    }
  }

  @Test
  void rejectsUnreadableSuccessfulResponses() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.json(200, "not json"),
          MockApi.json(200, "[1]"),
          MockApi.data(200, "{\"balance\":\"x\",\"held\":0}"),
          MockApi.data(202, "[]"));
      LabyrintheClient client = api.client();
      UnexpectedResponseException notJson =
          assertThrows(UnexpectedResponseException.class, () -> client.balance().get());
      assertEquals(200, notJson.statusCode());
      assertThrows(UnexpectedResponseException.class, () -> client.balance().get());
      UnexpectedResponseException field =
          assertThrows(UnexpectedResponseException.class, () -> client.balance().get());
      assertTrue(field.getMessage().contains("\"balance\""));
      assertThrows(UnexpectedResponseException.class, () -> client.sms().send("0812345678", "x"));
    }
  }

  @Test
  void listsWithFiltersAndPaginatesLazily() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.page(1, 3, 3, MockApi.message()),
          MockApi.page(2, 3, 3, MockApi.message("sent", "243899999999")),
          MockApi.page(3, 3, 3, MockApi.message("failed", "243811111111")));
      Page<Message> page =
          api.client()
              .sms()
              .list(
                  ListSmsParams.builder()
                      .status(MessageStatus.QUEUED)
                      .recipient("0812345678")
                      .reference("order 1042")
                      .from(LocalDate.of(2026, 11, 1))
                      .to(OffsetDateTime.of(2026, 11, 30, 23, 0, 0, 999, ZoneOffset.ofHours(1)))
                      .perPage(1)
                      .build());
      assertEquals(1, api.requests.size());
      assertEquals(3, page.total());
      assertTrue(page.hasMore());
      Map<String, String> query = api.last().query();
      assertEquals("queued", query.get("status"));
      assertEquals("+243812345678", query.get("recipient"));
      assertEquals("order 1042", query.get("reference"));
      assertEquals("2026-11-01", query.get("from"));
      assertEquals("2026-11-30T22:00:00Z", query.get("to"));
      assertEquals("1", query.get("per_page"));
      assertEquals("1", query.get("page"));
      assertTrue(api.last().rawQuery.contains("reference=order%201042"), api.last().rawQuery);

      List<MessageStatus> statuses = new ArrayList<>();
      for (Message message : page.autoPagingIterable()) {
        statuses.add(message.status());
      }
      assertEquals(
          List.of(MessageStatus.QUEUED, MessageStatus.SENT, MessageStatus.FAILED), statuses);
      assertEquals(3, api.requests.size());
      assertEquals("3", api.last().query().get("page"));
      assertEquals("queued", api.last().query().get("status"));
    }
  }

  @Test
  void nextPageAndStream() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.page(2, 3, 2, MockApi.message()),
          MockApi.page(3, 3, 2, MockApi.message()),
          MockApi.page(3, 3, 2, MockApi.message()));
      Page<Message> page = api.client().sms().list(ListSmsParams.builder().page(2).build());
      assertEquals("2", api.last().query().get("page"));
      assertEquals("15", api.last().query().get("per_page"));
      Page<Message> last = page.nextPage().orElseThrow();
      assertFalse(last.hasMore());
      assertFalse(last.nextPage().isPresent());
      assertEquals(1, last.autoPagingStream().count());
      assertEquals(2, page.autoPagingStream().count());
      assertEquals(3, api.requests.size());
    }
  }

  @Test
  void stopsOnIncoherentPagination() {
    try (MockApi api = new MockApi()) {
      api.enqueue(
          MockApi.page(1, 5, 5, MockApi.message()), MockApi.page(1, 5, 5, MockApi.message()));
      assertEquals(1, api.client().sms().list().autoPagingStream().count());
      assertEquals(2, api.requests.size());
    }
  }

  @Test
  void validatesListParameters() {
    assertThrows(InvalidArgumentException.class, () -> ListSmsParams.builder().page(0).build());
    assertThrows(
        InvalidArgumentException.class, () -> ListSmsParams.builder().perPage(101).build());
    assertThrows(
        InvalidArgumentException.class,
        () -> ListSmsParams.builder().status(MessageStatus.UNKNOWN).build());
    ListSmsParams params = ListSmsParams.builder().perPage(100).build();
    assertNull(params.status().orElse(null));
    assertEquals(
        "2026-11-05T10:00:00Z",
        ListSmsParams.builder()
            .from(Instant.parse("2026-11-05T10:00:00.900Z"))
            .build()
            .from()
            .orElseThrow());
  }
}

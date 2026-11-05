package cd.labyrinthe.connect;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertInstanceOf;
import static org.junit.jupiter.api.Assertions.assertNotEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.junit.jupiter.api.Assumptions.assumeTrue;

import cd.labyrinthe.connect.exception.AuthenticationException;
import cd.labyrinthe.connect.exception.IdempotencyKeyReusedException;
import cd.labyrinthe.connect.exception.NotFoundException;
import cd.labyrinthe.connect.exception.SenderIdNotApprovedException;
import java.security.SecureRandom;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.CompletionException;
import java.util.function.Supplier;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Tag;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.ValueSource;

/**
 * Runs against a real backend with a TEST key (nothing is sent, nothing is billed):
 *
 * <pre>
 * LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 ./mvnw verify -Pintegration
 * </pre>
 *
 * <p>Skipped when LABYRINTHE_API_KEY is not set or is not a test key. Every scenario runs with the
 * synchronous AND the asynchronous API.
 */
@Tag("integration")
class IntegrationTest {

  private static final SecureRandom RANDOM = new SecureRandom();
  private static String key;
  private static String baseUrl;

  @BeforeAll
  static void requireTestKey() {
    key = System.getenv("LABYRINTHE_API_KEY");
    assumeTrue(key != null && key.startsWith("lsk_test_"), "needs LABYRINTHE_API_KEY=lsk_test_…");
    String url = System.getenv("LABYRINTHE_BASE_URL");
    baseUrl = url == null || url.isEmpty() ? "http://localhost:8000" : url;
  }

  private static LabyrintheClient client() {
    return LabyrintheClient.builder().apiKey(key).baseUrl(baseUrl).build();
  }

  /** Un numéro RDC aléatoire : les limites OTP par numéro ne se croisent jamais. */
  private static String randomPhone() {
    return String.format("0899%06d", RANDOM.nextInt(1_000_000));
  }

  private static String reference() {
    return "sdk-java-it-" + Long.toHexString(RANDOM.nextLong());
  }

  /** Exécute l'appel synchrone ou sa version asynchrone (en déballant l'exception). */
  private static <T> T run(
      boolean async, Supplier<T> sync, Supplier<java.util.concurrent.CompletableFuture<T>> future) {
    if (!async) {
      return sync.get();
    }
    try {
      return future.get().join();
    } catch (CompletionException e) {
      if (e.getCause() instanceof RuntimeException) {
        throw (RuntimeException) e.getCause();
      }
      throw e;
    }
  }

  @ParameterizedTest(name = "async={0}")
  @ValueSource(booleans = {false, true})
  void readsTheBalance(boolean async) {
    LabyrintheClient client = client();
    assertTrue(client.isTestMode());
    Balance balance =
        run(async, () -> client.balance().get(), () -> client.async().balance().get());
    assertTrue(balance.held() >= 0);
  }

  @ParameterizedTest(name = "async={0}")
  @ValueSource(booleans = {false, true})
  void estimatesASend(boolean async) {
    LabyrintheClient client = client();
    EstimateSmsParams ascii =
        EstimateSmsParams.builder()
            .body("Hello from the Java SDK")
            .recipients(List.of("0812345678", "not-a-number"))
            .build();
    Estimate estimate =
        run(async, () -> client.sms().estimate(ascii), () -> client.async().sms().estimate(ascii));
    assertEquals(Encoding.GSM7, estimate.encoding());
    assertEquals(1, estimate.segmentsPerMessage());
    assertEquals(1, estimate.recipientsCount());
    assertEquals(1, estimate.totalCredits());
    EstimateSmsParams accented =
        EstimateSmsParams.builder().body("Réunion à 9h").addRecipient("0812345678").build();
    assertEquals(
        Encoding.UCS2,
        run(
                async,
                () -> client.sms().estimate(accented),
                () -> client.async().sms().estimate(accented))
            .encoding());
  }

  @ParameterizedTest(name = "async={0}")
  @ValueSource(booleans = {false, true})
  void sendWithReferenceAndIdempotencyReplayThen409ThenListAndGet(boolean async) {
    LabyrintheClient client = client();
    String ref = reference();
    String idempotencyKey = "sdk-java-it-" + Long.toHexString(RANDOM.nextLong());
    String phone = randomPhone();

    SendSmsParams params =
        SendSmsParams.builder()
            .to(phone)
            .body("Integration test")
            .reference(ref)
            .idempotencyKey(idempotencyKey)
            .build();
    Message message =
        run(async, () -> client.sms().send(params), () -> client.async().sms().send(params));
    assertEquals(ref, message.reference().orElseThrow());
    assertEquals("243" + phone.substring(1), message.recipient());
    assertEquals(1, message.segments());
    assertNotEquals(MessageStatus.UNKNOWN, message.status());
    assertFalse(message.createdAt().isAfter(Instant.now().plusSeconds(5)));

    // Même clé, même contenu : rejoué, rien n'est renvoyé.
    SendManySmsParams replay =
        SendManySmsParams.builder()
            .addRecipient(phone)
            .body("Integration test")
            .reference(ref)
            .idempotencyKey(idempotencyKey)
            .build();
    MessageBatch batch =
        run(
            async,
            () -> client.sms().sendMany(replay),
            () -> client.async().sms().sendMany(replay));
    assertTrue(batch.replayed());
    assertEquals(message.id(), batch.messages().get(0).id());

    // Même clé, autre contenu : refusé.
    SendSmsParams other =
        SendSmsParams.builder()
            .to(phone)
            .body("Another body")
            .reference(ref)
            .idempotencyKey(idempotencyKey)
            .build();
    IdempotencyKeyReusedException reused =
        assertThrows(
            IdempotencyKeyReusedException.class,
            () ->
                run(async, () -> client.sms().send(other), () -> client.async().sms().send(other)));
    assertEquals(409, reused.statusCode());

    ListSmsParams byReference = ListSmsParams.builder().reference(ref).build();
    Page<Message> page = client.sms().list(byReference);
    assertEquals(1, page.total());
    assertEquals(message.id(), page.data().get(0).id());
    ListSmsParams filtered =
        ListSmsParams.builder().recipient(phone).reference(ref).perPage(5).build();
    assertEquals(
        1,
        run(
            async,
            () -> client.sms().list(filtered).total(),
            () -> client.async().sms().list(filtered).thenApply(AsyncPage::total)));
    List<String> ids = new ArrayList<>();
    ListSmsParams onePerPage = ListSmsParams.builder().reference(ref).perPage(1).build();
    if (async) {
      client
          .async()
          .sms()
          .list(onePerPage)
          .thenCompose(p -> p.autoPagingForEach(m -> ids.add(m.id())))
          .join();
    } else {
      client.sms().list(onePerPage).autoPagingIterable().forEach(m -> ids.add(m.id()));
    }
    assertEquals(List.of(message.id()), ids);
    assertEquals(
        message.id(),
        run(
                async,
                () -> client.sms().get(message.id()),
                () -> client.async().sms().get(message.id()))
            .id());
  }

  @ParameterizedTest(name = "async={0}")
  @ValueSource(booleans = {false, true})
  void aRefusedMultiSendSendsNothing(boolean async) {
    LabyrintheClient client = client();
    String ref = reference();
    SendManySmsParams params =
        SendManySmsParams.builder()
            .recipients(List.of(randomPhone(), randomPhone()))
            .body("Never sent")
            .sender("NOTAPPROVED")
            .reference(ref)
            .build();
    SenderIdNotApprovedException error =
        assertThrows(
            SenderIdNotApprovedException.class,
            () ->
                run(
                    async,
                    () -> client.sms().sendMany(params),
                    () -> client.async().sms().sendMany(params)));
    assertEquals("SENDER_ID_NOT_APPROVED", error.code());
    assertEquals(0, client.sms().list(ListSmsParams.builder().reference(ref).build()).total());
  }

  @ParameterizedTest(name = "async={0}")
  @ValueSource(booleans = {false, true})
  void otpSendThenWrongCodeThenVerifyThenAlreadyUsed(boolean async) {
    LabyrintheClient client = client();
    SendOtpParams params =
        SendOtpParams.builder().phone(randomPhone()).locale(OtpLocale.EN).build();
    OtpSendResult otp =
        run(async, () -> client.otp().send(params), () -> client.async().otp().send(params));
    assertFalse(otp.otpId().isEmpty());
    assertTrue(otp.expiresAt().isAfter(Instant.now()));
    String code =
        otp.testCode().orElseThrow(() -> new AssertionError("a test key returns test_code"));
    assertFalse(otp.toString().contains(code));

    String wrong = "000000".equals(code) ? "111111" : "000000";
    assertEquals(
        OtpVerifyResult.failed(OtpFailureReason.INVALID_CODE),
        run(
            async,
            () -> client.otp().verify(otp.otpId(), wrong),
            () -> client.async().otp().verify(otp.otpId(), wrong)));
    assertEquals(
        OtpVerifyResult.verified(),
        run(
            async,
            () -> client.otp().verify(otp.otpId(), code),
            () -> client.async().otp().verify(otp.otpId(), code)));
    assertEquals(
        OtpVerifyResult.failed(OtpFailureReason.ALREADY_USED),
        run(
            async,
            () -> client.otp().verify(otp.otpId(), code),
            () -> client.async().otp().verify(otp.otpId(), code)));
  }

  @Test
  void unknownMessageAndOtpIdGiveNotFound() {
    LabyrintheClient client = client();
    assertThrows(
        NotFoundException.class, () -> client.sms().get("00000000-0000-4000-8000-000000000000"));
    assertThrows(
        NotFoundException.class,
        () -> client.otp().verify("00000000-0000-4000-8000-000000000000", "123456"));
  }

  @Test
  void anInvalidKeyIsRejected() {
    LabyrintheClient invalid =
        LabyrintheClient.builder().apiKey("lsk_test_invalid").baseUrl(baseUrl).build();
    AuthenticationException error =
        assertThrows(AuthenticationException.class, () -> invalid.balance().get());
    assertEquals("UNAUTHENTICATED", error.code());
    assertInstanceOf(
        AuthenticationException.class,
        assertThrows(CompletionException.class, () -> invalid.async().balance().get().join())
            .getCause());
  }

  @Test
  void listsApprovedSenderIds() {
    for (SenderId senderId : client().senderIds().list()) {
      assertEquals(SenderIdStatus.APPROVED, senderId.status());
    }
  }
}

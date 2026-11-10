package cd.labyrinthe.connect;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertInstanceOf;
import static org.junit.jupiter.api.Assertions.assertNotEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.junit.jupiter.api.Assumptions.assumeTrue;

import cd.labyrinthe.connect.exception.ApiException;
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
    OtpVerifyResult failed =
        run(
            async,
            () -> client.otp().verify(otp.otpId(), wrong),
            () -> client.async().otp().verify(otp.otpId(), wrong));
    assertEquals(OtpFailureReason.INVALID_CODE, failed.reason().orElseThrow());
    // API 1.2.0 : une tentative consommée.
    assertEquals(otp.maxAttempts() - 1, failed.attemptsRemaining().orElseThrow());
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

  @ParameterizedTest(name = "async={0}")
  @ValueSource(booleans = {false, true})
  void otpSendIsIdempotentAndDescribesTheCode(boolean async) {
    LabyrintheClient client = client();
    String key = "sdk-java-it-otp-" + Long.toHexString(RANDOM.nextLong());
    SendOtpParams params = SendOtpParams.builder().phone(randomPhone()).idempotencyKey(key).build();
    OtpSendResult first =
        run(async, () -> client.otp().send(params), () -> client.async().otp().send(params));
    assertFalse(first.replayed());
    assertEquals(key, first.idempotencyKey());
    assertTrue(first.codeLength() >= 4 && first.codeLength() <= 8, first::toString);
    assertNotEquals(OtpCodeType.UNKNOWN, first.codeType());
    assertTrue(first.resendAfterSeconds() >= 0);
    String code = first.testCode().orElseThrow();
    assertEquals(first.codeLength(), code.length());
    if (first.codeType() == OtpCodeType.NUMERIC) {
      assertTrue(code.matches("[0-9]+"), "numeric code");
    }

    // Même clé, même contenu : rejeu, même OTP, aucun nouveau SMS.
    OtpSendResult replay =
        run(async, () -> client.otp().send(params), () -> client.async().otp().send(params));
    assertTrue(replay.replayed());
    assertEquals(first.otpId(), replay.otpId());
    assertEquals(code, replay.testCode().orElseThrow());
  }

  @ParameterizedTest(name = "async={0}")
  @ValueSource(booleans = {false, true})
  void stripAccentsGivesGsm7AndForeignNumbersAreKept(boolean async) {
    LabyrintheClient client = client();
    EstimateSmsParams accented =
        EstimateSmsParams.builder()
            .body("Réunion à l'hôtel demain")
            .recipients(List.of("0812345678", "243812345678", "+243812345678"))
            .stripAccents(true)
            .build();
    Estimate estimate =
        run(
            async,
            () -> client.sms().estimate(accented),
            () -> client.async().sms().estimate(accented));
    assertEquals(Encoding.GSM7, estimate.encoding());
    // Doublons fusionnés comme à l'envoi (API 1.2.0).
    assertEquals(1, estimate.recipientsCount());

    String foreign = String.format("336%08d", RANDOM.nextInt(100_000_000));
    SendSmsParams params =
        SendSmsParams.builder()
            .to(foreign)
            .body("Integration test")
            .reference(reference())
            .stripAccents(true)
            .build();
    Message message =
        run(async, () -> client.sms().send(params), () -> client.async().sms().send(params));
    assertEquals(foreign, message.recipient());
    assertEquals(Encoding.GSM7, message.encoding().orElseThrow());
  }

  @ParameterizedTest(name = "async={0}")
  @ValueSource(booleans = {false, true})
  void shortForeignNumberKeepsItsCountryCode(boolean async) {
    // « +248… » (Seychelles, 10 chiffres) ne doit pas recevoir l'indicatif
    // 243.
    LabyrintheClient client = client();
    String digits = String.format("2482%06d", RANDOM.nextInt(1_000_000));
    EstimateSmsParams estimateParams =
        EstimateSmsParams.builder()
            .body("Integration test")
            .addRecipient("+" + digits)
            .addRecipient("0812345678")
            .build();
    Estimate estimate =
        run(
            async,
            () -> client.sms().estimate(estimateParams),
            () -> client.async().sms().estimate(estimateParams));
    // Sans « + », « 2481234567 » deviendrait 2432481234567 et serait compté à part.
    assertEquals(2, estimate.recipientsCount());

    SendSmsParams params =
        SendSmsParams.builder()
            .to("+" + digits)
            .body("Integration test")
            .reference(reference())
            .build();
    Message message =
        run(async, () -> client.sms().send(params), () -> client.async().sms().send(params));
    assertEquals(digits, message.recipient());
  }

  @ParameterizedTest(name = "async={0}")
  @ValueSource(booleans = {false, true})
  void requestIdIsEchoedOnResponsesAndExceptions(boolean async) {
    LabyrintheClient client = client();
    String requestId = "sdk-java-it:" + Long.toHexString(RANDOM.nextLong());
    List<ResponseInfo> seen = new java.util.concurrent.CopyOnWriteArrayList<>();
    RequestOptions options =
        RequestOptions.builder().requestId(requestId).onResponse(seen::add).build();
    run(async, () -> client.balance().get(options), () -> client.async().balance().get(options));
    assertEquals(requestId, seen.get(0).requestId().orElseThrow());
    assertEquals("1", seen.get(0).apiVersion().orElseThrow());

    ApiException error =
        assertThrows(
            NotFoundException.class,
            () ->
                run(
                    async,
                    () -> client.sms().get("00000000-0000-4000-8000-000000000000", options),
                    () ->
                        client.async().sms().get("00000000-0000-4000-8000-000000000000", options)));
    assertEquals(requestId, error.requestId().orElseThrow());
    assertEquals("1", error.apiVersion().orElseThrow());
    // Sans identifiant fourni, l'API en génère un.
    ApiException generated =
        assertThrows(
            NotFoundException.class,
            () -> client.sms().get("00000000-0000-4000-8000-000000000000"));
    assertFalse(generated.requestId().orElseThrow().isEmpty());
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

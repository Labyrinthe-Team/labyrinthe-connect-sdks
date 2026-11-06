package cd.labyrinthe.connect;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertSame;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import cd.labyrinthe.connect.exception.AccountSuspendedException;
import cd.labyrinthe.connect.exception.ApiException;
import cd.labyrinthe.connect.exception.AuthenticationException;
import cd.labyrinthe.connect.exception.FeatureDisabledException;
import cd.labyrinthe.connect.exception.IdempotencyKeyReusedException;
import cd.labyrinthe.connect.exception.IdempotencyRequestInProgressException;
import cd.labyrinthe.connect.exception.InsufficientCreditsException;
import cd.labyrinthe.connect.exception.InvalidPhoneException;
import cd.labyrinthe.connect.exception.LabyrintheException;
import cd.labyrinthe.connect.exception.NotFoundException;
import cd.labyrinthe.connect.exception.OtpRateLimitedException;
import cd.labyrinthe.connect.exception.OtpResendTooSoonException;
import cd.labyrinthe.connect.exception.PermissionException;
import cd.labyrinthe.connect.exception.RateLimitException;
import cd.labyrinthe.connect.exception.RecipientNotSendableException;
import cd.labyrinthe.connect.exception.SenderIdNotApprovedException;
import cd.labyrinthe.connect.exception.ServiceUnavailableException;
import cd.labyrinthe.connect.exception.ValidationException;
import java.time.Duration;
import java.time.Instant;
import java.util.List;
import java.util.Map;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.CsvSource;

class ErrorsTest {

  private static LabyrintheException failWith(MockApi.Reply reply) {
    try (MockApi api = new MockApi()) {
      api.enqueue(reply);
      return assertThrows(
          LabyrintheException.class, () -> api.builder().maxRetries(0).build().balance().get());
    }
  }

  @ParameterizedTest
  @CsvSource({
    "401, UNAUTHENTICATED, AuthenticationException",
    "403, FORBIDDEN, PermissionException",
    "403, OTP_DISABLED_FOR_ACCOUNT, PermissionException",
    "403, ACCOUNT_SUSPENDED, AccountSuspendedException",
    "404, NOT_FOUND, NotFoundException",
    "422, VALIDATION_ERROR, ValidationException",
    "422, RECIPIENT_NOT_SENDABLE, RecipientNotSendableException",
    "422, SENDER_ID_NOT_APPROVED, SenderIdNotApprovedException",
    "422, INVALID_PHONE, InvalidPhoneException",
    "422, INVALID_OTP_TEMPLATE, ApiException",
    "409, INSUFFICIENT_CREDITS, InsufficientCreditsException",
    "409, IDEMPOTENCY_KEY_REUSED, IdempotencyKeyReusedException",
    "409, IDEMPOTENCY_KEY_UNRESOLVED, IdempotencyKeyUnresolvedException",
    "409, IDEMPOTENCY_REQUEST_IN_PROGRESS, IdempotencyRequestInProgressException",
    "429, TOO_MANY_REQUESTS, RateLimitException",
    "429, OTP_RESEND_TOO_SOON, OtpResendTooSoonException",
    "429, OTP_RATE_LIMITED, OtpRateLimitedException",
    "503, OTP_SERVICE_DISABLED, FeatureDisabledException",
    "503, FEATURE_DISABLED, FeatureDisabledException",
    "418, SOMETHING_NEW, ApiException",
    "500, HTTP_ERROR, ServiceUnavailableException",
    "401, SOMETHING_NEW, AuthenticationException",
    "403, SOMETHING_NEW, PermissionException",
    "404, SOMETHING_NEW, NotFoundException",
    "429, SOMETHING_NEW, RateLimitException",
  })
  void mapsEachCodeToItsClass(int status, String code, String simpleName) {
    LabyrintheException error = failWith(MockApi.error(status, code));
    assertEquals(simpleName, error.getClass().getSimpleName());
    assertTrue(error instanceof ApiException);
    assertEquals(code, error.code());
    assertEquals(status, error.statusCode());
    assertEquals("Message de l'API.", error.getMessage());
  }

  @Test
  void fallsBackWhenTheBodyIsNotAnApiError() {
    LabyrintheException html = failWith(MockApi.json(502, "<html>Bad gateway</html>"));
    assertTrue(html instanceof ServiceUnavailableException);
    assertEquals("HTTP_ERROR", html.code());
    assertEquals("Labyrinthe Connect API returned HTTP 502.", html.getMessage());

    LabyrintheException throttled = failWith(MockApi.json(429, ""));
    assertTrue(throttled instanceof RateLimitException);
    assertEquals("TOO_MANY_REQUESTS", throttled.code());

    LabyrintheException teapot = failWith(MockApi.json(418, "{\"error\":\"nope\"}"));
    assertSame(ApiException.class, teapot.getClass());
    assertEquals("HTTP_ERROR", teapot.code());
  }

  @Test
  void phpEmptyArrayDetailsBecomeAnEmptyMap() {
    LabyrintheException error = failWith(MockApi.error(404, "NOT_FOUND", "[]"));
    assertEquals(Map.of(), error.details());
  }

  @Test
  void validationErrorsExposeFieldErrors() {
    ValidationException error =
        (ValidationException)
            failWith(
                MockApi.error(
                    422,
                    "VALIDATION_ERROR",
                    "{\"body\":[\"Le champ body est obligatoire.\"],\"recipients.0\":[\"a\",\"b\"],"
                        + "\"odd\":\"single\"}"));
    assertEquals(List.of("Le champ body est obligatoire."), error.fieldErrors().get("body"));
    assertEquals(List.of("a", "b"), error.fieldErrors().get("recipients.0"));
    assertEquals(List.of("single"), error.fieldErrors().get("odd"));
  }

  @Test
  void recipientNotSendableListsEveryRejection() {
    RecipientNotSendableException error =
        (RecipientNotSendableException)
            failWith(
                MockApi.error(
                    422,
                    "RECIPIENT_NOT_SENDABLE",
                    "{\"reason\":\"blacklisted\",\"rejected\":["
                        + "{\"recipient\":\"243812345678\",\"reason\":\"blacklisted\"},"
                        + "{\"recipient\":\"243899999999\",\"reason\":\"opted_out\"},"
                        + "{\"recipient\":\"x\",\"reason\":\"new_reason\"},{\"bad\":1}]}"));
    assertEquals(RejectionReason.BLACKLISTED, error.reason());
    assertEquals(
        List.of(
            new RejectedRecipient("243812345678", RejectionReason.BLACKLISTED),
            new RejectedRecipient("243899999999", RejectionReason.OPTED_OUT),
            new RejectedRecipient("x", RejectionReason.UNKNOWN)),
        error.rejected());

    RecipientNotSendableException otp =
        (RecipientNotSendableException)
            failWith(MockApi.error(422, "RECIPIENT_NOT_SENDABLE", "{\"reason\":\"opted_out\"}"));
    assertEquals(RejectionReason.OPTED_OUT, otp.reason());
    assertTrue(otp.rejected().isEmpty());
  }

  @Test
  void insufficientCreditsExposesAmounts() {
    InsufficientCreditsException error =
        (InsufficientCreditsException)
            failWith(
                MockApi.error(
                    409, "INSUFFICIENT_CREDITS", "{\"available\":3,\"required\":\"12\"}"));
    assertEquals(3, error.available().getAsLong());
    assertEquals(12, error.required().getAsLong());
    InsufficientCreditsException empty =
        (InsufficientCreditsException) failWith(MockApi.error(409, "INSUFFICIENT_CREDITS"));
    assertFalse(empty.available().isPresent());
  }

  @Test
  void rateLimitsExposeRetryAfterScopeAndLimit() {
    RateLimitException header =
        (RateLimitException)
            failWith(MockApi.error(429, "TOO_MANY_REQUESTS").header("Retry-After", "45"));
    assertEquals(Duration.ofSeconds(45), header.retryAfter().orElseThrow());

    OtpResendTooSoonException tooSoon =
        (OtpResendTooSoonException)
            failWith(MockApi.error(429, "OTP_RESEND_TOO_SOON", "{\"retry_after_seconds\":30}"));
    assertEquals(Duration.ofSeconds(30), tooSoon.retryAfter().orElseThrow());

    OtpRateLimitedException limited =
        (OtpRateLimitedException)
            failWith(
                MockApi.error(429, "OTP_RATE_LIMITED", "{\"scope\":\"account\",\"limit\":100}"));
    assertEquals(OtpRateLimitScope.ACCOUNT, limited.scope());
    assertEquals(100, limited.limit().getAsLong());
    assertFalse(limited.retryAfter().isPresent());

    // API 1.2.0 : Retry-After sur les deux limites métier OTP.
    OtpRateLimitedException withHeader =
        (OtpRateLimitedException)
            failWith(
                MockApi.error(
                        429,
                        "OTP_RATE_LIMITED",
                        "{\"retry_after_seconds\":1800,\"scope\":\"phone\",\"limit\":5}")
                    .header("Retry-After", "1800"));
    assertEquals(Duration.ofMinutes(30), withHeader.retryAfter().orElseThrow());
    assertEquals(OtpRateLimitScope.PHONE, withHeader.scope());
    OtpResendTooSoonException tooSoonHeader =
        (OtpResendTooSoonException)
            failWith(
                MockApi.error(429, "OTP_RESEND_TOO_SOON", "{\"retry_after_seconds\":42}")
                    .header("Retry-After", "42"));
    assertEquals(Duration.ofSeconds(42), tooSoonHeader.retryAfter().orElseThrow());

    IdempotencyRequestInProgressException inProgress =
        (IdempotencyRequestInProgressException)
            failWith(
                MockApi.error(
                    409, "IDEMPOTENCY_REQUEST_IN_PROGRESS", "{\"retry_after_seconds\":5}"));
    assertEquals(Duration.ofSeconds(5), inProgress.retryAfter().orElseThrow());
  }

  @Test
  void featureDisabledExposesItsDetails() {
    FeatureDisabledException error =
        (FeatureDisabledException)
            failWith(
                MockApi.error(
                        503,
                        "FEATURE_DISABLED",
                        "{\"feature\":\"api.sms_send\",\"blocked_by\":\"api\","
                            + "\"message\":\"Maintenance jusqu'à 10 h.\","
                            + "\"until\":\"2026-11-06T10:00:00+00:00\"}")
                    .header("Retry-After", "1800"));
    assertTrue(error instanceof ServiceUnavailableException);
    assertEquals("api.sms_send", error.feature().orElseThrow());
    assertEquals("api", error.blockedBy().orElseThrow());
    assertEquals("Maintenance jusqu'à 10 h.", error.adminMessage().orElseThrow());
    assertEquals(Instant.parse("2026-11-06T10:00:00Z"), error.until().orElseThrow());
    assertEquals(Duration.ofMinutes(30), error.retryAfter().orElseThrow());

    // Retour inconnu : message et until à null, pas de Retry-After.
    FeatureDisabledException unknown =
        (FeatureDisabledException)
            failWith(
                MockApi.error(
                    503,
                    "FEATURE_DISABLED",
                    "{\"feature\":\"api\",\"blocked_by\":\"api\",\"message\":null,"
                        + "\"until\":null}"));
    assertEquals("api", unknown.feature().orElseThrow());
    assertFalse(unknown.adminMessage().isPresent());
    assertFalse(unknown.until().isPresent());
    assertFalse(unknown.retryAfter().isPresent());

    // Code historique de POST /otp/send, mêmes détails.
    FeatureDisabledException otp =
        (FeatureDisabledException)
            failWith(
                MockApi.error(
                    503,
                    "OTP_SERVICE_DISABLED",
                    "{\"feature\":\"otp\",\"blocked_by\":\"otp\",\"message\":null,"
                        + "\"until\":null}"));
    assertEquals("otp", otp.feature().orElseThrow());
    assertEquals("otp", otp.blockedBy().orElseThrow());
  }

  @Test
  void featureDisabledToleratesMissingOrMalformedDetails() {
    FeatureDisabledException empty =
        (FeatureDisabledException) failWith(MockApi.error(503, "FEATURE_DISABLED"));
    assertFalse(empty.feature().isPresent());
    assertFalse(empty.blockedBy().isPresent());
    assertFalse(empty.adminMessage().isPresent());
    assertFalse(empty.until().isPresent());
    assertFalse(empty.retryAfter().isPresent());

    FeatureDisabledException legacy =
        (FeatureDisabledException) failWith(MockApi.error(503, "OTP_SERVICE_DISABLED", "[]"));
    assertFalse(legacy.feature().isPresent());

    FeatureDisabledException malformed =
        (FeatureDisabledException)
            failWith(
                MockApi.error(
                        503,
                        "FEATURE_DISABLED",
                        "{\"feature\":12,\"blocked_by\":[\"api\"],\"message\":\"\","
                            + "\"until\":\"demain\"}")
                    .header("Retry-After", "plus tard"));
    assertFalse(malformed.feature().isPresent());
    assertFalse(malformed.blockedBy().isPresent());
    assertFalse(malformed.adminMessage().isPresent());
    assertFalse(malformed.until().isPresent());
    assertFalse(malformed.retryAfter().isPresent());
    assertEquals("Message de l'API.", malformed.getMessage());
  }

  @Test
  void exposesHeadersAndRequestId() {
    ApiException error =
        (ApiException) failWith(MockApi.error(404, "NOT_FOUND").header("X-Request-Id", "req-123"));
    assertEquals("req-123", error.requestId().orElseThrow());
    assertEquals("req-123", error.headers().get("x-request-id"));
    assertFalse(error.apiVersion().isPresent());
    ApiException versioned =
        (ApiException)
            failWith(
                MockApi.error(403, "ACCOUNT_SUSPENDED")
                    .header("X-Request-Id", "5f0c2b9e-1d3a-4e7f-8b6c-9a0d2e4f6b81")
                    .header("X-Api-Version", "1"));
    assertEquals("5f0c2b9e-1d3a-4e7f-8b6c-9a0d2e4f6b81", versioned.requestId().orElseThrow());
    assertEquals("1", versioned.apiVersion().orElseThrow());
    assertEquals(
        "NotFoundException(code=NOT_FOUND, statusCode=404, message=Message de l'API.)",
        error.toString());
    assertFalse(((ApiException) failWith(MockApi.error(404, "NOT_FOUND"))).requestId().isPresent());
  }

  @Test
  void hierarchy() {
    assertTrue(
        new OtpResendTooSoonException("m", "c", 429, null, null) instanceof RateLimitException);
    assertTrue(
        new OtpRateLimitedException("m", "c", 429, null, null) instanceof RateLimitException);
    assertTrue(new AuthenticationException("m", "c", 401, null, null) instanceof ApiException);
    assertTrue(new PermissionException("m", "c", 403, null, null) instanceof ApiException);
    assertTrue(
        new AccountSuspendedException("m", "c", 403, null, null) instanceof PermissionException);
    assertTrue(
        new IdempotencyRequestInProgressException("m", "c", 409, null, null)
            instanceof ApiException);
    assertTrue(new NotFoundException("m", "c", 404, null, null) instanceof ApiException);
    assertTrue(new InvalidPhoneException("m", "c", 422, null, null) instanceof ApiException);
    assertTrue(new SenderIdNotApprovedException("m", "c", 422, null, null) instanceof ApiException);
    assertTrue(
        new IdempotencyKeyReusedException("m", "c", 409, null, null) instanceof ApiException);
    assertTrue(
        new FeatureDisabledException("m", "c", 503, null, null)
            instanceof ServiceUnavailableException);
    assertTrue(new ApiException("m", "c", 400, null, null) instanceof RuntimeException);
  }
}

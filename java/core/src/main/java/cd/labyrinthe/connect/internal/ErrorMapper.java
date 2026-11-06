package cd.labyrinthe.connect.internal;

import cd.labyrinthe.connect.exception.AccountSuspendedException;
import cd.labyrinthe.connect.exception.ApiException;
import cd.labyrinthe.connect.exception.AuthenticationException;
import cd.labyrinthe.connect.exception.FeatureDisabledException;
import cd.labyrinthe.connect.exception.IdempotencyKeyReusedException;
import cd.labyrinthe.connect.exception.IdempotencyKeyUnresolvedException;
import cd.labyrinthe.connect.exception.IdempotencyRequestInProgressException;
import cd.labyrinthe.connect.exception.InsufficientCreditsException;
import cd.labyrinthe.connect.exception.InvalidPhoneException;
import cd.labyrinthe.connect.exception.NotFoundException;
import cd.labyrinthe.connect.exception.OtpRateLimitedException;
import cd.labyrinthe.connect.exception.OtpResendTooSoonException;
import cd.labyrinthe.connect.exception.PermissionException;
import cd.labyrinthe.connect.exception.RateLimitException;
import cd.labyrinthe.connect.exception.RecipientNotSendableException;
import cd.labyrinthe.connect.exception.SenderIdNotApprovedException;
import cd.labyrinthe.connect.exception.ServiceUnavailableException;
import cd.labyrinthe.connect.exception.ValidationException;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.Map;

/** Conversion d'une réponse d'erreur en exception typée. Usage interne. */
public final class ErrorMapper {

  /** Construit une exception à partir de (message, code, statut, détails, en-têtes). */
  @FunctionalInterface
  public interface Factory {
    /**
     * @param message message
     * @param code code
     * @param status statut HTTP
     * @param details détails
     * @param headers en-têtes
     * @return l'exception
     */
    ApiException create(
        String message,
        String code,
        int status,
        Map<String, Object> details,
        Map<String, String> headers);
  }

  /** Classe d'exception par code d'erreur de l'API (vérifié contre la spec par les tests). */
  public static final Map<String, Class<? extends ApiException>> CLASSES_BY_CODE;

  private static final Map<String, Factory> FACTORIES;

  static {
    Map<String, Factory> factories = new LinkedHashMap<>();
    Map<String, Class<? extends ApiException>> classes = new LinkedHashMap<>();
    register(
        factories,
        classes,
        "UNAUTHENTICATED",
        AuthenticationException.class,
        AuthenticationException::new);
    register(factories, classes, "FORBIDDEN", PermissionException.class, PermissionException::new);
    register(
        factories,
        classes,
        "ACCOUNT_SUSPENDED",
        AccountSuspendedException.class,
        AccountSuspendedException::new);
    register(
        factories,
        classes,
        "OTP_DISABLED_FOR_ACCOUNT",
        PermissionException.class,
        PermissionException::new);
    register(factories, classes, "NOT_FOUND", NotFoundException.class, NotFoundException::new);
    register(
        factories,
        classes,
        "VALIDATION_ERROR",
        ValidationException.class,
        ValidationException::new);
    register(
        factories,
        classes,
        "RECIPIENT_NOT_SENDABLE",
        RecipientNotSendableException.class,
        RecipientNotSendableException::new);
    register(
        factories,
        classes,
        "SENDER_ID_NOT_APPROVED",
        SenderIdNotApprovedException.class,
        SenderIdNotApprovedException::new);
    register(
        factories,
        classes,
        "INVALID_PHONE",
        InvalidPhoneException.class,
        InvalidPhoneException::new);
    register(
        factories,
        classes,
        "INSUFFICIENT_CREDITS",
        InsufficientCreditsException.class,
        InsufficientCreditsException::new);
    register(
        factories,
        classes,
        "IDEMPOTENCY_KEY_REUSED",
        IdempotencyKeyReusedException.class,
        IdempotencyKeyReusedException::new);
    register(
        factories,
        classes,
        "IDEMPOTENCY_KEY_UNRESOLVED",
        IdempotencyKeyUnresolvedException.class,
        IdempotencyKeyUnresolvedException::new);
    register(
        factories,
        classes,
        "IDEMPOTENCY_REQUEST_IN_PROGRESS",
        IdempotencyRequestInProgressException.class,
        IdempotencyRequestInProgressException::new);
    register(
        factories, classes, "TOO_MANY_REQUESTS", RateLimitException.class, RateLimitException::new);
    register(
        factories,
        classes,
        "OTP_RESEND_TOO_SOON",
        OtpResendTooSoonException.class,
        OtpResendTooSoonException::new);
    register(
        factories,
        classes,
        "OTP_RATE_LIMITED",
        OtpRateLimitedException.class,
        OtpRateLimitedException::new);
    register(
        factories,
        classes,
        "OTP_SERVICE_DISABLED",
        FeatureDisabledException.class,
        FeatureDisabledException::new);
    register(
        factories,
        classes,
        "FEATURE_DISABLED",
        FeatureDisabledException.class,
        FeatureDisabledException::new);
    FACTORIES = Collections.unmodifiableMap(factories);
    CLASSES_BY_CODE = Collections.unmodifiableMap(classes);
  }

  private ErrorMapper() {}

  private static void register(
      Map<String, Factory> factories,
      Map<String, Class<? extends ApiException>> classes,
      String code,
      Class<? extends ApiException> type,
      Factory factory) {
    factories.put(code, factory);
    classes.put(code, type);
  }

  /**
   * Exception typée d'une réponse d'erreur : par code d'abord, puis par statut HTTP, sinon {@link
   * ApiException}.
   *
   * @param status statut HTTP
   * @param body corps brut de la réponse (peut être vide ou non JSON)
   * @param headers en-têtes (noms en minuscules)
   * @return l'exception
   */
  public static ApiException fromResponse(int status, String body, Map<String, String> headers) {
    String code = null;
    String message = null;
    Map<String, Object> details = Collections.emptyMap();
    Object decoded = null;
    if (body != null && !body.trim().isEmpty()) {
      try {
        decoded = Json.parse(body);
      } catch (Json.JsonException notJson) {
        decoded = null;
      }
    }
    if (decoded instanceof Map && ((Map<?, ?>) decoded).get("error") instanceof Map) {
      Map<?, ?> error = (Map<?, ?>) ((Map<?, ?>) decoded).get("error");
      code = error.get("code") instanceof String ? (String) error.get("code") : null;
      message = error.get("message") instanceof String ? (String) error.get("message") : null;
      // Toujours un objet depuis l'API 1.2.0 ; un [] (API plus ancienne) donne aussi {}.
      if (error.get("details") instanceof Map) {
        @SuppressWarnings("unchecked")
        Map<String, Object> map = (Map<String, Object>) error.get("details");
        details = map;
      }
    }
    if (code == null) {
      code = status == 429 ? "TOO_MANY_REQUESTS" : "HTTP_ERROR";
    }
    if (message == null) {
      message = "Labyrinthe Connect API returned HTTP " + status + ".";
    }
    Factory factory = FACTORIES.get(code);
    if (factory == null) {
      factory = forStatus(status);
    }
    return factory.create(message, code, status, details, headers);
  }

  private static Factory forStatus(int status) {
    if (status == 401) {
      return AuthenticationException::new;
    }
    if (status == 403) {
      return PermissionException::new;
    }
    if (status == 404) {
      return NotFoundException::new;
    }
    if (status == 429) {
      return RateLimitException::new;
    }
    if (status >= 500) {
      return ServiceUnavailableException::new;
    }
    return ApiException::new;
  }
}

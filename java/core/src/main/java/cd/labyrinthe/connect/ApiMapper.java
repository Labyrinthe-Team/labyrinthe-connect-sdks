package cd.labyrinthe.connect;

import cd.labyrinthe.connect.exception.InvalidArgumentException;
import cd.labyrinthe.connect.exception.UnexpectedResponseException;
import cd.labyrinthe.connect.internal.JsonReader;
import cd.labyrinthe.connect.internal.Redact;
import cd.labyrinthe.connect.internal.Transport;
import cd.labyrinthe.connect.internal.Transport.RetryMode;
import java.time.Duration;
import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;

/**
 * Construction des requêtes et lecture des réponses, communes aux clients synchrone et asynchrone.
 * Usage interne.
 */
final class ApiMapper {

  static final JsonReader API =
      new JsonReader("Unexpected API response", UnexpectedResponseException::new);

  private final String defaultCountryCode;
  private final String defaultSender;

  ApiMapper(String defaultCountryCode, String defaultSender) {
    this.defaultCountryCode = defaultCountryCode;
    this.defaultSender = defaultSender;
  }

  // -- SMS ----------------------------------------------------------------------------------------

  /** Un envoi préparé et la clé d'idempotence qu'il porte. */
  static final class Dispatch {
    final Transport.Request request;
    final String idempotencyKey;

    Dispatch(Transport.Request request, String idempotencyKey) {
      this.request = request;
      this.idempotencyKey = idempotencyKey;
    }
  }

  Dispatch send(SendSmsParams params, RequestOptions options) {
    Map<String, Object> target = new LinkedHashMap<>();
    target.put("recipient", PhoneNumbers.normalizeOrThrow(params.to(), defaultCountryCode, "to"));
    return dispatch(
        target,
        params.body(),
        params.sender().orElse(null),
        params.reference().orElse(null),
        params.idempotencyKey().orElse(null),
        options);
  }

  Dispatch sendMany(SendManySmsParams params, RequestOptions options) {
    List<String> normalized = new ArrayList<>();
    List<Integer> invalid = new ArrayList<>();
    List<String> recipients = params.recipients();
    for (int i = 0; i < recipients.size(); i++) {
      String number = PhoneNumbers.normalize(recipients.get(i), defaultCountryCode).orElse(null);
      if (number == null) {
        invalid.add(i);
      } else {
        normalized.add(number);
      }
    }
    if (!invalid.isEmpty()) {
      StringBuilder shown = new StringBuilder();
      for (int i = 0; i < Math.min(20, invalid.size()); i++) {
        shown.append(i == 0 ? "" : ", ").append(invalid.get(i));
      }
      if (invalid.size() > 20) {
        shown.append('…');
      }
      // Les numéros eux-mêmes ne figurent jamais dans le message (seulement leur position).
      throw new InvalidArgumentException(
          "Invalid phone number(s) at recipients index " + shown + ". Nothing was sent.");
    }
    Map<String, Object> target = new LinkedHashMap<>();
    target.put("recipients", Collections.unmodifiableList(normalized));
    return dispatch(
        target,
        params.body(),
        params.sender().orElse(null),
        params.reference().orElse(null),
        params.idempotencyKey().orElse(null),
        options);
  }

  private Dispatch dispatch(
      Map<String, Object> target,
      String body,
      String sender,
      String reference,
      String idempotencyKey,
      RequestOptions options) {
    String effectiveSender = Checks.sender(sender != null ? sender : defaultSender);
    // Générée une fois et réutilisée par chaque relance de cet appel.
    String key = idempotencyKey != null ? idempotencyKey : UUID.randomUUID().toString();
    Map<String, Object> payload = new LinkedHashMap<>(target);
    payload.put("body", body);
    if (effectiveSender != null) {
      payload.put("sender", effectiveSender);
    }
    if (reference != null && !reference.isEmpty()) {
      payload.put("reference", reference);
    }
    Map<String, String> headers = Collections.singletonMap("Idempotency-Key", key);
    return new Dispatch(
        new Transport.Request(
            "POST", "/sms", RetryMode.SAFE, null, payload, headers, timeout(options)),
        key);
  }

  static MessageBatch batch(Transport.Response response, String idempotencyKey) {
    List<Message> messages = new ArrayList<>();
    for (Map<String, Object> item : API.objects(response.body(), "data")) {
      messages.add(message(item));
    }
    return new MessageBatch(
        messages, idempotencyKey, "true".equals(response.header("idempotent-replayed")));
  }

  static Message first(MessageBatch batch) {
    if (batch.messages().isEmpty()) {
      throw new UnexpectedResponseException("Unexpected API response: no message was returned.");
    }
    return batch.messages().get(0);
  }

  Transport.Request estimate(EstimateSmsParams params, RequestOptions options) {
    List<String> recipients = new ArrayList<>();
    for (String recipient : params.recipients()) {
      // Les numéros invalides sont transmis tels quels : l'API les ignore dans le décompte.
      if (recipient != null) {
        recipients.add(PhoneNumbers.normalize(recipient, defaultCountryCode).orElse(recipient));
      }
    }
    Map<String, Object> payload = new LinkedHashMap<>();
    payload.put("body", params.body());
    payload.put("recipients", recipients);
    return new Transport.Request(
        "POST", "/sms/estimate", RetryMode.SAFE, null, payload, null, timeout(options));
  }

  static Estimate estimate(Transport.Response response) {
    Map<String, Object> data = API.object(response.body(), "data");
    return new Estimate(
        Encoding.fromValue(API.string(data, "encoding")),
        API.intValue(data, "characters"),
        API.intValue(data, "segments_per_message"),
        API.intValue(data, "recipients_count"),
        API.longValue(data, "total_credits"),
        API.nullableLong(data, "balance_after"));
  }

  static Transport.Request get(String id, RequestOptions options) {
    if (id == null || id.trim().isEmpty()) {
      throw new InvalidArgumentException("The message id cannot be empty.");
    }
    return new Transport.Request(
        "GET", "/sms/" + Redact.encode(id), RetryMode.SAFE, null, null, null, timeout(options));
  }

  static Message message(Transport.Response response) {
    return message(API.object(response.body(), "data"));
  }

  Transport.Request list(ListSmsParams params, int page, RequestOptions options) {
    Map<String, Object> query = new LinkedHashMap<>();
    query.put("status", params.status().map(MessageStatus::value).orElse(null));
    query.put(
        "recipient",
        params
            .recipient()
            .map(r -> PhoneNumbers.normalize(r, defaultCountryCode).orElse(r))
            .orElse(null));
    query.put("reference", params.reference().orElse(null));
    query.put("from", params.from().orElse(null));
    query.put("to", params.to().orElse(null));
    query.put("per_page", params.perPage());
    query.put("page", page);
    return new Transport.Request(
        "GET", "/sms", RetryMode.SAFE, query, null, null, timeout(options));
  }

  /** Valeurs d'une page : messages, page courante, dernière page, taille, total. */
  static final class PageValues {
    final List<Message> data;
    final int currentPage;
    final int lastPage;
    final int perPage;
    final long total;

    PageValues(List<Message> data, int currentPage, int lastPage, int perPage, long total) {
      this.data = data;
      this.currentPage = currentPage;
      this.lastPage = lastPage;
      this.perPage = perPage;
      this.total = total;
    }
  }

  static PageValues page(Transport.Response response, int requested) {
    Map<String, Object> meta = API.optionalObject(response.body(), "meta");
    List<Message> data = new ArrayList<>();
    for (Map<String, Object> item : API.objects(response.body(), "data")) {
      data.add(message(item));
    }
    Integer current = API.nullableInt(meta, "current_page");
    Integer last = API.nullableInt(meta, "last_page");
    Integer per = API.nullableInt(meta, "per_page");
    Long total = API.nullableLong(meta, "total");
    return new PageValues(
        data,
        current != null ? current : requested,
        last != null ? last : requested,
        per != null ? per : data.size(),
        total != null ? total : data.size());
  }

  static Message message(Map<String, Object> data) {
    String encoding = API.nullableString(data, "encoding");
    Integer segments = API.nullableInt(data, "segments");
    Long credits = API.nullableLong(data, "credits_charged");
    return new Message(
        API.string(data, "id"),
        API.nullableString(data, "reference"),
        API.string(data, "recipient"),
        API.nullableString(data, "sender"),
        // Nullable dans le backend malgré la spec (BACKEND-TODO n° 9).
        encoding == null ? null : Encoding.fromValue(encoding),
        MessageStatus.fromValue(API.string(data, "status")),
        segments == null ? 0 : segments,
        credits == null ? 0 : credits,
        API.nullableString(data, "campaign_id"),
        API.nullableString(data, "provider_message_id"),
        API.nullableString(data, "error_code"),
        API.nullableString(data, "error_message"),
        API.instant(data, "created_at"),
        API.nullableInstant(data, "submitted_at"),
        API.nullableInstant(data, "delivered_at"));
  }

  // -- compte -------------------------------------------------------------------------------------

  static Transport.Request balance(RequestOptions options) {
    return new Transport.Request(
        "GET", "/balance", RetryMode.SAFE, null, null, null, timeout(options));
  }

  static Balance balance(Transport.Response response) {
    Map<String, Object> data = API.object(response.body(), "data");
    return new Balance(API.longValue(data, "balance"), API.longValue(data, "held"));
  }

  static Transport.Request senderIds(RequestOptions options) {
    return new Transport.Request(
        "GET", "/sender-ids", RetryMode.SAFE, null, null, null, timeout(options));
  }

  static List<SenderId> senderIds(Transport.Response response) {
    List<SenderId> list = new ArrayList<>();
    for (Map<String, Object> data : API.objects(response.body(), "data")) {
      list.add(
          new SenderId(
              API.string(data, "id"),
              API.string(data, "value"),
              SenderIdStatus.fromValue(API.string(data, "status")),
              API.nullableString(data, "purpose"),
              API.nullableString(data, "rejection_reason"),
              data.get("is_default") != null && API.bool(data, "is_default"),
              API.nullableInstant(data, "created_at")));
    }
    return Collections.unmodifiableList(list);
  }

  // -- OTP ----------------------------------------------------------------------------------------

  Transport.Request otpSend(SendOtpParams params, RequestOptions options) {
    Map<String, Object> payload = new LinkedHashMap<>();
    payload.put(
        "phone", PhoneNumbers.normalizeOrThrow(params.phone(), defaultCountryCode, "phone"));
    params.template().ifPresent(template -> payload.put("template", template));
    params.locale().ifPresent(locale -> payload.put("locale", locale.value()));
    return new Transport.Request(
        "POST", "/otp/send", RetryMode.THROTTLED_ONLY, null, payload, null, timeout(options));
  }

  static OtpSendResult otpSend(Transport.Response response) {
    Map<String, Object> data = API.object(response.body(), "data");
    return new OtpSendResult(
        API.string(data, "otp_id"),
        API.instant(data, "expires_at"),
        API.intValue(data, "max_attempts"),
        API.nullableString(data, "test_code"));
  }

  static Transport.Request otpVerify(String otpId, String code, RequestOptions options) {
    if (otpId == null || otpId.trim().isEmpty()) {
      throw new InvalidArgumentException("otpId cannot be empty.");
    }
    if (code == null || code.trim().isEmpty() || code.length() > Limits.MAX_OTP_CODE_LENGTH) {
      // Le code saisi n'apparaît jamais dans le message.
      throw new InvalidArgumentException(
          "The code must be 1 to " + Limits.MAX_OTP_CODE_LENGTH + " characters.");
    }
    Map<String, Object> payload = new LinkedHashMap<>();
    payload.put("otp_id", otpId);
    payload.put("code", code);
    return new Transport.Request(
        "POST", "/otp/verify", RetryMode.THROTTLED_ONLY, null, payload, null, timeout(options));
  }

  static OtpVerifyResult otpVerify(Transport.Response response) {
    Map<String, Object> data = API.object(response.body(), "data");
    if (API.bool(data, "verified")) {
      return OtpVerifyResult.verified();
    }
    return OtpVerifyResult.failed(OtpFailureReason.fromValue(API.nullableString(data, "reason")));
  }

  private static Duration timeout(RequestOptions options) {
    return options == null ? null : options.timeoutOverride().orElse(null);
  }
}

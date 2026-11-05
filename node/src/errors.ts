import type { OtpRateLimitScope, RejectedRecipient, RejectionReason } from './types.js';

/** Error codes documented by the API. The list is open: new codes may appear. */
export type ApiErrorCode =
  | 'UNAUTHENTICATED'
  | 'FORBIDDEN'
  | 'NOT_FOUND'
  | 'VALIDATION_ERROR'
  | 'TOO_MANY_REQUESTS'
  | 'INSUFFICIENT_CREDITS'
  | 'IDEMPOTENCY_KEY_REUSED'
  | 'RECIPIENT_NOT_SENDABLE'
  | 'SENDER_ID_NOT_APPROVED'
  | 'INVALID_PHONE'
  | 'INVALID_OTP_TEMPLATE'
  | 'OTP_RESEND_TOO_SOON'
  | 'OTP_RATE_LIMITED'
  | 'OTP_DISABLED_FOR_ACCOUNT'
  | 'OTP_SERVICE_DISABLED'
  | 'HTTP_ERROR';

/** Codes of the errors raised by the SDK itself (no API response). */
export type SdkErrorCode =
  | 'CONNECTION_ERROR'
  | 'TIMEOUT'
  | 'REQUEST_ABORTED'
  | 'INVALID_ARGUMENT'
  | 'UNEXPECTED_RESPONSE'
  | 'SIGNATURE_VERIFICATION_FAILED'
  | 'INVALID_WEBHOOK_PAYLOAD';

/** Known codes, or any other string (the `string & {}` keeps autocompletion on the known ones). */
export type ErrorCode = ApiErrorCode | SdkErrorCode | (string & {});

export type ErrorDetails = Record<string, unknown>;

/**
 * Base class of every error thrown by the SDK: catch it to handle any
 * Labyrinthe Connect failure in one place.
 *
 * Always switch on `code` (stable), never on `message` (API messages are in
 * French, for humans).
 */
export class LabyrintheError extends Error {
  /** Stable machine-readable code, e.g. "INSUFFICIENT_CREDITS" or "TIMEOUT". */
  readonly code: ErrorCode;
  /** HTTP status of the response; null when there was no response (network, timeout, local validation…). */
  readonly status: number | null;
  /** Error details, depending on the code (`{}` when there are none). */
  readonly details: ErrorDetails;

  constructor(message: string, code: ErrorCode, status: number | null = null, details: ErrorDetails = {}) {
    super(message);
    this.name = new.target.name;
    this.code = code;
    this.status = status;
    this.details = details;
  }
}

/**
 * The API answered with an error (`{ "error": { "code", "message", "details" } }`).
 * Subclasses exist for the codes an integration usually handles; any other
 * code (including codes added to the API later) is thrown as a plain ApiError.
 */
export class ApiError extends LabyrintheError {
  declare readonly status: number;
  /** Response headers (lower-case names). */
  readonly headers: Readonly<Record<string, string>>;

  constructor(
    message: string,
    code: ErrorCode,
    status: number,
    details: ErrorDetails = {},
    headers: Record<string, string> = {},
  ) {
    super(message, code, status, details);
    this.headers = headers;
  }

  /** Request identifier for support (`X-Request-Id`), when the API provides one. */
  get requestId(): string | null {
    return this.headers['x-request-id'] ?? null;
  }

  protected detailInt(key: string): number | null {
    return toInt(this.details[key]);
  }
}

/** 401: missing, invalid or revoked API key. */
export class AuthenticationError extends ApiError {}

/** 403: IP not allowed for this key (`FORBIDDEN`) or OTP disabled for the account (`OTP_DISABLED_FOR_ACCOUNT`). */
export class PermissionError extends ApiError {}

/** 404: unknown message or `otpId` (or one from another account). */
export class NotFoundError extends ApiError {}

/** 422 VALIDATION_ERROR: one or more fields are missing or invalid. */
export class ValidationError extends ApiError {
  /** Error messages per field, e.g. `{ body: ["Le champ body est obligatoire."] }`. */
  get fieldErrors(): Record<string, string[]> {
    const errors: Record<string, string[]> = {};
    for (const [field, messages] of Object.entries(this.details)) {
      const list = Array.isArray(messages) ? (messages as unknown[]) : [messages];
      errors[field] = list.filter((m) => ['string', 'number', 'boolean'].includes(typeof m)).map(String);
    }
    return errors;
  }
}

/**
 * 422 RECIPIENT_NOT_SENDABLE: at least one recipient is invalid, blacklisted
 * or opted out. Sending is all-or-nothing: NOTHING was sent and no credit
 * was used. Remove the `rejected` numbers and send again.
 */
export class RecipientNotSendableError extends ApiError {
  /** Reason of the first rejection. */
  get reason(): RejectionReason {
    return rejectionReason(this.details['reason']);
  }

  /** Every rejected recipient (`sms.send` / `sms.sendMany`). Empty for OTP sends. */
  get rejected(): RejectedRecipient[] {
    const rejected = this.details['rejected'];
    if (!Array.isArray(rejected)) {
      return [];
    }
    const items: RejectedRecipient[] = [];
    for (const item of rejected as unknown[]) {
      if (isRecord(item) && typeof item['recipient'] === 'string') {
        items.push({ recipient: item['recipient'], reason: rejectionReason(item['reason']) });
      }
    }
    return items;
  }
}

/** 422 SENDER_ID_NOT_APPROVED: `sender` is not an approved Sender ID of the account. */
export class SenderIdNotApprovedError extends ApiError {}

/** 422 INVALID_PHONE: the phone number cannot be used (OTP). */
export class InvalidPhoneError extends ApiError {}

/** 409 INSUFFICIENT_CREDITS: the balance does not cover the whole send. Nothing was sent. */
export class InsufficientCreditsError extends ApiError {
  /** Credits available on the account. */
  get available(): number | null {
    return this.detailInt('available');
  }

  /** Credits needed for this send. */
  get required(): number | null {
    return this.detailInt('required');
  }
}

/** 409 IDEMPOTENCY_KEY_REUSED: the same Idempotency-Key was used for a different request. */
export class IdempotencyKeyReusedError extends ApiError {}

/**
 * 429: too many requests. The SDK already retried TOO_MANY_REQUESTS when it
 * was safe to do so; when you get this error, wait `retryAfterMs`.
 */
export class RateLimitError extends ApiError {
  /**
   * Milliseconds to wait before retrying, from the Retry-After header or
   * `details.retry_after_seconds`; null when the API does not say.
   */
  get retryAfterMs(): number | null {
    const header = this.headers['retry-after']?.trim();
    if (header !== undefined && /^\d+$/.test(header)) {
      return Number(header) * 1000;
    }
    const seconds = this.detailInt('retry_after_seconds');
    return seconds === null ? null : seconds * 1000;
  }
}

/** 429 OTP_RESEND_TOO_SOON: a code was sent to this number too recently. Never retried; show a countdown of `retryAfterMs`. */
export class OtpResendTooSoonError extends RateLimitError {}

/** 429 OTP_RATE_LIMITED: hourly cap reached for this phone number or for the whole account. Never retried. */
export class OtpRateLimitedError extends RateLimitError {
  get scope(): OtpRateLimitScope {
    const scope = this.details['scope'];
    return scope === 'phone' || scope === 'account' ? scope : 'unknown';
  }

  /** The hourly cap that was reached. */
  get limit(): number | null {
    return this.detailInt('limit');
  }
}

/** 5xx, including 503 OTP_SERVICE_DISABLED. */
export class ServiceUnavailableError extends ApiError {}

/**
 * The API could not be reached (DNS, TLS, connection refused…).
 *
 * For `otp.send()` and `otp.verify()` the request may or may not have been
 * processed: the SDK never retries them after a network error.
 */
export class ConnectionError extends LabyrintheError {
  constructor(message: string, code: ErrorCode = 'CONNECTION_ERROR') {
    super(message, code);
  }
}

/** An attempt took longer than `timeoutMs`. */
export class TimeoutError extends ConnectionError {
  constructor(message: string) {
    super(message, 'TIMEOUT');
  }
}

/** The call was cancelled through its `signal`. Never retried. */
export class RequestAbortedError extends LabyrintheError {
  constructor(message = 'The request was aborted.') {
    super(message, 'REQUEST_ABORTED');
  }
}

/** Invalid input detected by the SDK, before any network call. */
export class InvalidArgumentError extends LabyrintheError {
  constructor(message: string) {
    super(message, 'INVALID_ARGUMENT');
  }
}

/** A successful response that the SDK cannot read (not JSON, missing field…). */
export class UnexpectedResponseError extends LabyrintheError {
  constructor(message: string, status: number | null = null) {
    super(message, 'UNEXPECTED_RESPONSE', status);
  }
}

/** A webhook failed verification: missing or wrong signature, or a timestamp outside the tolerance. Answer 400. */
export class SignatureVerificationError extends LabyrintheError {
  constructor(message: string) {
    super(message, 'SIGNATURE_VERIFICATION_FAILED');
  }
}

/** A correctly signed webhook body is not a valid event envelope. Answer 400. */
export class InvalidWebhookPayloadError extends LabyrintheError {
  constructor(message: string) {
    super(message, 'INVALID_WEBHOOK_PAYLOAD');
  }
}

type ApiErrorClass = new (
  message: string,
  code: ErrorCode,
  status: number,
  details: ErrorDetails,
  headers: Record<string, string>,
) => ApiError;

/** Mapping by error code (checked against the spec by the contract tests). */
export const ERROR_CLASSES_BY_CODE: Readonly<Record<string, ApiErrorClass>> = {
  UNAUTHENTICATED: AuthenticationError,
  FORBIDDEN: PermissionError,
  OTP_DISABLED_FOR_ACCOUNT: PermissionError,
  NOT_FOUND: NotFoundError,
  VALIDATION_ERROR: ValidationError,
  RECIPIENT_NOT_SENDABLE: RecipientNotSendableError,
  SENDER_ID_NOT_APPROVED: SenderIdNotApprovedError,
  INVALID_PHONE: InvalidPhoneError,
  INSUFFICIENT_CREDITS: InsufficientCreditsError,
  IDEMPOTENCY_KEY_REUSED: IdempotencyKeyReusedError,
  TOO_MANY_REQUESTS: RateLimitError,
  OTP_RESEND_TOO_SOON: OtpResendTooSoonError,
  OTP_RATE_LIMITED: OtpRateLimitedError,
  OTP_SERVICE_DISABLED: ServiceUnavailableError,
};

/**
 * Builds the typed error of an error response: by error code first, then by
 * HTTP status, then a generic ApiError (unknown codes are expected).
 *
 * @internal
 */
export function errorFromResponse(status: number, rawBody: string, headers: Record<string, string>): ApiError {
  let code: string | null = null;
  let message: string | null = null;
  let details: ErrorDetails = {};

  let decoded: unknown = null;
  try {
    decoded = JSON.parse(rawBody);
  } catch {
    decoded = null;
  }
  if (isRecord(decoded) && isRecord(decoded['error'])) {
    const error = decoded['error'];
    code = typeof error['code'] === 'string' ? error['code'] : null;
    message = typeof error['message'] === 'string' ? error['message'] : null;
    // The API sometimes sends `[]` instead of `{}`: both become an empty object.
    details = isRecord(error['details']) ? error['details'] : {};
  }

  code ??= status === 429 ? 'TOO_MANY_REQUESTS' : 'HTTP_ERROR';
  message ??= `Labyrinthe Connect API returned HTTP ${String(status)}.`;

  const ErrorClass = ERROR_CLASSES_BY_CODE[code] ?? classForStatus(status);
  return new ErrorClass(message, code, status, details, headers);
}

function classForStatus(status: number): ApiErrorClass {
  if (status === 401) return AuthenticationError;
  if (status === 403) return PermissionError;
  if (status === 404) return NotFoundError;
  if (status === 429) return RateLimitError;
  if (status >= 500) return ServiceUnavailableError;
  return ApiError;
}

function rejectionReason(value: unknown): RejectionReason {
  return value === 'invalid_phone' || value === 'blacklisted' || value === 'opted_out' ? value : 'unknown';
}

/** @internal */
export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** @internal */
export function toInt(value: unknown): number | null {
  if (typeof value === 'number' && Number.isInteger(value)) {
    return value;
  }
  if (typeof value === 'string' && /^-?\d+$/.test(value)) {
    return Number(value);
  }
  return null;
}

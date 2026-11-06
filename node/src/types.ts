/**
 * Public types of the SDK: camelCase versions of the API objects described in
 * openapi/openapi.yaml. Dates are `Date` objects; enums are unions of string
 * literals with a constant object of the same name.
 */

/**
 * Lifecycle of a message.
 *
 * `unknown` is never sent by the API: the SDK uses it when the API returns a
 * status this version does not know yet.
 */
export const MessageStatus = {
  /** Queued at Labyrinthe Connect. */
  Queued: 'queued',
  /** Handed over to the SMS provider. */
  Submitted: 'submitted',
  /** Accepted by the mobile operator. */
  Sent: 'sent',
  /** Delivered to the handset (final). */
  Delivered: 'delivered',
  /** Failed (final). */
  Failed: 'failed',
  /** Rejected by the provider or the operator (final). */
  Rejected: 'rejected',
  /** Expired before delivery (final). */
  Expired: 'expired',
  /** Cannot be delivered to this number (final). */
  Undeliverable: 'undeliverable',
  /** Status not known by this SDK version. */
  Unknown: 'unknown',
} as const;
export type MessageStatus = (typeof MessageStatus)[keyof typeof MessageStatus];

const PENDING_STATUSES: readonly string[] = ['queued', 'submitted', 'sent'];
const FAILED_STATUSES: readonly string[] = ['failed', 'rejected', 'expired', 'undeliverable'];

/** True when the status will not change any more (delivered or failed). */
export function isFinalStatus(status: MessageStatus): boolean {
  return status === 'delivered' || FAILED_STATUSES.includes(status);
}

/** True for `failed`, `rejected`, `expired` and `undeliverable`. */
export function isFailedStatus(status: MessageStatus): boolean {
  return FAILED_STATUSES.includes(status);
}

/** True for `queued`, `submitted` and `sent`. */
export function isPendingStatus(status: MessageStatus): boolean {
  return PENDING_STATUSES.includes(status);
}

/** Character encoding of an SMS: GSM-7 (160 characters) or UCS-2 (70 characters). */
export const Encoding = {
  Gsm7: 'gsm7',
  Ucs2: 'ucs2',
} as const;
export type Encoding = (typeof Encoding)[keyof typeof Encoding];

export const SenderIdStatus = {
  Pending: 'pending',
  PendingProvider: 'pending_provider',
  Approved: 'approved',
  Rejected: 'rejected',
  Suspended: 'suspended',
  /** Status not known by this SDK version. */
  Unknown: 'unknown',
} as const;
export type SenderIdStatus = (typeof SenderIdStatus)[keyof typeof SenderIdStatus];

/** Why a recipient was refused (`RecipientNotSendableError`). */
export const RejectionReason = {
  InvalidPhone: 'invalid_phone',
  Blacklisted: 'blacklisted',
  OptedOut: 'opted_out',
  /** Reason not known by this SDK version. */
  Unknown: 'unknown',
} as const;
export type RejectionReason = (typeof RejectionReason)[keyof typeof RejectionReason];

/** Why an OTP verification failed (`verified: false`). */
export const OtpFailureReason = {
  /** Wrong code: one attempt was consumed, the user may try again. */
  InvalidCode: 'invalid_code',
  /** The code expired: send a new one. */
  Expired: 'expired',
  /** The code was already verified (each code is single-use). */
  AlreadyUsed: 'already_used',
  /** Too many wrong attempts: the code is permanently blocked. */
  MaxAttemptsReached: 'max_attempts_reached',
  /** Reason not known by this SDK version. */
  Unknown: 'unknown',
} as const;
export type OtpFailureReason = (typeof OtpFailureReason)[keyof typeof OtpFailureReason];

/** Scope of an `OTP_RATE_LIMITED` error: the phone number or the whole account. */
export const OtpRateLimitScope = {
  Phone: 'phone',
  Account: 'account',
  /** Scope not known by this SDK version. */
  Unknown: 'unknown',
} as const;
export type OtpRateLimitScope = (typeof OtpRateLimitScope)[keyof typeof OtpRateLimitScope];

/** Alphabet of an OTP code (`otp.send()` → `codeType`). */
export const OtpCodeType = {
  /** Digits 0-9. */
  Numeric: 'numeric',
  /** Upper-case letters and digits without ambiguous characters; typing is case-insensitive. */
  Alphanumeric: 'alphanumeric',
  /** Alphabet not known by this SDK version. */
  Unknown: 'unknown',
} as const;
export type OtpCodeType = (typeof OtpCodeType)[keyof typeof OtpCodeType];

/** Final status of an OTP verification, in the `otp.verified` / `otp.failed` webhooks. */
export const OtpVerificationStatus = {
  /** The right code was typed (`otp.verified`). */
  Verified: 'verified',
  /** Too many wrong attempts (`otp.failed`, reason `max_attempts_reached`). */
  Failed: 'failed',
  /** The code expired (`otp.failed`, reason `expired`). */
  Expired: 'expired',
  /** Status not known by this SDK version. */
  Unknown: 'unknown',
} as const;
export type OtpVerificationStatus = (typeof OtpVerificationStatus)[keyof typeof OtpVerificationStatus];

/** Webhook event types emitted by the API. */
export const WebhookEventType = {
  MessageStatusChanged: 'message.status_changed',
  CampaignCompleted: 'campaign.completed',
  BalanceLow: 'balance.low',
  OtpVerified: 'otp.verified',
  OtpFailed: 'otp.failed',
  TestPing: 'test.ping',
} as const;
export type WebhookEventType = (typeof WebhookEventType)[keyof typeof WebhookEventType];

/** OTP message languages of the standard templates. */
export type OtpLocale = 'fr' | 'en';

/** An SMS, one per recipient. The text is deliberately not returned by the API (it may contain an OTP code). */
export interface Message {
  id: string;
  /** Your reference, given at send time. */
  reference: string | null;
  /** International format without "+", e.g. "243812345678". */
  recipient: string;
  /** Sender shown on the handset. */
  sender: string | null;
  encoding: Encoding | null;
  status: MessageStatus;
  segments: number;
  /** Credits charged (equal to `segments`; fictitious with a test key). */
  creditsCharged: number;
  /** Campaign of origin (messages sent from the client area). */
  campaignId: string | null;
  providerMessageId: string | null;
  errorCode: string | null;
  errorMessage: string | null;
  createdAt: Date | null;
  /** Handed over to the provider. */
  submittedAt: Date | null;
  /** Delivery receipt. */
  deliveredAt: Date | null;
}

/** Result of `sms.sendMany()`: one message per distinct recipient. */
export interface MessageBatch {
  messages: Message[];
  /** The Idempotency-Key used for this send (yours, or the one generated by the SDK). */
  idempotencyKey: string;
  /** True when the API replayed an earlier identical request: nothing was sent or billed again. */
  replayed: boolean;
  /** `X-Request-Id` of the response, for the support; null when absent. */
  requestId: string | null;
}

/** Cost of a send, computed by the API without sending anything. */
export interface Estimate {
  encoding: Encoding;
  characters: number;
  segmentsPerMessage: number;
  /** Valid recipients only (invalid numbers are ignored, not rejected). */
  recipientsCount: number;
  totalCredits: number;
  /** Balance after the send (negative when insufficient); null if the account never had credits. */
  balanceAfter: number | null;
  /** True when the current balance covers the send (`balanceAfter >= 0`). */
  isAffordable: boolean;
}

/** Credit balance, in SMS credits (1 credit = 1 segment). */
export interface Balance {
  /** Available credits. */
  balance: number;
  /** Credits held by running campaigns. */
  held: number;
}

/** An approved sender name, usable as `sender` when sending. */
export interface SenderId {
  id: string;
  /** The name shown on the handset, e.g. "MYSHOP" (11 characters max). */
  value: string;
  status: SenderIdStatus;
  purpose: string | null;
  rejectionReason: string | null;
  isDefault: boolean;
  createdAt: Date | null;
}

/** A refused recipient of an all-or-nothing send. */
export interface RejectedRecipient {
  /** Number as received, or normalised. */
  recipient: string;
  reason: RejectionReason;
}

/**
 * Result of `otp.verify()`. A wrong code is not an exception.
 *
 * `attemptsRemaining`: attempts still possible for this code — an integer
 * after `invalid_code`, `0` with `max_attempts_reached`, null when the code is
 * verified, already used or expired (and with an API older than 1.2.0).
 */
export type OtpVerifyResult =
  | { verified: true; reason: null; attemptsRemaining: null }
  | { verified: false; reason: OtpFailureReason; attemptsRemaining: number | null };

/** What the SDK read from a successful response (`onResponse` option). */
export interface ResponseInfo {
  /** HTTP status, e.g. 200, 201, 202. */
  status: number;
  /** `X-Request-Id`: yours when you passed `requestId`, otherwise generated by the API. Null when absent. */
  requestId: string | null;
  /** `X-Api-Version`, the major API version (e.g. "1"); null when absent. */
  apiVersion: string | null;
  /** `Idempotent-Replayed: true`: replay of an earlier request with the same Idempotency-Key. */
  replayed: boolean;
  /** `X-RateLimit-Limit` / `X-RateLimit-Remaining`; null when absent. */
  rateLimit: { limit: number | null; remaining: number | null };
  /** Every response header (lower-case names). */
  headers: Readonly<Record<string, string>>;
}

/** Per-call options, accepted as the last argument of every method. */
export interface RequestOptions {
  /** Cancels the call (including the waits between retries). */
  signal?: AbortSignal;
  /** Timeout of each attempt, in milliseconds (defaults to the client's `timeoutMs`). */
  timeoutMs?: number;
  /**
   * Your own request identifier, sent as `X-Request-Id` (on every retry of
   * this call) and echoed by the API in its logs and responses: 1 to 128
   * characters among `A-Z a-z 0-9 . _ : -`. Without it, the API generates one.
   */
  requestId?: string;
  /** Called with the headers of the successful response (request id, rate limit, replay…). Errors carry them too. */
  onResponse?: (response: ResponseInfo) => void;
}

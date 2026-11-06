import { InvalidArgumentError, InvalidWebhookPayloadError, SignatureVerificationError, UnexpectedResponseError, isRecord } from './errors.js';
import { Reader, messageStatus, type Json } from './internal/data.js';
import { getCrypto } from './internal/runtime.js';
import type { MessageStatus, OtpVerificationStatus } from './types.js';

/** Name of the signature header. */
export const SIGNATURE_HEADER = 'X-Signature';
/** Default maximum age of a delivery, in seconds. */
export const DEFAULT_TOLERANCE_SECONDS = 300;
const PREFIX = 'sha256=';

/** Fields shared by every event. */
interface EventEnvelope {
  /** Event identifier, identical across delivery attempts: deduplicate on it. */
  id: string;
  /** Event name exactly as sent by the API, e.g. "message.status_changed" (also for unknown events). */
  type: string;
  /** Time of this delivery attempt (signed). */
  timestamp: Date;
  /** Decoded envelope `{ id, event, data, timestamp }`, as sent by the API. */
  raw: Json;
}

export interface MessageStatusChangedEvent extends EventEnvelope {
  event: 'message.status_changed';
  data: {
    messageId: string;
    recipient: string;
    status: MessageStatus;
    providerMessageId: string | null;
    campaignId: string | null;
    /** The `reference` given at send time (`sms.send({ reference })`). */
    reference: string | null;
    /** Provider or platform error code when the message failed. */
    errorCode: string | null;
    errorMessage: string | null;
  };
}

export interface CampaignCompletedEvent extends EventEnvelope {
  event: 'campaign.completed';
  data: {
    campaignId: string;
    name: string;
    recipientsCount: number;
    /** Number of messages per final status, e.g. `{ delivered: 1150, failed: 30 }`. */
    messagesByStatus: Partial<Record<MessageStatus, number>> & Record<string, number>;
    creditsCharged: number;
    completedAt: Date | null;
  };
}

export interface BalanceLowEvent extends EventEnvelope {
  event: 'balance.low';
  data: {
    /** Balance after the operation that crossed the threshold. */
    balance: number;
    threshold: number;
  };
}

/** Outcome of an OTP verification (`otp.verified`, `otp.failed`). Never contains the code. */
export interface OtpWebhookData {
  otpId: string;
  /** Normalised number (international, no "+"). */
  phone: string;
  /** `verified` with `otp.verified`; `failed` or `expired` with `otp.failed`. */
  status: OtpVerificationStatus;
  /** Null when verified; `max_attempts_reached` (status `failed`) or `expired` (status `expired`). */
  reason: 'max_attempts_reached' | 'expired' | 'unknown' | null;
  /** Verification date; null for `otp.failed`. */
  verifiedAt: Date | null;
}

/** A code was verified (sent once, after `otp.verify()` got the right code — API or client area console). */
export interface OtpVerifiedEvent extends EventEnvelope {
  event: 'otp.verified';
  data: OtpWebhookData;
}

/** A verification failed for good: attempts exhausted or code expired (sent once). */
export interface OtpFailedEvent extends EventEnvelope {
  event: 'otp.failed';
  data: OtpWebhookData;
}

export interface TestPingEvent extends EventEnvelope {
  event: 'test.ping';
  data: Json;
}

/**
 * An event type added to the API after this SDK version. Its signature was
 * verified: `type` holds its name and `data` its content (as sent, snake_case).
 * Acknowledge it with a 2xx. (`event` is the constant "unknown" so that
 * `switch (event.event)` narrows the known events.)
 */
export interface UnknownEvent extends EventEnvelope {
  event: 'unknown';
  data: Json;
}

/** A verified webhook event: switch on `event` to get the typed `data`. */
export type WebhookEvent =
  | MessageStatusChangedEvent
  | CampaignCompletedEvent
  | BalanceLowEvent
  | OtpVerifiedEvent
  | OtpFailedEvent
  | TestPingEvent
  | UnknownEvent;

export interface ConstructEventOptions {
  /** Max age of the signed timestamp, in seconds; 0 disables the check (not recommended). Default 300. */
  toleranceSeconds?: number;
  /** Current time (tests). */
  now?: Date | number;
}

/** Raw request body: the exact bytes, or the text decoded as UTF-8. Never a re-encoded object. */
export type RawBody = string | Uint8Array | ArrayBuffer;

const encoder = new TextEncoder();
const reader = new Reader('Invalid webhook payload');

/**
 * Webhook verification, with Web Crypto (Node, edge runtimes, Deno, Bun).
 *
 *     const event = await Webhooks.constructEvent(rawBody, request.headers.get('x-signature'), secret);
 *
 * The signature (`X-Signature: sha256=<hex HMAC-SHA256 of the raw body>`) is
 * checked in constant time on the RAW body, before any JSON parsing; then the
 * signed `timestamp` must be within the tolerance (replay protection).
 */
export const Webhooks = Object.freeze({
  SIGNATURE_HEADER,
  DEFAULT_TOLERANCE_SECONDS,

  /**
   * Verifies a delivery and returns the typed event.
   *
   * @throws SignatureVerificationError wrong/missing signature or timestamp out of tolerance: answer 400
   * @throws InvalidWebhookPayloadError signed body that is not a valid envelope: answer 400
   */
  async constructEvent(
    rawBody: RawBody,
    signatureHeader: string | null | undefined,
    secret: string,
    options: ConstructEventOptions = {},
  ): Promise<WebhookEvent> {
    const bytes = toBytes(rawBody);
    if (!(await Webhooks.verifySignature(bytes, signatureHeader, secret))) {
      throw new SignatureVerificationError('Invalid webhook signature: the body was not signed with this secret.');
    }

    let envelope: unknown;
    try {
      envelope = JSON.parse(new TextDecoder().decode(bytes));
    } catch {
      throw new InvalidWebhookPayloadError('The webhook body is not valid JSON.');
    }
    if (!isRecord(envelope)) {
      throw new InvalidWebhookPayloadError('The webhook body is not a JSON object.');
    }

    const tolerance = options.toleranceSeconds ?? DEFAULT_TOLERANCE_SECONDS;
    if (!Number.isFinite(tolerance) || tolerance < 0) {
      throw new InvalidArgumentError('toleranceSeconds cannot be negative.');
    }
    try {
      const timestamp = reader.int(envelope, 'timestamp');
      const nowMs = options.now === undefined ? Date.now() : new Date(options.now).getTime();
      if (tolerance > 0 && Math.abs(nowMs / 1000 - timestamp) > tolerance) {
        throw new SignatureVerificationError(
          `Webhook timestamp is outside the ${String(tolerance)} s tolerance (possible replay).`,
        );
      }
      return buildEvent(envelope, timestamp);
    } catch (error) {
      if (error instanceof UnexpectedResponseError) {
        throw new InvalidWebhookPayloadError(error.message);
      }
      throw error;
    }
  },

  /** Constant-time check of the X-Signature header against the raw body. */
  async verifySignature(rawBody: RawBody, signatureHeader: string | null | undefined, secret: string): Promise<boolean> {
    const key = await importKey(secret);
    const signature = (signatureHeader ?? '').trim().toLowerCase();
    if (!signature.startsWith(PREFIX)) {
      return false;
    }
    const hex = signature.slice(PREFIX.length);
    if (!/^[0-9a-f]{64}$/.test(hex)) {
      return false;
    }
    const expected = new Uint8Array(32);
    for (let i = 0; i < 32; i++) {
      expected[i] = parseInt(hex.slice(i * 2, i * 2 + 2), 16);
    }
    // crypto.subtle.verify compare en temps constant.
    const crypto = await getCrypto();
    return crypto.subtle.verify('HMAC', key, expected, toBytes(rawBody));
  },

  /** The X-Signature header value for a body: "sha256=<hex>". Useful to test your webhook endpoint. */
  async sign(rawBody: RawBody, secret: string): Promise<string> {
    const key = await importKey(secret);
    const crypto = await getCrypto();
    const mac = new Uint8Array(await crypto.subtle.sign('HMAC', key, toBytes(rawBody)));
    return PREFIX + Array.from(mac, (b) => b.toString(16).padStart(2, '0')).join('');
  },
});

async function importKey(secret: string): Promise<CryptoKey> {
  if (typeof secret !== 'string' || secret === '') {
    throw new InvalidArgumentError('The webhook secret cannot be empty.');
  }
  const crypto = await getCrypto();
  return crypto.subtle.importKey('raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, [
    'sign',
    'verify',
  ]);
}

const OTP_STATUSES: readonly string[] = ['verified', 'failed', 'expired'];

function toOtpWebhookData(data: Json): OtpWebhookData {
  const status = reader.string(data, 'status');
  const reason = reader.nullableString(data, 'reason');
  return {
    otpId: reader.string(data, 'otp_id'),
    phone: reader.string(data, 'phone'),
    status: (OTP_STATUSES.includes(status) ? status : 'unknown') as OtpVerificationStatus,
    reason: reason === null ? null : reason === 'max_attempts_reached' || reason === 'expired' ? reason : 'unknown',
    verifiedAt: reader.nullableDate(data, 'verified_at'),
  };
}

function toBytes(rawBody: RawBody): Uint8Array<ArrayBuffer> {

  if (typeof rawBody === 'string') {
    return encoder.encode(rawBody);
  }
  if (rawBody instanceof ArrayBuffer) {
    return new Uint8Array(rawBody);
  }
  if (ArrayBuffer.isView(rawBody)) {
    // Copie : Web Crypto exige un ArrayBuffer (pas un SharedArrayBuffer) et le Buffer de Node peut être une vue d'un pool.
    return new Uint8Array(rawBody);
  }
  throw new InvalidArgumentError(
    'The webhook body must be the RAW request body (string, Uint8Array/Buffer or ArrayBuffer), not a parsed object.',
  );
}

function buildEvent(envelope: Json, timestamp: number): WebhookEvent {
  const id = reader.string(envelope, 'id');
  const event = reader.string(envelope, 'event');
  const data = reader.optionalObject(envelope, 'data');
  const base = { id, type: event, timestamp: new Date(timestamp * 1000), raw: envelope };

  switch (event) {
    case 'message.status_changed':
      return {
        ...base,
        event,
        data: {
          messageId: reader.string(data, 'message_id'),
          recipient: reader.string(data, 'recipient'),
          status: messageStatus(reader.string(data, 'status')),
          providerMessageId: reader.nullableString(data, 'provider_message_id'),
          campaignId: reader.nullableString(data, 'campaign_id'),
          // Spec 1.2.0 : absents d'un backend plus ancien → null.
          reference: reader.nullableString(data, 'reference'),
          errorCode: reader.nullableString(data, 'error_code'),
          errorMessage: reader.nullableString(data, 'error_message'),
        },
      };
    case 'campaign.completed': {
      const counts = reader.optionalObject(data, 'messages_by_status');
      const messagesByStatus: Record<string, number> = {};
      for (const status of Object.keys(counts)) {
        messagesByStatus[status] = reader.int(counts, status);
      }
      return {
        ...base,
        event,
        data: {
          campaignId: reader.string(data, 'campaign_id'),
          name: reader.nullableString(data, 'name') ?? '',
          recipientsCount: reader.nullableInt(data, 'recipients_count') ?? 0,
          messagesByStatus,
          creditsCharged: reader.nullableInt(data, 'credits_charged') ?? 0,
          completedAt: reader.nullableDate(data, 'completed_at'),
        },
      };
    }
    case 'balance.low':
      return {
        ...base,
        event,
        data: { balance: reader.int(data, 'balance'), threshold: reader.int(data, 'threshold') },
      };
    case 'otp.verified':
    case 'otp.failed':
      return { ...base, event, data: toOtpWebhookData(data) };
    case 'test.ping':
      return { ...base, event, data };
    default:
      return { ...base, event: 'unknown', data };
  }
}

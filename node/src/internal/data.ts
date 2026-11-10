/**
 * Lecture typée du JSON de l'API et conversion snake_case → camelCase.
 * Tolérant sur la représentation (entiers en chaîne acceptés), strict sur la
 * présence des champs obligatoires.
 *
 * @internal
 */
import { UnexpectedResponseError, isRecord, toInt } from '../errors.js';
import type {
  Balance,
  Encoding,
  Estimate,
  Message,
  MessageStatus,
  OtpFailureReason,
  OtpVerifyResult,
  SenderId,
  SenderIdStatus,
} from '../types.js';

export type Json = Record<string, unknown>;

/** Longueur en caractères (points de code), comme mb_strlen côté backend. */
export function characterCount(value: string): number {
  let count = 0;
  for (const _char of value) count++;
  return count;
}

const MESSAGE_STATUSES: readonly string[] = [
  'queued',
  'submitted',
  'sent',
  'delivered',
  'failed',
  'rejected',
  'expired',
  'undeliverable',
];
const SENDER_ID_STATUSES: readonly string[] = ['pending', 'pending_provider', 'approved', 'rejected', 'suspended'];
const OTP_FAILURE_REASONS: readonly string[] = ['invalid_code', 'expired', 'already_used', 'max_attempts_reached'];

/** Préfixe des messages d'erreur ; remplacé pour les webhooks. */
export class Reader {
  constructor(private readonly context = 'Unexpected API payload') {}

  invalid(key: string, expected: string): UnexpectedResponseError {
    return new UnexpectedResponseError(`${this.context}: field "${key}" should be ${expected}.`);
  }

  string(data: Json, key: string): string {
    const value = data[key];
    if (typeof value === 'string') return value;
    if (typeof value === 'number') return String(value);
    throw this.invalid(key, 'a string');
  }

  nullableString(data: Json, key: string): string | null {
    return data[key] === undefined || data[key] === null ? null : this.string(data, key);
  }

  int(data: Json, key: string): number {
    const value = toInt(data[key]);
    if (value === null) throw this.invalid(key, 'an integer');
    return value;
  }

  nullableInt(data: Json, key: string): number | null {
    return data[key] === undefined || data[key] === null ? null : this.int(data, key);
  }

  bool(data: Json, key: string): boolean {
    const value = data[key];
    if (typeof value === 'boolean') return value;
    if (value === 0 || value === 1) return value === 1;
    throw this.invalid(key, 'a boolean');
  }

  object(data: Json, key: string): Json {
    const value = data[key];
    if (isRecord(value)) return value;
    throw this.invalid(key, 'an object');
  }

  /** Objet facultatif : absent, nul ou `[]` (PHP) donnent `{}`. */
  optionalObject(data: Json, key: string): Json {
    const value = data[key];
    if (value === undefined || value === null || (Array.isArray(value) && value.length === 0)) return {};
    return this.object(data, key);
  }

  objects(data: Json, key: string): Json[] {
    const value = data[key];
    if (!Array.isArray(value)) throw this.invalid(key, 'an array');
    return (value as unknown[]).map((item) => {
      if (!isRecord(item)) throw this.invalid(`${key}[]`, 'an object');
      return item;
    });
  }

  date(data: Json, key: string): Date {
    const value = data[key];
    const date = typeof value === 'string' ? new Date(value) : null;
    if (date === null || Number.isNaN(date.getTime())) throw this.invalid(key, 'an ISO 8601 date-time');
    return date;
  }

  nullableDate(data: Json, key: string): Date | null {
    return data[key] === undefined || data[key] === null ? null : this.date(data, key);
  }
}

const api = new Reader();

export function messageStatus(value: string): MessageStatus {
  return (MESSAGE_STATUSES.includes(value) ? value : 'unknown') as MessageStatus;
}

function encoding(value: string | null): Encoding | null {
  return value === 'gsm7' || value === 'ucs2' ? value : null;
}

export function toMessage(data: Json): Message {
  return {
    id: api.string(data, 'id'),
    reference: api.nullableString(data, 'reference'),
    recipient: api.string(data, 'recipient'),
    sender: api.nullableString(data, 'sender'),
    // Jamais null depuis la spec 1.2.0 ; null toléré pour un backend plus ancien.
    encoding: encoding(api.nullableString(data, 'encoding')),
    status: messageStatus(api.string(data, 'status')),
    segments: api.nullableInt(data, 'segments') ?? 0,
    creditsCharged: api.nullableInt(data, 'credits_charged') ?? 0,
    campaignId: api.nullableString(data, 'campaign_id'),
    providerMessageId: api.nullableString(data, 'provider_message_id'),
    errorCode: api.nullableString(data, 'error_code'),
    errorMessage: api.nullableString(data, 'error_message'),
    createdAt: api.nullableDate(data, 'created_at'),
    submittedAt: api.nullableDate(data, 'submitted_at'),
    deliveredAt: api.nullableDate(data, 'delivered_at'),
  };
}

export function toEstimate(data: Json): Estimate {
  const balanceAfter = api.nullableInt(data, 'balance_after');
  return {
    encoding: encoding(api.string(data, 'encoding')) ?? 'ucs2',
    characters: api.int(data, 'characters'),
    segmentsPerMessage: api.int(data, 'segments_per_message'),
    recipientsCount: api.int(data, 'recipients_count'),
    totalCredits: api.int(data, 'total_credits'),
    balanceAfter,
    isAffordable: balanceAfter !== null && balanceAfter >= 0,
  };
}

export function toBalance(data: Json): Balance {
  return { balance: api.int(data, 'balance'), held: api.int(data, 'held') };
}

export function toSenderId(data: Json): SenderId {
  const status = api.string(data, 'status');
  return {
    id: api.string(data, 'id'),
    value: api.string(data, 'value'),
    status: (SENDER_ID_STATUSES.includes(status) ? status : 'unknown') as SenderIdStatus,
    purpose: api.nullableString(data, 'purpose'),
    rejectionReason: api.nullableString(data, 'rejection_reason'),
    isDefault: data['is_default'] !== undefined && data['is_default'] !== null && api.bool(data, 'is_default'),
    createdAt: api.nullableDate(data, 'created_at'),
  };
}

export function toOtpVerifyResult(data: Json): OtpVerifyResult {
  if (api.bool(data, 'verified')) {
    return { verified: true, reason: null, attemptsRemaining: null };
  }
  const reason = api.nullableString(data, 'reason') ?? 'unknown';
  // `attempts_remaining` depuis la spec 1.2.0 : absent (backend plus ancien) → null.
  const remaining = api.nullableInt(data, 'attempts_remaining');
  return {
    verified: false,
    reason: (OTP_FAILURE_REASONS.includes(reason) ? reason : 'unknown') as OtpFailureReason,
    attemptsRemaining: remaining === null ? null : Math.max(0, remaining),
  };
}


export { api as apiReader };

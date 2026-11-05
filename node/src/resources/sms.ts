import { InvalidArgumentError, UnexpectedResponseError } from '../errors.js';
import { apiReader, characterCount, toEstimate, toMessage, type Json } from '../internal/data.js';
import { getCrypto } from '../internal/runtime.js';
import type { Transport } from '../internal/transport.js';
import { Page, PagePromise } from '../pagination.js';
import { PhoneNumber } from '../phone.js';
import type { Estimate, Message, MessageBatch, MessageStatus, RequestOptions } from '../types.js';

export const MAX_BODY_LENGTH = 1600;
export const MAX_RECIPIENTS = 1000;
export const MAX_SENDER_LENGTH = 11;
export const MAX_REFERENCE_LENGTH = 64;
export const MAX_IDEMPOTENCY_KEY_LENGTH = 255;
export const MAX_PER_PAGE = 100;

interface SendCommon {
  /** Text, 1 to 1600 characters. Any non-ASCII character switches to UCS-2 (70 characters per SMS). */
  body: string;
  /** Approved Sender ID (see `senderIds.list()`). Defaults to the client's `defaultSender`, then the platform's. */
  sender?: string;
  /** Your own reference (order number…), max 64 characters, filterable with `sms.list({ reference })`. */
  reference?: string;
  /**
   * Max 255 characters. Generated (UUID v4) when absent, and reused by the
   * SDK's retries. Pass your own (e.g. "order-1042-ready") to also protect
   * against your own retries for 24 hours.
   */
  idempotencyKey?: string;
}

export interface SendSmsParams extends SendCommon {
  /** Phone number, international ("+243…", "243…", "00243…") or local ("0812…", "812…"). */
  to: string;
}

export interface SendManySmsParams extends SendCommon {
  /** 1 to 1000 phone numbers. All or nothing: one refused number means nothing is sent. */
  recipients: readonly string[];
}

export interface EstimateSmsParams {
  body: string;
  /** Invalid numbers are not counted (no error). */
  recipients: string | readonly string[];
}

export interface ListSmsParams {
  status?: MessageStatus;
  /** Any format, normalised before the search. */
  recipient?: string;
  /** Exact match. */
  reference?: string;
  /** Inclusive: a date ("2026-11-01") or a date-time. */
  from?: Date | string;
  /** Inclusive: a date covers the whole day. */
  to?: Date | string;
  /** Default 1. */
  page?: number;
  /** 1 to 100, default 15. */
  perPage?: number;
}

/** Send and track SMS (`client.sms`). */
export class Sms {
  readonly #transport: Transport;
  readonly #defaultCountryCode: string;
  readonly #defaultSender: string | undefined;

  /** @internal */
  constructor(transport: Transport, defaultCountryCode: string, defaultSender: string | undefined) {
    this.#transport = transport;
    this.#defaultCountryCode = defaultCountryCode;
    this.#defaultSender = defaultSender;
  }

  /**
   * Sends one SMS. Asynchronous: the returned message is `queued`; follow its
   * status with webhooks (`message.status_changed`) or `get()`.
   *
   * An Idempotency-Key is always sent, so network errors are retried safely:
   * the SMS is never sent twice.
   */
  async send(params: SendSmsParams, options: RequestOptions = {}): Promise<Message> {
    const recipient = PhoneNumber.normalizeOrThrow(params.to, this.#defaultCountryCode, 'to');
    const batch = await this.#dispatch({ recipient }, params, options);
    const message = batch.messages[0];
    if (message === undefined) {
      throw new UnexpectedResponseError('Unexpected API response: no message was returned.');
    }
    return message;
  }

  /**
   * Sends the same SMS to several recipients (up to 1000), all or nothing: if
   * a single recipient is refused, nothing is sent and a
   * `RecipientNotSendableError` lists every refused number. Duplicates are
   * merged by the API (one message per distinct number).
   */
  async sendMany(params: SendManySmsParams, options: RequestOptions = {}): Promise<MessageBatch> {
    const recipients = params.recipients as readonly unknown[];
    if (!Array.isArray(recipients) || recipients.length === 0) {
      throw new InvalidArgumentError('At least one recipient is required.');
    }
    if (recipients.length > MAX_RECIPIENTS) {
      throw new InvalidArgumentError(`At most ${String(MAX_RECIPIENTS)} recipients per request.`);
    }

    const normalized: string[] = [];
    const invalid: number[] = [];
    recipients.forEach((recipient, index) => {
      const number = typeof recipient === 'string' ? PhoneNumber.normalize(recipient, this.#defaultCountryCode) : null;
      if (number === null) {
        invalid.push(index);
      } else {
        normalized.push(number);
      }
    });
    if (invalid.length > 0) {
      const shown = invalid.slice(0, 20).join(', ') + (invalid.length > 20 ? '…' : '');
      throw new InvalidArgumentError(`Invalid phone number(s) at recipients index ${shown}. Nothing was sent.`);
    }

    return this.#dispatch({ recipients: normalized }, params, options);
  }

  /** Computes encoding, segments and credits without sending anything. */
  async estimate(params: EstimateSmsParams, options: RequestOptions = {}): Promise<Estimate> {
    assertBody(params.body);
    const list = typeof params.recipients === 'string' ? [params.recipients] : [...params.recipients];
    if (list.length === 0) {
      throw new InvalidArgumentError('At least one recipient is required.');
    }
    const recipients = list.map((r) => PhoneNumber.normalize(r, this.#defaultCountryCode) ?? r);

    const response = await this.#transport.request('POST', '/sms/estimate', 'safe', {
      body: { body: params.body, recipients },
      ...options,
    });
    return toEstimate(apiReader.object(response.body, 'data'));
  }

  /** One message of the account, by id. Throws `NotFoundError` when unknown. */
  async get(id: string, options: RequestOptions = {}): Promise<Message> {
    if (typeof id !== 'string' || id.trim() === '') {
      throw new InvalidArgumentError('The message id cannot be empty.');
    }
    const response = await this.#transport.request('GET', `/sms/${encodeURIComponent(id)}`, 'safe', options);
    return toMessage(apiReader.object(response.body, 'data'));
  }

  /**
   * Messages sent with this API key, newest first.
   *
   *     const page = await client.sms.list({ status: 'failed' });   // one page
   *     for await (const message of client.sms.list()) { … }         // every page
   */
  list(params: ListSmsParams = {}, options: RequestOptions = {}): PagePromise<Message> {
    const page = params.page ?? 1;
    const perPage = params.perPage ?? 15;
    if (!Number.isInteger(page) || page < 1) {
      throw new InvalidArgumentError('page must be an integer >= 1.');
    }
    if (!Number.isInteger(perPage) || perPage < 1 || perPage > MAX_PER_PAGE) {
      throw new InvalidArgumentError(`perPage must be an integer between 1 and ${String(MAX_PER_PAGE)}.`);
    }

    const query: Record<string, string | number | undefined> = {
      status: params.status,
      recipient:
        params.recipient === undefined
          ? undefined
          : (PhoneNumber.normalize(params.recipient, this.#defaultCountryCode) ?? params.recipient),
      reference: params.reference,
      from: formatDate(params.from),
      to: formatDate(params.to),
      per_page: perPage,
    };

    return new PagePromise(() => this.#fetchPage(query, page, options));
  }

  async #fetchPage(
    query: Record<string, string | number | undefined>,
    page: number,
    options: RequestOptions,
  ): Promise<Page<Message>> {
    const response = await this.#transport.request('GET', '/sms', 'safe', { query: { ...query, page }, ...options });
    const meta = apiReader.optionalObject(response.body, 'meta');
    const data = apiReader.objects(response.body, 'data').map(toMessage);
    return new Page(
      {
        data,
        currentPage: apiReader.nullableInt(meta, 'current_page') ?? page,
        lastPage: apiReader.nullableInt(meta, 'last_page') ?? page,
        perPage: apiReader.nullableInt(meta, 'per_page') ?? data.length,
        total: apiReader.nullableInt(meta, 'total') ?? data.length,
      },
      (next) => this.#fetchPage(query, next, options),
    );
  }

  async #dispatch(
    target: { recipient: string } | { recipients: string[] },
    params: SendCommon,
    options: RequestOptions,
  ): Promise<MessageBatch> {
    assertBody(params.body);
    const sender = params.sender ?? this.#defaultSender;
    if (sender !== undefined && (typeof sender !== 'string' || sender === '' || characterCount(sender) > MAX_SENDER_LENGTH)) {
      throw new InvalidArgumentError(`sender must be 1 to ${String(MAX_SENDER_LENGTH)} characters.`);
    }
    const reference = params.reference;
    if (reference !== undefined && (typeof reference !== 'string' || characterCount(reference) > MAX_REFERENCE_LENGTH)) {
      throw new InvalidArgumentError(`reference must be at most ${String(MAX_REFERENCE_LENGTH)} characters.`);
    }
    let idempotencyKey = params.idempotencyKey;
    if (
      idempotencyKey !== undefined &&
      (typeof idempotencyKey !== 'string' || idempotencyKey === '' || idempotencyKey.length > MAX_IDEMPOTENCY_KEY_LENGTH)
    ) {
      throw new InvalidArgumentError(`idempotencyKey must be 1 to ${String(MAX_IDEMPOTENCY_KEY_LENGTH)} characters.`);
    }

    // Générée une fois et réutilisée par chaque relance de cet appel.
    idempotencyKey ??= (await getCrypto()).randomUUID();

    const body: Json = { ...target, body: params.body };
    if (sender !== undefined) body['sender'] = sender;
    if (reference !== undefined && reference !== '') body['reference'] = reference;

    const response = await this.#transport.request('POST', '/sms', 'safe', {
      body,
      headers: { 'Idempotency-Key': idempotencyKey },
      ...options,
    });

    return {
      messages: apiReader.objects(response.body, 'data').map(toMessage),
      idempotencyKey,
      replayed: response.headers['idempotent-replayed'] === 'true',
    };
  }

  toJSON(): Record<string, unknown> {
    return { transport: this.#transport.toJSON(), defaultCountryCode: this.#defaultCountryCode };
  }

  [Symbol.for('nodejs.util.inspect.custom')](): Record<string, unknown> {
    return this.toJSON();
  }
}

function assertBody(body: unknown): asserts body is string {
  if (typeof body !== 'string' || body === '') {
    throw new InvalidArgumentError('The SMS body cannot be empty.');
  }
  if (characterCount(body) > MAX_BODY_LENGTH) {
    throw new InvalidArgumentError(`The SMS body must be at most ${String(MAX_BODY_LENGTH)} characters.`);
  }
}

function formatDate(date: Date | string | undefined): string | undefined {
  if (date instanceof Date) {
    if (Number.isNaN(date.getTime())) {
      throw new InvalidArgumentError('Invalid date in the list filters.');
    }
    return date.toISOString().replace(/\.\d{3}Z$/, 'Z');
  }
  return date;
}

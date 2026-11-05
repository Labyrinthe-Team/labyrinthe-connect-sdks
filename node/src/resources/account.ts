import { apiReader, toBalance, toSenderId } from '../internal/data.js';
import type { Transport } from '../internal/transport.js';
import type { Balance, RequestOptions, SenderId } from '../types.js';

/** Credit balance (`client.balance`). */
export class BalanceResource {
  readonly #transport: Transport;

  /** @internal */
  constructor(transport: Transport) {
    this.#transport = transport;
  }

  async get(options: RequestOptions = {}): Promise<Balance> {
    const response = await this.#transport.request('GET', '/balance', 'safe', options);
    return toBalance(apiReader.object(response.body, 'data'));
  }

  toJSON(): Record<string, unknown> {
    return { transport: this.#transport.toJSON() };
  }

  [Symbol.for('nodejs.util.inspect.custom')](): Record<string, unknown> {
    return this.toJSON();
  }
}

/** Approved Sender IDs (`client.senderIds`). */
export class SenderIds {
  readonly #transport: Transport;

  /** @internal */
  constructor(transport: Transport) {
    this.#transport = transport;
  }

  /** Sender IDs with the `approved` status, usable as `sender`. */
  async list(options: RequestOptions = {}): Promise<SenderId[]> {
    const response = await this.#transport.request('GET', '/sender-ids', 'safe', options);
    return apiReader.objects(response.body, 'data').map(toSenderId);
  }

  toJSON(): Record<string, unknown> {
    return { transport: this.#transport.toJSON() };
  }

  [Symbol.for('nodejs.util.inspect.custom')](): Record<string, unknown> {
    return this.toJSON();
  }
}

/**
 * Clé d'idempotence des envois (POST /sms, POST /otp/send).
 *
 * @internal
 */
import { InvalidArgumentError } from '../errors.js';
import { getCrypto } from './runtime.js';

export const MAX_IDEMPOTENCY_KEY_LENGTH = 255;

/**
 * La clé de l'appelant (vérifiée), sinon un UUID v4 : générée une fois par
 * appel et réutilisée par chacune de ses relances.
 */
export async function resolveIdempotencyKey(value: unknown): Promise<string> {
  if (value === undefined) {
    return (await getCrypto()).randomUUID();
  }
  if (typeof value !== 'string' || value === '' || value.length > MAX_IDEMPOTENCY_KEY_LENGTH) {
    throw new InvalidArgumentError(`idempotencyKey must be 1 to ${String(MAX_IDEMPOTENCY_KEY_LENGTH)} characters.`);
  }
  return value;
}

/** `strip_accents` facultatif : un booléen, ou rien. */
export function assertStripAccents(value: unknown): asserts value is boolean | undefined {
  if (value !== undefined && typeof value !== 'boolean') {
    throw new InvalidArgumentError('stripAccents must be a boolean.');
  }
}

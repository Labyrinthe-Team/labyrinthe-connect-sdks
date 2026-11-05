import { InvalidArgumentError } from './errors.js';

/** Default country code added to local numbers (DR Congo). */
export const DEFAULT_COUNTRY_CODE = '243';

/**
 * Phone number helpers, consistent with the API's own normalisation.
 *
 * The API expects international numbers without "+" (e.g. "243812345678").
 * Accepted input formats (with the default country code 243, DR Congo):
 *
 *  - "+243 81 234 5678", "00243812345678" → "243812345678" (explicit international prefix kept)
 *  - "243812345678"                       → "243812345678"
 *  - "0812345678", "812345678"            → "243812345678" (local format, default country code added)
 *  - "+33 6 12 34 56 78"                  → "33612345678"  (other countries are never blocked)
 *
 * Validation is deliberately light (8 to 15 digits, E.164): the API remains
 * the authority on whether a number can receive SMS.
 */
export const PhoneNumber = Object.freeze({
  /** Normalises a number, or returns null when it cannot be a valid phone number. */
  normalize(raw: string, defaultCountryCode: string = DEFAULT_COUNTRY_CODE): string | null {
    assertCountryCode(defaultCountryCode);

    const input = raw.trim();
    if (input === '') {
      return null;
    }

    const explicitInternational = input.startsWith('+') || input.startsWith('00');
    let digits = input.replace(/\D/g, '');

    if (explicitInternational) {
      digits = input.startsWith('00') ? digits.slice(2) : digits;
    } else if (!(digits.startsWith(defaultCountryCode) && digits.length > 10)) {
      // Local format: drop the trunk "0" and add the default country code.
      digits = defaultCountryCode + digits.replace(/^0+/, '');
    }

    return digits.length >= 8 && digits.length <= 15 ? digits : null;
  },

  /** Normalises a number or throws an InvalidArgumentError (the number itself is never part of the message). */
  normalizeOrThrow(raw: string, defaultCountryCode: string = DEFAULT_COUNTRY_CODE, field = 'phone'): string {
    const normalized = PhoneNumber.normalize(raw, defaultCountryCode);
    if (normalized === null) {
      throw new InvalidArgumentError(
        `Invalid phone number in "${field}": expected an international number (e.g. 243812345678) or a local number.`,
      );
    }
    return normalized;
  },

  isValid(raw: string, defaultCountryCode: string = DEFAULT_COUNTRY_CODE): boolean {
    return PhoneNumber.normalize(raw, defaultCountryCode) !== null;
  },

  /** Masks a number for logs: "243812345678" → "24381*****78". */
  mask(phone: string): string {
    const digits = phone.replace(/\D/g, '');
    const length = digits.length;
    if (length <= 4) {
      return '*'.repeat(length);
    }
    const visibleStart = Math.min(5, Math.max(1, length - 7));
    return digits.slice(0, visibleStart) + '*'.repeat(length - visibleStart - 2) + digits.slice(-2);
  },
});

/** @internal */
export function assertCountryCode(countryCode: string): void {
  if (!/^[1-9]\d{0,2}$/.test(countryCode)) {
    throw new InvalidArgumentError('The default country code must be 1 to 3 digits without "+" (e.g. "243").');
  }
}

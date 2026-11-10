import { InvalidArgumentError } from './errors.js';

/** Default country code added to local numbers (DR Congo). */
export const DEFAULT_COUNTRY_CODE = '243';

/**
 * Phone number helpers, consistent with the API's own normalisation.
 *
 * `normalize()` returns international digits without "+" (e.g. "243812345678"), the format of API
 * responses. Requests carry the same number in E.164 with "+" ("+243812345678"), so the API keeps the
 * country code of a short foreign number.
 * Accepted input formats (with the default country code 243, DR Congo):
 *
 *  - "+243 81 234 5678", "00243812345678" → "243812345678" (explicit international prefix kept)
 *  - "243812345678"                       → "243812345678"
 *  - "0812345678", "812345678"            → "243812345678" (local format, default country code added)
 *  - "+33 6 12 34 56 78"                  → "33612345678"  (other countries are never blocked)
 *  - "33612345678"                        → "33612345678"  (no "+", 11 digits or more not starting
 *                                                            with 0: already international)
 *
 * Idempotent, like the API: normalising an output again does not change it.
 * For a short foreign number (10 digits or less), always use the "+".
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
    } else if (digits.startsWith(defaultCountryCode) && digits.length > 10) {
      // Déjà préfixé de l'indicatif par défaut, sans « + » (243812345678).
    } else if (digits.length >= 11 && !digits.startsWith('0')) {
      // Déjà international, autre indicatif, sans « + » (33612345678) : aucun numéro
      // local RDC n'a cette longueur (9 chiffres, 10 avec le 0). Spec 1.2.0.
    } else {
      // Format local : on retire le « 0 » national et on ajoute l'indicatif par défaut.
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

/**
 * @internal Format envoyé à l'API : E.164 avec « + ». Sans « + », l'API lit un numéro
 * de 10 chiffres ou moins comme local RDC et lui ajoute 243 (« 2481234567 » → « 2432481234567 ») ; avec
 * « + », elle conserve l'indicatif. Les réponses renvoient toujours les chiffres sans « + ».
 */
export function toApiNumber(digits: string): string {
  return '+' + digits;
}

/** @internal */
export function assertCountryCode(countryCode: string): void {
  if (!/^[1-9]\d{0,2}$/.test(countryCode)) {
    throw new InvalidArgumentError('The default country code must be 1 to 3 digits without "+" (e.g. "243").');
  }
}

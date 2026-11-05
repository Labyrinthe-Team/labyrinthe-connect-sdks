/**
 * Normalisation des numéros, identique à celle du SDK Node (`PhoneNumber.normalize`
 * de `@labyrinthe/connect`) et de l'API. Copiée ici pour garder le widget sans
 * dépendance ; un test vérifie la parité avec le SDK.
 */

/** Default country code added to local numbers (DR Congo). */
export const DEFAULT_COUNTRY_CODE = '243';

/**
 * Normalises a phone number to the API format (international, digits only, no "+"),
 * or returns null when it cannot be a valid number.
 *
 *  - "+243 81 234 5678", "00243812345678", "243812345678" → "243812345678"
 *  - "0812345678", "812345678" → "243812345678" (default country code added)
 *  - "+33 6 12 34 56 78" → "33612345678" (other countries are never blocked)
 */
export function normalizePhone(raw: string, defaultCountryCode: string = DEFAULT_COUNTRY_CODE): string | null {
  if (!isCountryCode(defaultCountryCode)) {
    return null;
  }
  const input = raw.trim();
  if (input === '') {
    return null;
  }

  const explicitInternational = input.startsWith('+') || input.startsWith('00');
  let digits = input.replace(/\D/g, '');

  if (explicitInternational) {
    digits = input.startsWith('00') ? digits.slice(2) : digits;
  } else if (!(digits.startsWith(defaultCountryCode) && digits.length > 10)) {
    // Format local : on retire le « 0 » national et on ajoute l'indicatif par défaut.
    digits = defaultCountryCode + digits.replace(/^0+/, '');
  }

  return digits.length >= 8 && digits.length <= 15 ? digits : null;
}

/** A country code: 1 to 3 digits, without "+". */
export function isCountryCode(value: string): boolean {
  return /^[1-9]\d{0,2}$/.test(value);
}

/** Masks a number for display and logs: "243812345678" → "+243 81•••••78". */
export function maskPhone(phone: string): string {
  const digits = phone.replace(/\D/g, '');
  if (digits.length <= 6) {
    return '•'.repeat(digits.length);
  }
  return `+${digits.slice(0, 3)} ${digits.slice(3, 5)}${'•'.repeat(digits.length - 7)}${digits.slice(-2)}`;
}

/**
 * Masque les secrets et les données personnelles avant un log ou un dump.
 *
 * @internal
 */
import { PhoneNumber } from '../phone.js';

/** Paramètres de requête dont la valeur est un numéro de téléphone. */
const PHONE_PARAMS = ['recipient', 'phone'];

/** "lsk_test_abc…" → "lsk_test_****". Ne révèle jamais un caractère de la partie secrète. */
export function redactApiKey(apiKey: string): string {
  const match = /^(lsk_(?:live|test)_)/.exec(apiKey);
  return match?.[1] !== undefined ? `${match[1]}****` : '****';
}

/** Chemin + requête, numéros de téléphone masqués. */
export function redactPath(path: string, query: URLSearchParams): string {
  if ([...query.keys()].length === 0) {
    return path;
  }
  const parts: string[] = [];
  for (const [name, value] of query) {
    const shown = PHONE_PARAMS.includes(name) ? PhoneNumber.mask(value) : encodeURIComponent(value);
    parts.push(`${encodeURIComponent(name)}=${shown}`);
  }
  return `${path}?${parts.join('&')}`;
}

/** Retire la requête d'une URL citée dans un message d'erreur (elle peut contenir un numéro). */
export function stripQueryStrings(text: string): string {
  return text.replace(/\?[^\s"']*/g, '?…');
}

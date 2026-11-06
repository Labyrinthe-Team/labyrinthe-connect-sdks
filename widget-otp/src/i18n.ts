import type { OtpWidgetErrorCode } from './protocol.js';

/**
 * Texts of the widget. Placeholders: `{phone}`, `{time}`, `{count}`, `{index}`,
 * `{total}`, `{code}`. Override any of them with the `texts` property (or React prop).
 */
export interface OtpWidgetTexts {
  title: string;
  phoneLabel: string;
  countryCodeLabel: string;
  phonePlaceholder: string;
  phoneHint: string;
  send: string;
  sending: string;
  codeLabel: string;
  codeSentTo: string;
  /** Accessible name of each code box. */
  cellLabel: string;
  verify: string;
  verifying: string;
  changePhone: string;
  resend: string;
  resendIn: string;
  resendAvailable: string;
  attemptsLeftOne: string;
  attemptsLeftOther: string;
  success: string;
  testMode: string;
  errors: Record<OtpWidgetErrorCode, string>;
}

export type OtpWidgetLocale = 'fr' | 'en';

/** Partial override: top-level keys and `errors` entries are merged with the locale defaults. */
export type OtpWidgetTextsOverride = Partial<Omit<OtpWidgetTexts, 'errors'>> & {
  errors?: Partial<OtpWidgetTexts['errors']>;
};

const fr: OtpWidgetTexts = {
  title: 'Vérification du numéro',
  phoneLabel: 'Numéro de téléphone',
  countryCodeLabel: 'Indicatif pays',
  phonePlaceholder: '81 234 5678',
  phoneHint: 'Vous allez recevoir un code par SMS.',
  send: 'Recevoir le code',
  sending: 'Envoi…',
  codeLabel: 'Code reçu par SMS',
  codeSentTo: 'Code envoyé au {phone}.',
  cellLabel: 'Caractère {index} sur {total}',
  verify: 'Vérifier',
  verifying: 'Vérification…',
  changePhone: 'Modifier le numéro',
  resend: 'Renvoyer le code',
  resendIn: 'Renvoyer le code dans {time}',
  resendAvailable: 'Vous pouvez demander un nouveau code.',
  attemptsLeftOne: 'Il vous reste 1 tentative.',
  attemptsLeftOther: 'Il vous reste {count} tentatives.',
  success: 'Numéro vérifié.',
  testMode: 'Mode test : le code est {code}',
  errors: {
    invalid_phone: 'Ce numéro de téléphone n’est pas valide.',
    invalid_code: 'Code incorrect.',
    expired: 'Ce code a expiré. Demandez-en un nouveau.',
    already_used: 'Ce code a déjà été utilisé. Demandez-en un nouveau.',
    max_attempts_reached: 'Trop d’essais incorrects. Demandez un nouveau code.',
    resend_too_soon: 'Patientez {time} avant de demander un nouveau code.',
    rate_limited: 'Trop de demandes. Réessayez dans quelques minutes.',
    no_pending_verification: 'Votre demande a expiré. Demandez un nouveau code.',
    forbidden: 'Demande refusée.',
    invalid_request: 'Demande invalide.',
    service_unavailable: 'Le service de vérification est momentanément indisponible.',
    account_suspended: 'La vérification par SMS n’est pas disponible sur ce site pour le moment.',
    send_failed: 'L’envoi du code n’a pas pu être confirmé. Si vous ne le recevez pas d’ici une minute, demandez un nouveau code.',
    server_error: 'Une erreur est survenue. Réessayez.',
    network_error: 'Connexion impossible. Vérifiez votre réseau et réessayez.',
    incomplete_code: 'Saisissez les {count} caractères du code.',
  },
};

const en: OtpWidgetTexts = {
  title: 'Phone verification',
  phoneLabel: 'Phone number',
  countryCodeLabel: 'Country code',
  phonePlaceholder: '81 234 5678',
  phoneHint: 'We will send you a code by SMS.',
  send: 'Send code',
  sending: 'Sending…',
  codeLabel: 'Code received by SMS',
  codeSentTo: 'Code sent to {phone}.',
  cellLabel: 'Character {index} of {total}',
  verify: 'Verify',
  verifying: 'Verifying…',
  changePhone: 'Change number',
  resend: 'Resend code',
  resendIn: 'Resend code in {time}',
  resendAvailable: 'You can request a new code.',
  attemptsLeftOne: '1 attempt left.',
  attemptsLeftOther: '{count} attempts left.',
  success: 'Phone number verified.',
  testMode: 'Test mode: the code is {code}',
  errors: {
    invalid_phone: 'This phone number is not valid.',
    invalid_code: 'Wrong code.',
    expired: 'This code has expired. Request a new one.',
    already_used: 'This code was already used. Request a new one.',
    max_attempts_reached: 'Too many wrong attempts. Request a new code.',
    resend_too_soon: 'Please wait {time} before requesting a new code.',
    rate_limited: 'Too many requests. Try again in a few minutes.',
    no_pending_verification: 'Your request has expired. Request a new code.',
    forbidden: 'Request refused.',
    invalid_request: 'Invalid request.',
    service_unavailable: 'The verification service is temporarily unavailable.',
    account_suspended: 'SMS verification is not available on this site at the moment.',
    send_failed: 'We could not confirm that the code was sent. If it does not arrive within a minute, ask for a new code.',
    server_error: 'Something went wrong. Please try again.',
    network_error: 'Cannot connect. Check your network and try again.',
    incomplete_code: 'Enter the {count} characters of the code.',
  },
};

export const LOCALES: Readonly<Record<OtpWidgetLocale, OtpWidgetTexts>> = { fr, en };

/** Default texts of a locale merged with an override. */
export function resolveTexts(locale: string | null | undefined, override?: OtpWidgetTextsOverride | null): OtpWidgetTexts {
  const base = locale?.toLowerCase().startsWith('en') === true ? en : fr;
  if (override === undefined || override === null) {
    return base;
  }
  const { errors, ...rest } = override;
  return { ...base, ...stripUndefined(rest), errors: { ...base.errors, ...stripUndefined(errors ?? {}) } };
}

/** "Il reste {count}" + { count: 2 } → "Il reste 2". Unknown placeholders are kept. */
export function format(template: string, values: Record<string, string | number>): string {
  return template.replace(/\{(\w+)\}/g, (match, key: string) => {
    const value = values[key];
    return value === undefined ? match : String(value);
  });
}

/** 75 → "1:15". */
export function formatDuration(seconds: number): string {
  const s = Math.max(0, Math.ceil(seconds));
  return `${String(Math.floor(s / 60))}:${String(s % 60).padStart(2, '0')}`;
}

function stripUndefined<T extends object>(value: T): Partial<T> {
  return Object.fromEntries(Object.entries(value).filter(([, v]) => typeof v === 'string')) as Partial<T>;
}

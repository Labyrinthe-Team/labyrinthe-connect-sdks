/**
 * Protocole JSON entre le widget (navigateur) et le serveur de l'intégrateur
 * (`@labyrinthe/otp-widget/server`). Le navigateur ne voit jamais la clé API,
 * ni `otp_id`, ni le code envoyé : seulement ce qui suit.
 *
 *     POST {sendUrl}    { "phone": "+243812345678", "locale": "fr", "send_id": "…" }
 *       200 → SendSuccessBody            erreur → ErrorBody
 *     POST {verifyUrl}  { "code": "482913" }
 *       200 → VerifySuccessBody | VerifyFailureBody     erreur → ErrorBody
 */

/** Why a code was refused (same values as the Labyrinthe API `reason`). */
export type OtpFailureReason = 'invalid_code' | 'expired' | 'already_used' | 'max_attempts_reached';

/** Error codes sent by the server helper (stable, lower case). */
export type ServerErrorCode =
  /** The phone number cannot be used (invalid, blacklisted or opted out). */
  | 'invalid_phone'
  /** A code was sent to this number too recently: wait `retry_after_seconds`. */
  | 'resend_too_soon'
  /** Too many requests (per number, per account or per API key). */
  | 'rate_limited'
  /** No code is pending for this browser (cookie missing, expired or tampered with): send a new one. */
  | 'no_pending_verification'
  /** Refused by the integrator (`beforeSend`) or by the origin / content-type checks. */
  | 'forbidden'
  /** Malformed request (missing field, wrong content type…). */
  | 'invalid_request'
  /** OTP service unavailable (Labyrinthe unreachable, misconfiguration, no credits…). */
  | 'service_unavailable'
  /** The integrator's Labyrinthe account is suspended (ACCOUNT_SUSPENDED): nothing the user can do. */
  | 'account_suspended'
  /**
   * The send failed unexpectedly on the Labyrinthe side and its outcome is unknown (IDEMPOTENCY_KEY_UNRESOLVED):
   * a code may have been sent. Never retried automatically; the user may ask for a new code (sent with a new key).
   */
  | 'send_failed'
  /** Unexpected server error. */
  | 'server_error';

/** Every error code the widget can report: server codes, refusal reasons and client-side errors. */
export type OtpWidgetErrorCode = ServerErrorCode | OtpFailureReason | 'network_error' | 'incomplete_code';

export type CodeType = 'numeric' | 'alphanumeric';

export interface SendSuccessBody {
  sent: true;
  /** Masked number, for display ("+243 81•••••78"). */
  phone_masked: string;
  /** ISO 8601. */
  expires_at: string;
  max_attempts: number;
  /** Seconds before "Resend" is enabled (account's anti-resend delay, returned by the API). */
  resend_after_seconds: number;
  /** Code length / alphabet (account's OTP settings, returned by the API 1.2.0, or the server's override). */
  code_length?: number;
  code_type?: CodeType;
  /** ONLY when the integrator enabled `exposeTestCode` in development, with a `lsk_test_` key. */
  test_code?: string;
}

export interface VerifySuccessBody {
  verified: true;
  /** Whatever the integrator's `onVerified` returned (e.g. `{ redirect: "/dashboard" }`). */
  data?: unknown;
}

export interface VerifyFailureBody {
  verified: false;
  reason: OtpFailureReason;
  /** Attempts left for this code (`attempts_remaining` of the API; estimated with an older SDK); null when unknown. */
  attempts_left: number | null;

}

export interface ErrorBody {
  error: {
    code: ServerErrorCode;
    message: string;
    retry_after_seconds?: number;
  };
}

export const FAILURE_REASONS: readonly OtpFailureReason[] = ['invalid_code', 'expired', 'already_used', 'max_attempts_reached'];

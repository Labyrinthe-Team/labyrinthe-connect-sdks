import { InvalidArgumentError } from '../errors.js';
import { apiReader, characterCount, toOtpVerifyResult } from '../internal/data.js';
import type { Transport } from '../internal/transport.js';
import { PhoneNumber } from '../phone.js';
import type { OtpLocale, OtpVerifyResult, RequestOptions } from '../types.js';

export const MAX_TEMPLATE_LENGTH = 1600;
export const MAX_CODE_LENGTH = 20;

export interface SendOtpParams {
  /** Phone number, international or local format. */
  phone: string;
  /** Must contain "{code}"; optional "{app_name}", "{minutes}". Defaults to the account's OTP settings. */
  template?: string;
  /** Language of the standard template. Defaults to the account's OTP settings. */
  locale?: OtpLocale;
}

export interface VerifyOtpParams {
  /** The `otpId` returned by `otp.send()`, kept server-side. */
  otpId: string;
  /** The code typed by the user (spaces and case are ignored by the API). */
  code: string;
}

const INSPECT = Symbol.for('nodejs.util.inspect.custom');
const DENO_INSPECT = Symbol.for('Deno.customInspect');

/**
 * Result of an OTP send. Store `otpId` server-side (session, cache…) and pass
 * it to `otp.verify()` with the code typed by the user.
 */
export class OtpSendResult {
  readonly otpId: string;
  readonly expiresAt: Date;
  readonly maxAttempts: number;
  readonly #testCode: string | null;

  /** @internal */
  constructor(otpId: string, expiresAt: Date, maxAttempts: number, testCode: string | null) {
    this.otpId = otpId;
    this.expiresAt = expiresAt;
    this.maxAttempts = maxAttempts;
    this.#testCode = testCode;
  }

  /**
   * The code, ONLY with a test key (`lsk_test_…`): nothing is sent by SMS in
   * test mode, so the API returns the code to let you test `verify()`. Always
   * null with a live key. Never log it, never show it to end users. Hidden
   * from `JSON.stringify()` and `console.log()`.
   */
  get testCode(): string | null {
    return this.#testCode;
  }

  toJSON(): { otpId: string; expiresAt: string; maxAttempts: number } {
    return { otpId: this.otpId, expiresAt: this.expiresAt.toISOString(), maxAttempts: this.maxAttempts };
  }

  [INSPECT](): Record<string, unknown> {
    return this.#redacted();
  }

  [DENO_INSPECT](): string {
    return `OtpSendResult ${JSON.stringify(this.#redacted())}`;
  }

  #redacted(): Record<string, unknown> {
    return {
      otpId: this.otpId,
      expiresAt: this.expiresAt,
      maxAttempts: this.maxAttempts,
      testCode: this.#testCode === null ? null : '[redacted]',
    };
  }
}

/**
 * One-time passwords by SMS (`client.otp`).
 *
 * 1. `send({ phone })` and keep `otpId` server-side (session);
 * 2. the user types the code received by SMS;
 * 3. `verify({ otpId, code })`: log the user in when `verified` is true.
 *
 * Code length, validity, attempts, sender and template come from the
 * account's OTP settings (client area > OTP verification).
 */
export class Otp {
  readonly #transport: Transport;
  readonly #defaultCountryCode: string;

  /** @internal */
  constructor(transport: Transport, defaultCountryCode: string) {
    this.#transport = transport;
    this.#defaultCountryCode = defaultCountryCode;
  }

  /**
   * Generates a code and sends it by SMS (billed like any SMS). A new send to
   * the same number invalidates the previous code.
   *
   * Never retried automatically, except on 429 TOO_MANY_REQUESTS (refused
   * before processing): a second success would send a second, billed SMS.
   */
  async send(params: SendOtpParams, options: RequestOptions = {}): Promise<OtpSendResult> {
    const body: Record<string, unknown> = {
      phone: PhoneNumber.normalizeOrThrow(params.phone, this.#defaultCountryCode, 'phone'),
    };
    // Valeurs non typées : l'appelant peut venir de JavaScript.
    const template: unknown = params.template;
    const locale: unknown = params.locale;
    if (template !== undefined) {
      if (typeof template !== 'string' || !template.includes('{code}')) {
        throw new InvalidArgumentError('The OTP template must contain the {code} token.');
      }
      if (characterCount(template) > MAX_TEMPLATE_LENGTH) {
        throw new InvalidArgumentError(`The OTP template must be at most ${String(MAX_TEMPLATE_LENGTH)} characters.`);
      }
      body['template'] = template;
    }
    if (locale !== undefined) {
      if (locale !== 'fr' && locale !== 'en') {
        throw new InvalidArgumentError('locale must be "fr" or "en".');
      }
      body['locale'] = locale;
    }

    const response = await this.#transport.request('POST', '/otp/send', 'throttled-only', { body, ...options });
    const data = apiReader.object(response.body, 'data');
    return new OtpSendResult(
      apiReader.string(data, 'otp_id'),
      apiReader.date(data, 'expires_at'),
      apiReader.int(data, 'max_attempts'),
      apiReader.nullableString(data, 'test_code'),
    );
  }

  /**
   * Checks a code. A wrong code is NOT an error: the result says
   * `verified: false` with a `reason`, and one attempt is consumed. Free.
   * Throws `NotFoundError` for an unknown `otpId` (or one from another account).
   */
  async verify(params: VerifyOtpParams, options: RequestOptions = {}): Promise<OtpVerifyResult> {
    const { otpId, code } = params;
    if (typeof otpId !== 'string' || otpId.trim() === '') {
      throw new InvalidArgumentError('otpId cannot be empty.');
    }
    if (typeof code !== 'string' || code.trim() === '' || code.length > MAX_CODE_LENGTH) {
      throw new InvalidArgumentError(`The code must be 1 to ${String(MAX_CODE_LENGTH)} characters.`);
    }

    const response = await this.#transport.request('POST', '/otp/verify', 'throttled-only', {
      body: { otp_id: otpId, code },
      ...options,
    });
    return toOtpVerifyResult(apiReader.object(response.body, 'data'));
  }

  toJSON(): Record<string, unknown> {
    return { transport: this.#transport.toJSON(), defaultCountryCode: this.#defaultCountryCode };
  }

  [INSPECT](): Record<string, unknown> {
    return this.toJSON();
  }
}

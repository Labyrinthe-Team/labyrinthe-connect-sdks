import { format, formatDuration, resolveTexts, type OtpWidgetTexts, type OtpWidgetTextsOverride } from './i18n.js';
import { DEFAULT_COUNTRY_CODE, isCountryCode, normalizePhone } from './phone.js';
import {
  FAILURE_REASONS,
  type CodeType,
  type OtpFailureReason,
  type OtpWidgetErrorCode,
  type SendSuccessBody,
  type ServerErrorCode,
} from './protocol.js';
import { STYLES } from './styles.js';

export const TAG_NAME = 'labyrinthe-otp';
export const EVENT_SENT = 'labyrinthe-otp:sent';
export const EVENT_VERIFIED = 'labyrinthe-otp:verified';
export const EVENT_ERROR = 'labyrinthe-otp:error';

export type OtpWidgetStep = 'phone' | 'code' | 'success';

/** `labyrinthe-otp:sent` — a code was sent (first send or resend). */
export interface OtpSentDetail {
  /** Normalised number (international, no "+"). */
  phone: string;
  phoneMasked: string;
  expiresAt: Date;
  maxAttempts: number;
  resendAfterSeconds: number;
  /** Only when the server exposes it (test key, development). */
  testCode?: string;
}

/** `labyrinthe-otp:verified` — the code was accepted by the server. */
export interface OtpVerifiedDetail {
  phone: string;
  /** What the integrator's `onVerified` returned. */
  data: unknown;
}

/** `labyrinthe-otp:error` — anything that went wrong; `message` is the localised text shown to the user. */
export interface OtpErrorDetail {
  code: OtpWidgetErrorCode;
  message: string;
  /** HTTP status of the server response; null for local and network errors. */
  status: number | null;
  retryAfterSeconds?: number;
  attemptsLeft?: number | null;
}

/** Public API of the `<labyrinthe-otp>` element. */
export interface LabyrintheOtpElement extends HTMLElement {
  /** Current step. */
  readonly step: OtpWidgetStep;
  /** Text overrides (merged with the locale defaults). */
  texts: OtpWidgetTextsOverride | null;
  /** Extra headers sent to your endpoints (e.g. a CSRF token). */
  requestHeaders: Record<string, string>;
  /** Back to the phone step, clearing the code. */
  reset(): void;
}

const SERVER_CODES: readonly ServerErrorCode[] = [
  'invalid_phone',
  'resend_too_soon',
  'rate_limited',
  'no_pending_verification',
  'forbidden',
  'invalid_request',
  'service_unavailable',
  'server_error',
];

/** Codes after which the current code cannot be used any more: a new one must be sent. */
const NEEDS_NEW_CODE: readonly OtpWidgetErrorCode[] = ['expired', 'already_used', 'max_attempts_reached', 'no_pending_verification'];

/** Ajouté à un message identique au précédent pour que les lecteurs d'écran l'annoncent à nouveau. */
const ZERO_WIDTH_SPACE = String.fromCharCode(0x200b);

const CHECK_ICON =
  '<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="11" fill="none" stroke="currentColor" stroke-width="2"/><path d="M7 12.5l3.2 3.2L17 9" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

const TEMPLATE = `<style>${STYLES}</style>
<div class="root" part="root">
<h2 id="t" part="title"></h2>
<form id="pf" part="phone-form" novalidate>
<label for="pn" id="pl"></label>
<div class="phone"><span class="cc" part="country-code"><span aria-hidden="true">+</span><input id="cc" inputmode="numeric" autocomplete="tel-country-code" maxlength="3" spellcheck="false"></span>
<input id="pn" type="tel" inputmode="tel" autocomplete="tel-national" aria-describedby="ph er" part="phone-input"></div>
<p class="hint" id="ph"></p>
<button class="primary" type="submit" id="sb" part="button send-button"></button>
</form>
<form id="cf" part="code-form" novalidate hidden>
<div class="row"><p class="muted" id="st"></p><button type="button" class="link" id="chg" part="link change-button"></button></div>
<span class="label" id="cl"></span>
<div class="cells" role="group" id="cells" aria-labelledby="cl" aria-describedby="at er" part="cells"></div>
<p class="muted" id="at"></p>
<p class="test" id="tc" hidden part="test-code"></p>
<button class="primary" type="submit" id="vb" part="button verify-button"></button>
<button type="button" class="link" id="rs" part="link resend-button"></button>
</form>
<div class="success" id="ok" hidden tabindex="-1" part="success">${CHECK_ICON}<span id="okt"></span></div>
<p class="error" id="er" role="alert" part="error"></p>
<p class="sr" id="lv" aria-live="polite"></p>
</div>`;

interface Elements {
  title: HTMLElement;
  phoneForm: HTMLFormElement;
  phoneLabel: HTMLElement;
  countryCode: HTMLInputElement;
  phone: HTMLInputElement;
  phoneHint: HTMLElement;
  sendButton: HTMLButtonElement;
  codeForm: HTMLFormElement;
  sentTo: HTMLElement;
  changeButton: HTMLButtonElement;
  codeLabel: HTMLElement;
  cells: HTMLElement;
  attempts: HTMLElement;
  testCode: HTMLElement;
  verifyButton: HTMLButtonElement;
  resendButton: HTMLButtonElement;
  success: HTMLElement;
  successText: HTMLElement;
  error: HTMLElement;
  live: HTMLElement;
}

interface HttpResult {
  status: number;
  body: unknown;
}

/**
 * Creates the element class. Called by `defineLabyrintheOtp()` only where
 * `HTMLElement` exists, so importing the package on a server (SSR) is safe.
 */
export function createElementClass(): CustomElementConstructor {
  class LabyrintheOtp extends HTMLElement implements LabyrintheOtpElement {
    static readonly observedAttributes = ['locale', 'country-code', 'phone', 'code-length', 'code-type', 'heading'];

    readonly #root: ShadowRoot;
    readonly #el: Elements;
    #textsOverride: OtpWidgetTextsOverride | null = null;
    #texts: OtpWidgetTexts = resolveTexts('fr');
    #requestHeaders: Record<string, string> = {};
    #step: OtpWidgetStep = 'phone';
    #phoneNumber = '';
    #phoneMasked = '';
    #busy = false;
    #codeLength = 6;
    #codeType: CodeType = 'numeric';
    #attemptsLeft: number | null = null;
    #needsNewCode = false;
    #resendAt = 0;
    #timer: ReturnType<typeof setInterval> | undefined;
    #webOtp: AbortController | undefined;
    #lastAutoSubmitted = '';
    #errorCode: OtpWidgetErrorCode | null = null;
    #errorValues: Record<string, string | number> = {};

    constructor() {
      super();
      this.#root = this.attachShadow({ mode: 'open', delegatesFocus: true });
      this.#root.innerHTML = TEMPLATE;
      // eslint-disable-next-line @typescript-eslint/no-unnecessary-type-parameters -- raccourci typé pour les éléments du gabarit
      const $ = <T extends HTMLElement>(id: string) => this.#root.getElementById(id) as T;
      this.#el = {
        title: $('t'),
        phoneForm: $('pf'),
        phoneLabel: $('pl'),
        countryCode: $('cc'),
        phone: $('pn'),
        phoneHint: $('ph'),
        sendButton: $('sb'),
        codeForm: $('cf'),
        sentTo: $('st'),
        changeButton: $('chg'),
        codeLabel: $('cl'),
        cells: $('cells'),
        attempts: $('at'),
        testCode: $('tc'),
        verifyButton: $('vb'),
        resendButton: $('rs'),
        success: $('ok'),
        successText: $('okt'),
        error: $('er'),
        live: $('lv'),
      };

      this.#el.phoneForm.addEventListener('submit', (event) => {
        event.preventDefault();
        void this.#submitPhone();
      });
      this.#el.codeForm.addEventListener('submit', (event) => {
        event.preventDefault();
        void this.#submitCode();
      });
      this.#el.changeButton.addEventListener('click', () => {
        this.#goToPhoneStep();
      });
      this.#el.resendButton.addEventListener('click', () => {
        if (!this.#busy && this.#secondsBeforeResend() === 0) {
          void this.#send(this.#phoneNumber);
        }
      });
      this.#el.phone.addEventListener('input', () => {
        this.#el.phone.removeAttribute('aria-invalid');
      });
      this.#el.cells.addEventListener('input', (event) => {
        this.#onCellInput(event);
      });
      this.#el.cells.addEventListener('keydown', (event) => {
        this.#onCellKeydown(event);
      });
      this.#el.cells.addEventListener('paste', (event) => {
        this.#onCellPaste(event);
      });
      this.#el.cells.addEventListener('focusin', (event) => {
        // Sélection du contenu : une frappe remplace le caractère au lieu de s'y ajouter.
        const cell = event.target;
        if (cell instanceof HTMLInputElement) {
          cell.select();
        }
      });
    }

    connectedCallback(): void {
      if (this.#el.countryCode.value === '') {
        this.#el.countryCode.value = this.#defaultCountryCode();
      }
      const phone = this.getAttribute('phone');
      if (phone !== null && this.#el.phone.value === '') {
        this.#el.phone.value = phone;
      }
      this.#readCodeAttributes();
      this.#applyTexts();
      if (this.#step === 'code') {
        this.#startTimer();
      }
    }

    disconnectedCallback(): void {
      this.#stopTimer();
      this.#stopWebOtp();
    }

    attributeChangedCallback(name: string, oldValue: string | null, value: string | null): void {
      if (oldValue === value) {
        return;
      }
      if (name === 'country-code' && value !== null && isCountryCode(value.replace(/^\+/, ''))) {
        this.#el.countryCode.value = value.replace(/^\+/, '');
      } else if (name === 'phone' && value !== null && this.#step === 'phone') {
        this.#el.phone.value = value;
      } else if ((name === 'code-length' || name === 'code-type') && this.#step === 'phone') {
        this.#readCodeAttributes();
      }
      this.#applyTexts();
    }

    get step(): OtpWidgetStep {
      return this.#step;
    }

    get texts(): OtpWidgetTextsOverride | null {
      return this.#textsOverride;
    }

    set texts(value: OtpWidgetTextsOverride | null) {
      this.#textsOverride = value;
      this.#applyTexts();
    }

    get requestHeaders(): Record<string, string> {
      return { ...this.#requestHeaders };
    }

    set requestHeaders(value: Record<string, string> | null) {
      this.#requestHeaders = { ...(value ?? {}) };
    }

    reset(): void {
      this.#needsNewCode = false;
      this.#attemptsLeft = null;
      this.#clearCells();
      this.#goToPhoneStep();
      this.#el.phone.value = this.getAttribute('phone') ?? '';
    }

    override focus(options?: FocusOptions): void {
      const target =
        this.#step === 'phone' ? this.#el.phone : this.#step === 'code' ? this.#firstEmptyCell() ?? this.#el.verifyButton : this.#el.success;
      target.focus(options);
    }

    // ----- Étape 1 : numéro -------------------------------------------------

    async #submitPhone(): Promise<void> {
      if (this.#busy) {
        return;
      }
      const countryCode = this.#el.countryCode.value.replace(/\D/g, '') || this.#defaultCountryCode();
      const phone = normalizePhone(this.#el.phone.value, countryCode);
      if (phone === null) {
        this.#el.phone.setAttribute('aria-invalid', 'true');
        this.#fail('invalid_phone', null);
        this.#el.phone.focus();
        return;
      }
      await this.#send(phone);
    }

    async #send(phone: string): Promise<void> {
      this.#setBusy(true);
      this.#clearError();
      // La langue du widget sert de langue du SMS, sauf si le serveur en impose une.
      const locale = this.getAttribute('locale')?.toLowerCase().startsWith('en') === true ? 'en' : 'fr';
      // « + » explicite : le serveur ne doit pas ajouter son indicatif par défaut à un numéro déjà international.
      const result = await this.#post(this.getAttribute('send-url') ?? '/api/otp/send', { phone: `+${phone}`, locale });
      this.#setBusy(false);
      if (result === null) {
        return;
      }
      const body = result.body as Partial<SendSuccessBody> | null;
      if (result.status >= 200 && result.status < 300 && body?.sent === true) {
        this.#onSent(phone, body);
        return;
      }
      const { code, retryAfterSeconds } = readError(result);
      if (code === 'resend_too_soon' && retryAfterSeconds !== undefined) {
        this.#resendAt = Date.now() + retryAfterSeconds * 1000;
        this.#startTimer();
      }
      if (code === 'invalid_phone' && this.#step === 'phone') {
        this.#el.phone.setAttribute('aria-invalid', 'true');
      }
      this.#fail(code, result.status, retryAfterSeconds);
    }

    #onSent(phone: string, body: Partial<SendSuccessBody>): void {
      this.#phoneNumber = phone;
      this.#phoneMasked = typeof body.phone_masked === 'string' ? body.phone_masked : `+${phone}`;
      const length = body.code_length;
      if (typeof length === 'number' && Number.isInteger(length) && length >= 1 && length <= 20) {
        this.#codeLength = length;
      }
      if (body.code_type === 'numeric' || body.code_type === 'alphanumeric') {
        this.#codeType = body.code_type;
      }
      const resendAfter = typeof body.resend_after_seconds === 'number' ? Math.max(0, body.resend_after_seconds) : 60;
      const maxAttempts = typeof body.max_attempts === 'number' ? body.max_attempts : null;
      this.#resendAt = Date.now() + resendAfter * 1000;
      this.#attemptsLeft = maxAttempts;
      this.#needsNewCode = false;
      this.#lastAutoSubmitted = '';

      const testCode = typeof body.test_code === 'string' && body.test_code !== '' ? body.test_code : undefined;
      this.#el.testCode.hidden = testCode === undefined;
      this.#el.testCode.textContent = testCode === undefined ? '' : format(this.#texts.testMode, { code: testCode });

      this.#step = 'code';
      this.#buildCells();
      this.#showStep();
      this.#applyTexts();
      this.#startTimer();
      this.#startWebOtp();
      this.#announce(format(this.#texts.codeSentTo, { phone: this.#phoneMasked }));
      this.#firstEmptyCell()?.focus();

      const detail: OtpSentDetail = {
        phone,
        phoneMasked: this.#phoneMasked,
        expiresAt: new Date(typeof body.expires_at === 'string' ? body.expires_at : Date.now()),
        maxAttempts: maxAttempts ?? 0,
        resendAfterSeconds: resendAfter,
        ...(testCode === undefined ? {} : { testCode }),
      };
      this.#emit(EVENT_SENT, detail);
    }

    // ----- Étape 2 : code ---------------------------------------------------

    async #submitCode(): Promise<void> {
      if (this.#busy || this.#needsNewCode) {
        return;
      }
      const code = this.#code();
      if (code.length < this.#codeLength) {
        this.#fail('incomplete_code', null);
        this.#firstEmptyCell()?.focus();
        return;
      }
      this.#setBusy(true);
      this.#clearError();
      const result = await this.#post(this.getAttribute('verify-url') ?? '/api/otp/verify', { code });
      this.#setBusy(false);
      if (result === null) {
        this.#lastAutoSubmitted = '';
        return;
      }
      const body = result.body as { verified?: unknown; data?: unknown; reason?: unknown; attempts_left?: unknown } | null;
      if (result.status >= 200 && result.status < 300 && body?.verified === true) {
        this.#onVerified(body.data);
        return;
      }
      if (result.status >= 200 && result.status < 300 && body?.verified === false) {
        const reason: OtpFailureReason = FAILURE_REASONS.includes(body.reason as OtpFailureReason)
          ? (body.reason as OtpFailureReason)
          : 'invalid_code';
        this.#attemptsLeft = typeof body.attempts_left === 'number' ? body.attempts_left : null;
        this.#onRefused(reason, result.status);
        return;
      }
      const { code: errorCode, retryAfterSeconds } = readError(result);
      if (NEEDS_NEW_CODE.includes(errorCode)) {
        this.#onRefused(errorCode, result.status);
        return;
      }
      this.#lastAutoSubmitted = '';
      this.#fail(errorCode, result.status, retryAfterSeconds);
    }

    #onRefused(reason: OtpWidgetErrorCode, status: number): void {
      this.#clearCells();
      this.#lastAutoSubmitted = '';
      if (NEEDS_NEW_CODE.includes(reason)) {
        this.#needsNewCode = true;
        this.#attemptsLeft = null;
      }
      this.#applyTexts();
      this.#fail(reason, status, undefined, this.#attemptsLeft);
      if (this.#needsNewCode) {
        (this.#el.resendButton.disabled ? this.#el.changeButton : this.#el.resendButton).focus();
      } else {
        this.#firstEmptyCell()?.focus();
      }
    }

    #onVerified(data: unknown): void {
      this.#step = 'success';
      this.#stopTimer();
      this.#stopWebOtp();
      this.#clearError();
      this.#showStep();
      this.#applyTexts();
      this.#el.success.focus();
      this.#announce(this.#texts.success);
      const detail: OtpVerifiedDetail = { phone: this.#phoneNumber, data: data ?? null };
      this.#emit(EVENT_VERIFIED, detail);
    }

    #goToPhoneStep(): void {
      this.#step = 'phone';
      this.#stopTimer();
      this.#stopWebOtp();
      this.#clearError();
      this.#readCodeAttributes();
      this.#showStep();
      this.#applyTexts();
      this.#el.phone.focus();
    }

    // ----- Cases du code ----------------------------------------------------

    #buildCells(): void {
      const cells = this.#el.cells;
      cells.textContent = '';
      for (let i = 0; i < this.#codeLength; i++) {
        const input = document.createElement('input');
        input.type = 'text';
        input.inputMode = this.#codeType === 'numeric' ? 'numeric' : 'text';
        input.autocomplete = i === 0 ? 'one-time-code' : 'off';
        input.spellcheck = false;
        input.setAttribute('autocapitalize', this.#codeType === 'numeric' ? 'off' : 'characters');
        input.setAttribute('enterkeyhint', 'done');
        input.setAttribute('part', 'cell');
        input.dataset['index'] = String(i);
        cells.append(input);
      }
    }

    #cells(): HTMLInputElement[] {
      return Array.from(this.#el.cells.querySelectorAll('input'));
    }

    #code(): string {
      return this.#cells()
        .map((cell) => cell.value)
        .join('');
    }

    #firstEmptyCell(): HTMLInputElement | undefined {
      const cells = this.#cells();
      return cells.find((cell) => cell.value === '') ?? cells[cells.length - 1];
    }

    #clearCells(): void {
      for (const cell of this.#cells()) {
        cell.value = '';
      }
    }

    #sanitize(value: string): string {
      return this.#codeType === 'numeric' ? value.replace(/\D/g, '') : value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    }

    /** Écrit `text` à partir de la case `start` (collage, saisie automatique, Web OTP). */
    #fillFrom(start: number, text: string): void {
      const cells = this.#cells();
      const chars = this.#sanitize(text);
      const from = chars.length >= cells.length ? 0 : start;
      for (let i = 0; i < chars.length && from + i < cells.length; i++) {
        const cell = cells[from + i];
        if (cell !== undefined) {
          cell.value = chars.charAt(i);
        }
      }
      const next = cells.find((cell, index) => index >= from && cell.value === '') ?? cells[Math.min(from + chars.length, cells.length - 1)];
      next?.focus();
      this.#afterCodeChange();
    }

    #onCellInput(event: Event): void {
      const cell = event.target;
      if (!(cell instanceof HTMLInputElement)) {
        return;
      }
      const index = Number(cell.dataset['index']);
      const value = this.#sanitize(cell.value);
      if (value.length <= 1) {
        cell.value = value;
        if (value !== '') {
          this.#cells()[index + 1]?.focus();
        }
        this.#afterCodeChange();
        return;
      }
      cell.value = '';
      this.#fillFrom(index, value);
    }

    #onCellKeydown(event: KeyboardEvent): void {
      const cell = event.target;
      if (!(cell instanceof HTMLInputElement)) {
        return;
      }
      const cells = this.#cells();
      const index = Number(cell.dataset['index']);
      let target: HTMLInputElement | undefined;
      if (event.key === 'Backspace' && cell.value === '' && index > 0) {
        target = cells[index - 1];
        if (target !== undefined) {
          target.value = '';
        }
      } else if (event.key === 'ArrowLeft') {
        target = cells[index - 1];
      } else if (event.key === 'ArrowRight') {
        target = cells[index + 1];
      } else if (event.key === 'Home') {
        target = cells[0];
      } else if (event.key === 'End') {
        target = cells[cells.length - 1];
      }
      if (target !== undefined) {
        event.preventDefault();
        target.focus();
      }
    }

    #onCellPaste(event: ClipboardEvent): void {
      const cell = event.target;
      const text = event.clipboardData?.getData('text') ?? '';
      if (!(cell instanceof HTMLInputElement) || text === '') {
        return;
      }
      event.preventDefault();
      this.#fillFrom(Number(cell.dataset['index']), text);
    }

    #afterCodeChange(): void {
      if (this.#errorCode === 'incomplete_code' || this.#errorCode === 'invalid_code') {
        this.#clearError();
      }
      const code = this.#code();
      if (
        code.length === this.#codeLength &&
        !this.hasAttribute('no-auto-submit') &&
        code !== this.#lastAutoSubmitted &&
        !this.#busy &&
        !this.#needsNewCode
      ) {
        this.#lastAutoSubmitted = code;
        void this.#submitCode();
      }
    }

    // ----- Renvoi et Web OTP ------------------------------------------------

    #secondsBeforeResend(): number {
      return Math.max(0, Math.ceil((this.#resendAt - Date.now()) / 1000));
    }

    #startTimer(): void {
      this.#stopTimer();
      this.#renderResend();
      if (this.#secondsBeforeResend() > 0) {
        this.#timer = setInterval(() => {
          this.#renderResend();
          if (this.#secondsBeforeResend() === 0) {
            this.#stopTimer();
            if (this.#step === 'code') {
              this.#announce(this.#texts.resendAvailable);
            }
          }
        }, 1000);
      }
    }

    #stopTimer(): void {
      if (this.#timer !== undefined) {
        clearInterval(this.#timer);
        this.#timer = undefined;
      }
    }

    #renderResend(): void {
      const seconds = this.#secondsBeforeResend();
      const button = this.#el.resendButton;
      button.disabled = seconds > 0 || this.#busy;
      button.textContent = seconds > 0 ? format(this.#texts.resendIn, { time: formatDuration(seconds) }) : this.#texts.resend;
      if (this.#step === 'phone') {
        this.#el.sendButton.disabled = this.#busy || (this.#errorCode === 'resend_too_soon' && seconds > 0);
      }
    }

    #startWebOtp(): void {
      this.#stopWebOtp();
      const credentials = (navigator as Partial<Navigator>).credentials;
      if (this.hasAttribute('no-web-otp') || !('OTPCredential' in window) || credentials === undefined) {
        return;
      }
      const controller = new AbortController();
      this.#webOtp = controller;
      credentials
        .get({ otp: { transport: ['sms'] }, signal: controller.signal } as CredentialRequestOptions)
        .then((credential) => {
          const code = (credential as { code?: unknown } | null)?.code;
          if (typeof code === 'string' && !controller.signal.aborted && this.#step === 'code') {
            this.#fillFrom(0, code);
          }
        })
        .catch(() => {
          // Annulé, refusé ou non pris en charge : la saisie manuelle reste possible.
        });
    }

    #stopWebOtp(): void {
      this.#webOtp?.abort();
      this.#webOtp = undefined;
    }

    // ----- Rendu ------------------------------------------------------------

    #showStep(): void {
      this.#el.phoneForm.hidden = this.#step !== 'phone';
      this.#el.codeForm.hidden = this.#step !== 'code';
      this.#el.success.hidden = this.#step !== 'success';
    }

    #applyTexts(): void {
      const t = (this.#texts = resolveTexts(this.getAttribute('locale'), this.#textsOverride));
      const el = this.#el;
      el.title.textContent = this.getAttribute('heading') ?? t.title;
      el.title.hidden = this.getAttribute('heading') === '';
      el.phoneLabel.textContent = t.phoneLabel;
      el.countryCode.setAttribute('aria-label', t.countryCodeLabel);
      el.phone.placeholder = t.phonePlaceholder;
      el.phoneHint.textContent = t.phoneHint;
      el.sendButton.textContent = this.#busy && this.#step === 'phone' ? t.sending : t.send;
      el.sentTo.textContent = format(t.codeSentTo, { phone: this.#phoneMasked });
      el.changeButton.textContent = t.changePhone;
      el.codeLabel.textContent = t.codeLabel;
      el.verifyButton.textContent = this.#busy && this.#step === 'code' ? t.verifying : t.verify;
      el.successText.textContent = t.success;
      const cells = this.#cells();
      cells.forEach((cell, index) => {
        cell.setAttribute('aria-label', format(t.cellLabel, { index: index + 1, total: cells.length }));
        cell.disabled = this.#needsNewCode;
      });
      el.verifyButton.disabled = this.#busy || this.#needsNewCode;
      const left = this.#attemptsLeft;
      el.attempts.textContent =
        left === null || this.#errorCode !== 'invalid_code'
          ? ''
          : format(left === 1 ? t.attemptsLeftOne : t.attemptsLeftOther, { count: left });
      if (this.#errorCode !== null) {
        // Changement de langue ou de textes : le message affiché suit.
        const message = this.#errorMessage(this.#errorCode);
        if (el.error.textContent.replace(ZERO_WIDTH_SPACE, '') !== message) {
          el.error.textContent = message;
        }
      }
      this.#renderResend();
    }

    #setBusy(busy: boolean): void {
      this.#busy = busy;
      const form = this.#step === 'phone' ? this.#el.phoneForm : this.#el.codeForm;
      form.setAttribute('aria-busy', String(busy));
      this.#el.sendButton.disabled = busy;
      this.#applyTexts();
    }

    #errorMessage(code: OtpWidgetErrorCode): string {
      return format(this.#texts.errors[code], { count: this.#codeLength, ...this.#errorValues });
    }

    #fail(code: OtpWidgetErrorCode, status: number | null, retryAfterSeconds?: number, attemptsLeft?: number | null): void {
      this.#errorCode = code;
      this.#errorValues = retryAfterSeconds === undefined ? {} : { time: formatDuration(retryAfterSeconds) };
      const message = this.#errorMessage(code);
      // Même texte deux fois de suite : on le modifie d'un caractère invisible pour qu'il soit réannoncé.
      this.#el.error.textContent = this.#el.error.textContent === message ? message + ZERO_WIDTH_SPACE : message;
      this.#applyTexts();
      const detail: OtpErrorDetail = {
        code,
        message,
        status,
        ...(retryAfterSeconds === undefined ? {} : { retryAfterSeconds }),
        ...(attemptsLeft === undefined ? {} : { attemptsLeft }),
      };
      this.#emit(EVENT_ERROR, detail);
    }

    #clearError(): void {
      this.#errorCode = null;
      this.#errorValues = {};
      this.#el.error.textContent = '';
      this.#el.attempts.textContent = '';
    }

    #announce(message: string): void {
      this.#el.live.textContent = message;
    }

    #emit(type: string, detail: unknown): void {
      this.dispatchEvent(new CustomEvent(type, { detail, bubbles: true, composed: true }));
    }

    #defaultCountryCode(): string {
      const value = (this.getAttribute('country-code') ?? '').replace(/^\+/, '');
      return isCountryCode(value) ? value : DEFAULT_COUNTRY_CODE;
    }

    #readCodeAttributes(): void {
      const length = Number(this.getAttribute('code-length') ?? '6');
      this.#codeLength = Number.isInteger(length) && length >= 1 && length <= 20 ? length : 6;
      this.#codeType = this.getAttribute('code-type') === 'alphanumeric' ? 'alphanumeric' : 'numeric';
    }

    /** POST JSON vers le serveur de l'intégrateur ; null après une erreur réseau (déjà signalée). */
    async #post(url: string, body: unknown): Promise<HttpResult | null> {
      try {
        const response = await fetch(url, {
          method: 'POST',
          headers: { ...this.#requestHeaders, 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify(body),
          credentials: this.getAttribute('credentials') === 'include' ? 'include' : 'same-origin',
        });
        let json: unknown = null;
        try {
          json = await response.json();
        } catch {
          json = null;
        }
        return { status: response.status, body: json };
      } catch {
        this.#fail('network_error', null);
        return null;
      }
    }
  }

  return LabyrintheOtp;
}

/** Lit `{ error: { code, retry_after_seconds } }` ; repli sur le statut HTTP. */
function readError(result: HttpResult): { code: ServerErrorCode; retryAfterSeconds?: number } {
  const error = (result.body as { error?: { code?: unknown; retry_after_seconds?: unknown } } | null)?.error;
  const retry = error?.retry_after_seconds;
  const retryAfterSeconds = typeof retry === 'number' && retry >= 0 ? Math.ceil(retry) : undefined;
  let code: ServerErrorCode;
  if (typeof error?.code === 'string' && SERVER_CODES.includes(error.code as ServerErrorCode)) {
    code = error.code as ServerErrorCode;
  } else if (result.status === 429) {
    code = 'rate_limited';
  } else if (result.status === 403) {
    code = 'forbidden';
  } else if (result.status === 503) {
    code = 'service_unavailable';
  } else {
    code = 'server_error';
  }
  return retryAfterSeconds === undefined ? { code } : { code, retryAfterSeconds };
}

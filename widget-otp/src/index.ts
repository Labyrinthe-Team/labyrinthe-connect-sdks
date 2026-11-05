/**
 * `<labyrinthe-otp>`: phone verification by SMS code, ready to use.
 *
 *     <script type="module">import '@labyrinthe/otp-widget';</script>
 *     <labyrinthe-otp send-url="/api/otp/send" verify-url="/api/otp/verify"></labyrinthe-otp>
 *
 * The widget only talks to YOUR server (see `@labyrinthe/otp-widget/server`):
 * no API key, no `otp_id` and no code ever reach the browser.
 *
 * Importing this module defines the element when `customElements` exists;
 * on a server (SSR) it does nothing.
 */
import { TAG_NAME, createElementClass, type LabyrintheOtpElement } from './element.js';

export {
  EVENT_ERROR,
  EVENT_SENT,
  EVENT_VERIFIED,
  TAG_NAME,
  type LabyrintheOtpElement,
  type OtpErrorDetail,
  type OtpSentDetail,
  type OtpVerifiedDetail,
  type OtpWidgetStep,
} from './element.js';
export { LOCALES, resolveTexts, type OtpWidgetLocale, type OtpWidgetTexts, type OtpWidgetTextsOverride } from './i18n.js';
export { DEFAULT_COUNTRY_CODE, maskPhone, normalizePhone } from './phone.js';
export type {
  CodeType,
  ErrorBody,
  OtpFailureReason,
  OtpWidgetErrorCode,
  SendSuccessBody,
  ServerErrorCode,
  VerifyFailureBody,
  VerifySuccessBody,
} from './protocol.js';
export { VERSION } from './version.js';

/**
 * Defines `<labyrinthe-otp>` (or another tag name) and returns its class.
 * Idempotent; returns undefined where custom elements do not exist (SSR).
 */
export function defineLabyrintheOtp(tagName: string = TAG_NAME): CustomElementConstructor | undefined {
  if (typeof customElements === 'undefined' || typeof HTMLElement === 'undefined') {
    return undefined;
  }
  const existing = customElements.get(tagName);
  if (existing !== undefined) {
    return existing;
  }
  const ElementClass = createElementClass();
  customElements.define(tagName, ElementClass);
  return ElementClass;
}

defineLabyrintheOtp();

declare global {
  interface HTMLElementTagNameMap {
    'labyrinthe-otp': LabyrintheOtpElement;
  }
}

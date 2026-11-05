/**
 * React wrapper of `<labyrinthe-otp>` (React 18 and 19, Next.js App Router).
 *
 *     'use client';
 *     import { LabyrintheOtp } from '@labyrinthe/otp-widget/react';
 *
 *     <LabyrintheOtp sendUrl="/api/otp/send" verifyUrl="/api/otp/verify"
 *       onVerified={({ data }) => router.push('/dashboard')} />
 */
import { createElement, forwardRef, useEffect, useImperativeHandle, useRef, type CSSProperties } from 'react';
import {
  EVENT_ERROR,
  EVENT_SENT,
  EVENT_VERIFIED,
  defineLabyrintheOtp,
  type LabyrintheOtpElement,
  type OtpErrorDetail,
  type OtpSentDetail,
  type OtpVerifiedDetail,
  type OtpWidgetLocale,
  type OtpWidgetTextsOverride,
} from '@labyrinthe/otp-widget';

export interface LabyrintheOtpProps {
  /** Your endpoint that sends the code (default "/api/otp/send"). */
  sendUrl?: string;
  /** Your endpoint that verifies the code (default "/api/otp/verify"). */
  verifyUrl?: string;
  /** "fr" (default) or "en". */
  locale?: OtpWidgetLocale;
  /** Default country code, without "+" (default "243", DR Congo). */
  countryCode?: string;
  /** Pre-filled phone number. */
  phone?: string;
  /** Number of code boxes before the server says otherwise (default 6). */
  codeLength?: number;
  codeType?: 'numeric' | 'alphanumeric';
  /** "light", "dark" or automatic (default). */
  theme?: 'light' | 'dark';
  /** Title shown above the form; "" hides it. */
  heading?: string;
  /** Text overrides, merged with the locale defaults. */
  texts?: OtpWidgetTextsOverride;
  /** Extra headers sent to your endpoints (e.g. a CSRF token). */
  requestHeaders?: Record<string, string>;
  /** "include" when your endpoints are on another origin (default "same-origin"). */
  credentials?: 'same-origin' | 'include';
  /** Submit as soon as every box is filled (default true). */
  autoSubmit?: boolean;
  /** Web OTP API (Android Chrome) when available (default true). */
  webOtp?: boolean;
  className?: string;
  style?: CSSProperties;
  id?: string;
  onSent?: (detail: OtpSentDetail) => void;
  onVerified?: (detail: OtpVerifiedDetail) => void;
  onError?: (detail: OtpErrorDetail) => void;
}

export const LabyrintheOtp = forwardRef<LabyrintheOtpElement | null, LabyrintheOtpProps>(function LabyrintheOtp(props, ref) {
  const element = useRef<LabyrintheOtpElement | null>(null);
  const callbacks = useRef(props);
  callbacks.current = props;

  useImperativeHandle<LabyrintheOtpElement | null, LabyrintheOtpElement | null>(ref, () => element.current, []);

  // Événements : toujours les derniers callbacks, sans réabonnement à chaque rendu.
  useEffect(() => {
    defineLabyrintheOtp();
    const node = element.current;
    if (node === null) {
      return undefined;
    }
    const onSent = (event: Event) => callbacks.current.onSent?.((event as CustomEvent<OtpSentDetail>).detail);
    const onVerified = (event: Event) => callbacks.current.onVerified?.((event as CustomEvent<OtpVerifiedDetail>).detail);
    const onError = (event: Event) => callbacks.current.onError?.((event as CustomEvent<OtpErrorDetail>).detail);
    node.addEventListener(EVENT_SENT, onSent);
    node.addEventListener(EVENT_VERIFIED, onVerified);
    node.addEventListener(EVENT_ERROR, onError);
    return () => {
      node.removeEventListener(EVENT_SENT, onSent);
      node.removeEventListener(EVENT_VERIFIED, onVerified);
      node.removeEventListener(EVENT_ERROR, onError);
    };
  }, []);

  // Propriétés objets (React 18 ne sait poser que des attributs sur un custom element).
  const { texts, requestHeaders } = props;
  useEffect(() => {
    if (element.current !== null) {
      element.current.texts = texts ?? null;
    }
  }, [texts]);
  useEffect(() => {
    if (element.current !== null) {
      element.current.requestHeaders = requestHeaders ?? {};
    }
  }, [requestHeaders]);

  return createElement('labyrinthe-otp', {
    ref: element,
    id: props.id,
    className: props.className,
    style: props.style,
    'send-url': props.sendUrl,
    'verify-url': props.verifyUrl,
    locale: props.locale,
    'country-code': props.countryCode,
    phone: props.phone,
    'code-length': props.codeLength === undefined ? undefined : String(props.codeLength),
    'code-type': props.codeType,
    theme: props.theme,
    heading: props.heading,
    credentials: props.credentials,
    'no-auto-submit': props.autoSubmit === false ? '' : undefined,
    'no-web-otp': props.webOtp === false ? '' : undefined,
  });
});

export type {
  LabyrintheOtpElement,
  OtpErrorDetail,
  OtpSentDetail,
  OtpVerifiedDetail,
  OtpWidgetLocale,
  OtpWidgetTextsOverride,
} from '@labyrinthe/otp-widget';

// @vitest-environment happy-dom
import { act, createRef } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { renderToString } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LabyrintheOtp, type LabyrintheOtpElement } from '../src/react.js';
import { browserFetch, fakeClient, flush, handlers } from './helpers.js';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root | undefined;
afterEach(() => {
  act(() => root?.unmount());
  root = undefined;
  document.body.innerHTML = '';
  vi.unstubAllGlobals();
});

function render(ui: React.ReactNode): HTMLElement {
  const container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
  act(() => root!.render(ui));
  return container;
}

describe('<LabyrintheOtp> (React)', () => {
  it('renders the custom element with attributes and object properties', () => {
    const ref = createRef<LabyrintheOtpElement>();
    const texts = { send: 'Get my code' };
    const container = render(
      <LabyrintheOtp
        ref={ref}
        sendUrl="/auth/send"
        verifyUrl="/auth/verify"
        locale="en"
        countryCode="33"
        codeLength={4}
        theme="dark"
        className="otp"
        autoSubmit={false}
        webOtp={false}
        texts={texts}
        requestHeaders={{ 'X-CSRF-Token': 't' }}
      />,
    );
    const element = container.querySelector('labyrinthe-otp')!;
    expect(ref.current).toBe(element);
    expect(element.getAttribute('send-url')).toBe('/auth/send');
    expect(element.getAttribute('verify-url')).toBe('/auth/verify');
    expect(element.getAttribute('locale')).toBe('en');
    expect(element.getAttribute('country-code')).toBe('33');
    expect(element.getAttribute('code-length')).toBe('4');
    expect(element.getAttribute('theme')).toBe('dark');
    expect(element.getAttribute('class')).toBe('otp');
    expect(element.hasAttribute('no-auto-submit')).toBe(true);
    expect(element.hasAttribute('no-web-otp')).toBe(true);
    expect(element.texts).toEqual(texts);
    expect(element.requestHeaders).toEqual({ 'X-CSRF-Token': 't' });
    expect(element.shadowRoot!.querySelector('#sb')!.textContent).toBe('Get my code');
  });

  it('calls onSent, onError and onVerified with the event details', async () => {
    const client = fakeClient();
    const net = browserFetch(handlers(client, { onVerified: () => ({ ok: 1 }) }));
    vi.stubGlobal('fetch', net.fetch);
    const onSent = vi.fn();
    const onError = vi.fn();
    const onVerified = vi.fn();
    const container = render(<LabyrintheOtp onSent={onSent} onError={onError} onVerified={onVerified} />);
    const shadow = container.querySelector('labyrinthe-otp')!.shadowRoot!;

    const phone = shadow.querySelector<HTMLInputElement>('#pn')!;
    phone.value = '1';
    await act(async () => {
      shadow.querySelector<HTMLFormElement>('#pf')!.requestSubmit();
      await flush();
    });
    expect(onError).toHaveBeenCalledWith(expect.objectContaining({ code: 'invalid_phone' }));

    phone.value = '0812345678';
    await act(async () => {
      shadow.querySelector<HTMLFormElement>('#pf')!.requestSubmit();
      await flush();
    });
    expect(onSent).toHaveBeenCalledWith(expect.objectContaining({ phone: '243812345678' }));

    const cells = shadow.querySelector<HTMLInputElement>('#cells input')!;
    await act(async () => {
      cells.value = '482913';
      cells.dispatchEvent(new Event('input', { bubbles: true }));
      await flush();
    });
    expect(onVerified).toHaveBeenCalledWith({ phone: '243812345678', data: { ok: 1 } });
  });

  it('renders on the server without touching the DOM (SSR)', () => {
    expect(renderToString(<LabyrintheOtp sendUrl="/s" />)).toBe('<labyrinthe-otp send-url="/s"></labyrinthe-otp>');
  });
});

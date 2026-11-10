// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '../src/index.js';
import { EVENT_ERROR, EVENT_SENT, EVENT_VERIFIED, type LabyrintheOtpElement, type OtpErrorDetail } from '../src/index.js';
import { browserFetch, fakeClient, flush, handlers, sdkErrors, type FakeClient } from './helpers.js';
import type { OtpHandlersOptions } from '../src/server/index.js';

let client: FakeClient;
let net: ReturnType<typeof browserFetch>;

function mount(attributes: Record<string, string> = {}, options: Partial<OtpHandlersOptions> = {}): LabyrintheOtpElement {
  net = browserFetch(handlers(client, { codeLength: 6, resendAfterSeconds: 30, ...options }));
  vi.stubGlobal('fetch', net.fetch);
  const element = document.createElement('labyrinthe-otp');
  for (const [name, value] of Object.entries(attributes)) {
    element.setAttribute(name, value);
  }
  document.body.append(element);
  return element;
}

const $ = <T extends Element = HTMLElement>(element: LabyrintheOtpElement, selector: string) =>
  element.shadowRoot!.querySelector(selector) as T;
const cells = (element: LabyrintheOtpElement) => Array.from(element.shadowRoot!.querySelectorAll<HTMLInputElement>('#cells input'));
const errorText = (element: LabyrintheOtpElement) => $(element, '#er').textContent.replace(String.fromCharCode(0x200b), '');

function events(element: LabyrintheOtpElement) {
  const log: { type: string; detail: any }[] = [];
  for (const type of [EVENT_SENT, EVENT_VERIFIED, EVENT_ERROR]) {
    element.addEventListener(type, (event) => log.push({ type, detail: (event as CustomEvent).detail }));
  }
  return log;
}

function type(input: HTMLInputElement, value: string) {
  input.value = value;
  input.dispatchEvent(new Event('input', { bubbles: true, composed: true }));
}

async function sendPhone(element: LabyrintheOtpElement, phone = '0812345678') {
  type($(element, '#pn'), phone);
  $<HTMLFormElement>(element, '#pf').requestSubmit();
  await flush();
}

async function typeCode(element: LabyrintheOtpElement, code: string) {
  code.split('').forEach((char, index) => {
    type(cells(element)[index]!, char);
  });
  await flush();
}

beforeEach(() => {
  client = fakeClient();
});

afterEach(() => {
  document.body.innerHTML = '';
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe('<labyrinthe-otp> rendering and accessibility', () => {
  it('renders the phone step with labels, hint, alert and live regions', () => {
    const element = mount();
    expect(customElements.get('labyrinthe-otp')).toBeDefined();
    expect(element.step).toBe('phone');
    const phone = $<HTMLInputElement>(element, '#pn');
    expect(phone.type).toBe('tel');
    expect(phone.getAttribute('autocomplete')).toBe('tel-national');
    expect($(element, 'label[for=pn]').textContent).toBe('Numéro de téléphone');
    expect(phone.getAttribute('aria-describedby')).toBe('ph er');
    expect($<HTMLInputElement>(element, '#cc').value).toBe('243');
    expect($(element, '#cc').getAttribute('aria-label')).toBe('Indicatif pays');
    expect($(element, '#er').getAttribute('role')).toBe('alert');
    expect($(element, '#lv').getAttribute('aria-live')).toBe('polite');
    expect($(element, '#sb').textContent).toBe('Recevoir le code');
    expect($<HTMLFormElement>(element, '#cf').hidden).toBe(true);
  });

  it('supports English, text overrides, a custom heading, country code and prefilled phone', () => {
    const element = mount({ locale: 'en', 'country-code': '+33', phone: '612345678', heading: 'Sign in' });
    expect($(element, '#sb').textContent).toBe('Send code');
    expect($(element, '#t').textContent).toBe('Sign in');
    expect($<HTMLInputElement>(element, '#cc').value).toBe('33');
    expect($<HTMLInputElement>(element, '#pn').value).toBe('612345678');
    element.texts = { send: 'Get my code', errors: { invalid_phone: 'Nope' } };
    expect($(element, '#sb').textContent).toBe('Get my code');
    element.setAttribute('locale', 'fr');
    expect($(element, '#pl').textContent).toBe('Numéro de téléphone');
    expect($(element, '#sb').textContent).toBe('Get my code');
    element.setAttribute('heading', '');
    expect($(element, '#t').hidden).toBe(true);
  });

  it('exposes parts for styling', () => {
    const element = mount();
    for (const part of ['root', 'phone-form', 'phone-input', 'send-button', 'error']) {
      expect(element.shadowRoot!.querySelector(`[part~="${part}"]`)).not.toBeNull();
    }
  });
});

describe('phone step', () => {
  it('rejects an invalid number locally: error, aria-invalid, event, no request', async () => {
    const element = mount();
    const log = events(element);
    await sendPhone(element, '12');
    expect(errorText(element)).toBe('Ce numéro de téléphone n’est pas valide.');
    expect($(element, '#pn').getAttribute('aria-invalid')).toBe('true');
    expect(log).toEqual([{ type: EVENT_ERROR, detail: { code: 'invalid_phone', message: expect.any(String), status: null } }]);
    expect(net.exchanges).toHaveLength(0);
    expect(element.shadowRoot!.activeElement).toBe($(element, '#pn'));
  });

  it('sends a short foreign number with its "+"', async () => {
    const element = mount({ locale: 'en' });
    await sendPhone(element, '+248 123 4567');
    expect(net.exchanges[0]!.requestBody).toMatchObject({ phone: '+2481234567' });
    expect(client.sent[0]).toMatchObject({ phone: '+2481234567' });
  });

  it('normalises the number like the SDK and sends the widget locale', async () => {
    const element = mount({ locale: 'en' });
    await sendPhone(element, '+243 81 234 5678');
    expect(net.exchanges[0]!.requestBody).toEqual({ phone: '+243812345678', locale: 'en', send_id: expect.stringMatching(/^[0-9a-f]{32}$/) });
    expect(client.sent[0]).toEqual({ phone: '+243812345678', locale: 'en', idempotencyKey: expect.stringMatching(/^otp-widget-/) });
  });

  it('reuses the send_id after a lost response: a second click does not send a second SMS', async () => {
    const element = mount();
    const realFetch = net.fetch;
    // Le serveur traite l'envoi, mais la réponse n'arrive jamais au navigateur.
    vi.stubGlobal('fetch', async (input: string, init: RequestInit) => {
      await realFetch(input, init);
      throw new TypeError('Failed to fetch');
    });
    await sendPhone(element);
    expect(errorText(element)).toMatch(/Connexion impossible/);

    vi.stubGlobal('fetch', realFetch);
    await sendPhone(element);

    expect(element.step).toBe('code');
    expect(client.sent).toHaveLength(2);
    expect(client.sent[1]!.idempotencyKey).toBe(client.sent[0]!.idempotencyKey);
    expect(client.replays).toBe(1);
    expect(client.otps.size).toBe(1);
  });

  it('uses a new send_id for each successful send (resend = new code)', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      const element = mount({}, { resendAfterSeconds: 0 });
      await sendPhone(element);
      $<HTMLButtonElement>(element, '#rs').click();
      await flush();
      expect(client.sent).toHaveLength(2);
      expect(client.sent[1]!.idempotencyKey).not.toBe(client.sent[0]!.idempotencyKey);
      expect(client.replays).toBe(0);
    } finally {
      vi.useRealTimers();
    }
  });

  it('builds the code boxes from the code_length / code_type returned by the API', async () => {
    client = fakeClient({ code: 'AB7K9QXZ', codeLength: 8, codeType: 'alphanumeric' });
    // Sans surcharge serveur : les valeurs viennent de l'API.
    net = browserFetch(handlers(client));
    vi.stubGlobal('fetch', net.fetch);
    const element = document.createElement('labyrinthe-otp');
    document.body.append(element);

    await sendPhone(element);

    expect(cells(element)).toHaveLength(8);
    expect(cells(element)[0]!.inputMode).toBe('text');
  });

  it('shows a stable account_suspended error', async () => {
    const element = mount();
    const log = events(element);
    client.nextSendError = sdkErrors.accountSuspended();
    await sendPhone(element);
    expect(errorText(element)).toMatch(/pas disponible sur ce site/);
    expect(log[0]).toMatchObject({ type: EVENT_ERROR, detail: { code: 'account_suspended', status: 503 } });
  });


  it('shows the maintenance message of the Labyrinthe administrator, with the time left when known', async () => {
    const element = mount();
    const log = events(element);
    client.nextSendError = sdkErrors.featureDisabled(90);
    await sendPhone(element);
    expect(errorText(element)).toBe('Maintenance jusqu’à 10 h. Réessayez dans 1:30.');
    expect(log[0]).toMatchObject({
      type: EVENT_ERROR,
      detail: { code: 'service_unavailable', status: 503, retryAfterSeconds: 90, adminMessage: 'Maintenance jusqu’à 10 h.' },
    });
    expect(log[0]!.detail.until).toEqual(new Date('2026-11-06T10:00:00Z'));
    expect(element.step).toBe('phone');

    element.setAttribute('locale', 'en');
    expect(errorText(element)).toBe('Maintenance jusqu’à 10 h. Try again in 1:30.');
  });

  it('shows the default text during a maintenance without an admin message or end date', async () => {
    const element = mount();
    const log = events(element);
    client.nextSendError = sdkErrors.featureDisabled(undefined, 'FEATURE_DISABLED', null);
    await sendPhone(element);
    expect(errorText(element)).toBe('Le service de vérification est momentanément indisponible.');
    expect(log[0]!.detail).not.toHaveProperty('adminMessage');
    expect(log[0]!.detail).not.toHaveProperty('until');
  });

  it('shows send_failed when the outcome of a send is unknown, and a new click uses a new send_id', async () => {
    const element = mount();
    const log = events(element);
    client.nextSendError = sdkErrors.unresolved();
    await sendPhone(element);
    expect(errorText(element)).toMatch(/n’a pas pu être confirmé/);
    expect(log[0]).toMatchObject({ type: EVENT_ERROR, detail: { code: 'send_failed', status: 502 } });
    expect(client.sent).toHaveLength(1);

    await sendPhone(element);
    expect(element.step).toBe('code');
    expect(client.sent).toHaveLength(2);
    expect(client.sent[1]!.idempotencyKey).not.toBe(client.sent[0]!.idempotencyKey);
  });

  it('uses the country code field for local numbers', async () => {
    const element = mount();
    type($(element, '#cc'), '33');
    await sendPhone(element, '06 12 34 56 78');
    expect(client.sent[0]!.phone).toBe('+33612345678');
  });

  it('reports a network error', async () => {
    const element = mount();
    vi.stubGlobal('fetch', () => Promise.reject(new TypeError('Failed to fetch')));
    const log = events(element);
    await sendPhone(element);
    expect(errorText(element)).toMatch(/Connexion impossible/);
    expect(log[0]).toMatchObject({ type: EVENT_ERROR, detail: { code: 'network_error', status: null } });
    expect(element.step).toBe('phone');
  });

  it('shows the server errors (rate limit, service unavailable, unknown body)', async () => {
    const element = mount();
    client.nextSendError = sdkErrors.rateLimited();
    await sendPhone(element);
    expect(errorText(element)).toMatch(/Trop de demandes/);

    client.nextSendError = sdkErrors.serviceDisabled();
    $<HTMLFormElement>(element, '#pf').requestSubmit();
    await flush();
    expect(errorText(element)).toMatch(/momentanément indisponible/);

    vi.stubGlobal('fetch', () => Promise.resolve(new Response('<html>', { status: 502 })));
    $<HTMLFormElement>(element, '#pf').requestSubmit();
    await flush();
    expect(errorText(element)).toMatch(/Une erreur est survenue/);
  });

  it('respects OTP_RESEND_TOO_SOON on the first send', async () => {
    const element = mount();
    const log = events(element);
    client.nextSendError = sdkErrors.resendTooSoon(42);
    await sendPhone(element);
    expect(errorText(element)).toBe('Patientez 0:42 avant de demander un nouveau code.');
    expect(log[0]!.detail).toMatchObject({ code: 'resend_too_soon', status: 429, retryAfterSeconds: 42 });
    expect($<HTMLButtonElement>(element, '#sb').disabled).toBe(true);
  });
});

describe('code step', () => {
  it('runs the whole flow: send, boxes, auto-submit, verified', async () => {
    const element = mount({}, { onVerified: () => ({ redirect: '/home' }) });
    const log = events(element);
    await sendPhone(element);

    expect(element.step).toBe('code');
    expect(log[0]).toMatchObject({ type: EVENT_SENT, detail: { phone: '243812345678', phoneMasked: '+243 81•••••78', maxAttempts: 3, resendAfterSeconds: 30 } });
    expect(log[0]!.detail.expiresAt).toBeInstanceOf(Date);
    expect($(element, '#st').textContent).toBe('Code envoyé au +243 81•••••78.');
    expect($(element, '#lv').textContent).toBe('Code envoyé au +243 81•••••78.');

    const boxes = cells(element);
    expect(boxes).toHaveLength(6);
    expect(boxes[0]!.getAttribute('autocomplete')).toBe('one-time-code');
    expect(boxes[1]!.getAttribute('autocomplete')).toBe('off');
    expect(boxes[0]!.inputMode).toBe('numeric');
    expect(boxes.map((box) => box.getAttribute('aria-label'))).toContain('Caractère 3 sur 6');
    expect($(element, '#cells').getAttribute('role')).toBe('group');
    expect(element.shadowRoot!.activeElement).toBe(boxes[0]);

    await typeCode(element, '482913');
    expect(element.step).toBe('success');
    expect(log.at(-1)).toEqual({ type: EVENT_VERIFIED, detail: { phone: '243812345678', data: { redirect: '/home' } } });
    expect($(element, '#okt').textContent).toBe('Numéro vérifié.');
    expect(element.shadowRoot!.activeElement).toBe($(element, '#ok'));
    expect(net.jar.size).toBe(0); // cookie de session effacé
  });

  it('never exposes otp_id or the code to the browser', async () => {
    const element = mount();
    await sendPhone(element);
    await typeCode(element, '482913');
    const otpId = [...client.otps.keys()][0]!;
    const browserSide = [
      ...net.exchanges.map((exchange) => exchange.responseText),
      element.shadowRoot!.innerHTML,
      document.cookie,
    ].join('\n');
    expect(browserSide).not.toContain(otpId);
    expect(net.exchanges[0]!.responseText).not.toContain('482913');
    expect(net.exchanges[0]!.responseText).not.toContain('test_code');
  });

  it('wrong code: message, attempts left, boxes cleared, focus back to the first box', async () => {
    const element = mount();
    const log = events(element);
    await sendPhone(element);
    await typeCode(element, '000000');

    expect(element.step).toBe('code');
    expect(errorText(element)).toBe('Code incorrect.');
    expect($(element, '#at').textContent).toBe('Il vous reste 2 tentatives.');
    expect(cells(element).every((box) => box.value === '')).toBe(true);
    expect(element.shadowRoot!.activeElement).toBe(cells(element)[0]);
    expect(log.at(-1)!.detail).toMatchObject({ code: 'invalid_code', attemptsLeft: 2, status: 200 });

    await typeCode(element, '000001');
    expect($(element, '#at').textContent).toBe('Il vous reste 1 tentative.');
  });

  it('max attempts: boxes and Verify disabled until a new code is sent', async () => {
    const element = mount();
    await sendPhone(element);
    await typeCode(element, '000000');
    await typeCode(element, '000001');
    await typeCode(element, '000002');

    expect(errorText(element)).toBe('Trop d’essais incorrects. Demandez un nouveau code.');
    expect(cells(element).every((box) => box.disabled)).toBe(true);
    expect($<HTMLButtonElement>(element, '#vb').disabled).toBe(true);
    expect(client.verified).toHaveLength(3);
  });

  it('resend: countdown, then enabled; a new code works', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
    const element = mount();
    await sendPhone(element);
    const resend = $<HTMLButtonElement>(element, '#rs');
    expect(resend.disabled).toBe(true);
    expect(resend.textContent).toBe('Renvoyer le code dans 0:30');

    vi.advanceTimersByTime(10_000);
    expect(resend.textContent).toBe('Renvoyer le code dans 0:20');
    resend.click();
    await flush();
    expect(client.sent).toHaveLength(1);

    vi.advanceTimersByTime(20_000);
    expect(resend.disabled).toBe(false);
    expect(resend.textContent).toBe('Renvoyer le code');
    expect($(element, '#lv').textContent).toBe('Vous pouvez demander un nouveau code.');

    resend.click();
    await flush();
    expect(client.sent).toHaveLength(2);
    expect(resend.disabled).toBe(true);
    await typeCode(element, '482913');
    expect(element.step).toBe('success');
  });

  it('resend refused by the API (OTP_RESEND_TOO_SOON) restarts the countdown with retry_after_seconds', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
    const element = mount({}, { resendAfterSeconds: 0 });
    await sendPhone(element);
    client.nextSendError = sdkErrors.resendTooSoon(25);
    $<HTMLButtonElement>(element, '#rs').click();
    await flush();
    expect(errorText(element)).toBe('Patientez 0:25 avant de demander un nouveau code.');
    expect($(element, '#rs').textContent).toBe('Renvoyer le code dans 0:25');
  });

  it('paste distributes the code over the boxes and submits', async () => {
    const element = mount();
    await sendPhone(element);
    const paste = new Event('paste', { bubbles: true, cancelable: true }) as Event & { clipboardData: unknown };
    Object.defineProperty(paste, 'clipboardData', { value: { getData: () => ' 482-913 ' } });
    cells(element)[2]!.dispatchEvent(paste);
    expect(paste.defaultPrevented).toBe(true);
    expect(cells(element).map((box) => box.value).join('')).toBe('482913');
    await flush();
    expect(element.step).toBe('success');
  });

  it('autofill of the whole code in the first box (autocomplete="one-time-code")', async () => {
    const element = mount();
    await sendPhone(element);
    type(cells(element)[0]!, '482913');
    expect(cells(element).map((box) => box.value)).toEqual(['4', '8', '2', '9', '1', '3']);
    await flush();
    expect(element.step).toBe('success');
  });

  it('keyboard: digits only, Backspace goes back, arrows move, Enter with an incomplete code', async () => {
    const element = mount({ 'no-auto-submit': '' });
    const log = events(element);
    await sendPhone(element);
    const boxes = cells(element);
    type(boxes[0]!, 'a');
    expect(boxes[0]!.value).toBe('');
    type(boxes[0]!, '4');
    expect(element.shadowRoot!.activeElement).toBe(boxes[1]);

    boxes[1]!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Backspace', bubbles: true, cancelable: true }));
    expect(element.shadowRoot!.activeElement).toBe(boxes[0]);
    expect(boxes[0]!.value).toBe('');
    boxes[0]!.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true }));
    expect(element.shadowRoot!.activeElement).toBe(boxes[1]);
    boxes[1]!.dispatchEvent(new KeyboardEvent('keydown', { key: 'End', bubbles: true, cancelable: true }));
    expect(element.shadowRoot!.activeElement).toBe(boxes[5]);

    type(boxes[0]!, '4');
    $<HTMLFormElement>(element, '#cf').requestSubmit();
    await flush();
    expect(errorText(element)).toBe('Saisissez les 6 caractères du code.');
    expect((log.at(-1)!.detail as OtpErrorDetail).code).toBe('incomplete_code');
    expect(client.verified).toHaveLength(0);

    await typeCode(element, '482913');
    expect(element.step).toBe('code'); // pas d'envoi automatique
    $<HTMLFormElement>(element, '#cf').requestSubmit();
    await flush();
    expect(element.step).toBe('success');
  });

  it('alphanumeric codes: uppercase, text keyboard, length from the server', async () => {
    client = fakeClient({ code: 'AB3K' });
    const element = mount({}, { codeLength: 4, codeType: 'alphanumeric' });
    await sendPhone(element);
    expect(cells(element)).toHaveLength(4);
    expect(cells(element)[0]!.inputMode).toBe('text');
    type(cells(element)[0]!, 'ab3k');
    await flush();
    expect(client.verified[0]!.code).toBe('AB3K');
    expect(element.step).toBe('success');
  });

  it('change number goes back to the phone step', async () => {
    const element = mount();
    await sendPhone(element);
    $<HTMLButtonElement>(element, '#chg').click();
    expect(element.step).toBe('phone');
    expect(element.shadowRoot!.activeElement).toBe($(element, '#pn'));
    expect($<HTMLInputElement>(element, '#pn').value).toBe('0812345678');
  });

  it('expired session (cookie lost): asks for a new code', async () => {
    const element = mount();
    await sendPhone(element);
    net.jar.clear();
    await typeCode(element, '482913');
    expect(errorText(element)).toBe('Votre demande a expiré. Demandez un nouveau code.');
    expect(cells(element)[0]!.disabled).toBe(true);
  });

  it('shows the test code only when the server exposes it', async () => {
    const element = mount({}, { exposeTestCode: true });
    const log = events(element);
    await sendPhone(element);
    expect($(element, '#tc').hidden).toBe(false);
    expect($(element, '#tc').textContent).toBe('Mode test : le code est 482913');
    expect(log[0]!.detail.testCode).toBe('482913');
  });

  it('sends requestHeaders (CSRF token) to the endpoints, and the configured URLs', async () => {
    const element = mount({ 'send-url': '/auth/otp/send', 'verify-url': '/auth/otp/verify' });
    const seen: { url: string; headers: Headers }[] = [];
    const routed = net.fetch;
    vi.stubGlobal('fetch', (url: string, init: RequestInit) => {
      seen.push({ url, headers: new Headers(init.headers) });
      return routed(url, init);
    });
    element.requestHeaders = { 'X-CSRF-Token': 'tok' };
    await sendPhone(element);
    await typeCode(element, '482913');
    expect(seen.map((s) => s.url)).toEqual(['/auth/otp/send', '/auth/otp/verify']);
    expect(seen.every((s) => s.headers.get('x-csrf-token') === 'tok' && s.headers.get('content-type') === 'application/json')).toBe(true);
  });

  it('reset() goes back to the phone step', async () => {
    const element = mount();
    await sendPhone(element);
    element.reset();
    expect(element.step).toBe('phone');
  });
});

describe('Web OTP API', () => {
  it('fills and submits the code received through navigator.credentials.get({ otp })', async () => {
    let resolve: (value: unknown) => void = () => undefined;
    const get = vi.fn((_options: unknown) => new Promise((r) => (resolve = r)));
    vi.stubGlobal('OTPCredential', class {});
    Object.defineProperty(navigator, 'credentials', { value: { get }, configurable: true });
    const element = mount();
    await sendPhone(element);
    expect(get).toHaveBeenCalledWith(expect.objectContaining({ otp: { transport: ['sms'] } }));
    resolve({ code: '482913' });
    await flush();
    expect(element.step).toBe('success');
  });

  it('is skipped with no-web-otp, and aborted when leaving the code step', async () => {
    const get = vi.fn((options: { signal: AbortSignal }) => new Promise((_resolve, reject) => options.signal.addEventListener('abort', reject)));
    vi.stubGlobal('OTPCredential', class {});
    Object.defineProperty(navigator, 'credentials', { value: { get }, configurable: true });

    const off = mount({ 'no-web-otp': '' });
    await sendPhone(off);
    expect(get).not.toHaveBeenCalled();
    off.remove();

    const on = mount();
    await sendPhone(on);
    const signal = get.mock.calls[0]![0].signal;
    $<HTMLButtonElement>(on, '#chg').click();
    expect(signal.aborted).toBe(true);
  });
});

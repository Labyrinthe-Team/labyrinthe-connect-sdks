/**
 * Garde le SDK aligné sur openapi/openapi.yaml (source de vérité) :
 * opérations, énumérations, codes d'erreur, limites, et validation des
 * réponses simulées et des requêtes du SDK contre les schémas de la spec.
 * Ignoré hors du monorepo (spec absente).
 */
import { existsSync, readFileSync } from 'node:fs';
import { Ajv2020 } from 'ajv/dist/2020.js';
import addFormatsModule from 'ajv-formats';
import { parse } from 'yaml';
import { describe, expect, it } from 'vitest';
import {
  DEFAULT_BASE_URL,
  Encoding,
  MessageStatus,
  OtpFailureReason,
  OtpRateLimitScope,
  RejectionReason,
  SenderIdStatus,
  WebhookEventType,
  Webhooks,
} from '../src/index.js';
import { ERROR_CLASSES_BY_CODE } from '../src/errors.js';
import * as otpLimits from '../src/resources/otp.js';
import * as smsLimits from '../src/resources/sms.js';
import { harness, json, message, page, type RecordedRequest } from './helpers.js';

const SPEC_URL = new URL('../../openapi/openapi.yaml', import.meta.url);
const available = existsSync(SPEC_URL);

type Node = Record<string, unknown>;
const spec: Node = available ? (parse(readFileSync(SPEC_URL, 'utf8')) as Node) : {};

/** Suit un chemin "components.schemas.Message.required" (les segments peuvent contenir des "/"). */
function at(path: string[]): unknown {
  let node: unknown = spec;
  for (const segment of path) {
    expect(node, path.join(' > ')).toBeTypeOf('object');
    node = (node as Node)[segment];
    expect(node, path.join(' > ')).toBeDefined();
  }
  return node;
}
const dotted = (path: string) => at(path.split('.'));
const strings = (path: string) => dotted(path) as string[];
const known = (values: Record<string, string>) => Object.values(values).filter((v) => v !== 'unknown');

const addFormats = addFormatsModule as unknown as (ajv: Ajv2020) => Ajv2020;
const ajv = new Ajv2020({ strict: false, validateSchema: false, allErrors: true });
addFormats(ajv);
if (available) ajv.addSchema(spec, 'spec');

const pointer = (path: string[]) => path.map((p) => p.replace(/~/g, '~0').replace(/\//g, '~1')).join('/');

/** Valide une valeur contre un schéma de la spec. */
function expectValid(path: string[], value: unknown): void {
  const validate = ajv.compile({ $ref: `spec#/${pointer(path)}` });
  const valid = validate(value);
  expect(valid, `${path.join(' > ')}: ${ajv.errorsText(validate.errors)}`).toBe(true);
}
const responseSchema = (pathKey: string, method: string, status: string) => [
  'paths',
  pathKey,
  method,
  'responses',
  status,
  'content',
  'application/json',
  'schema',
];
const requestSchema = (pathKey: string) => ['paths', pathKey, 'post', 'requestBody', 'content', 'application/json', 'schema'];

describe.skipIf(!available)('OpenAPI contract', () => {
  it('targets spec 1.1.0', () => {
    expect(dotted('info.version')).toBe('1.1.0');
  });

  it('implements every operation of the spec, and only those', () => {
    const operations = Object.entries(dotted('paths') as Record<string, Node>)
      .flatMap(([path, methods]) =>
        Object.keys(methods)
          .filter((m) => ['get', 'post', 'put', 'patch', 'delete'].includes(m))
          .map((m) => `${m} ${path}`),
      )
      .sort();
    expect(operations).toEqual(
      ['get /balance', 'get /sender-ids', 'get /sms', 'get /sms/{id}', 'post /otp/send', 'post /otp/verify', 'post /sms', 'post /sms/estimate'].sort(),
    );
  });

  it('defaults to the production server', () => {
    expect(DEFAULT_BASE_URL).toBe(dotted('servers.0.url'));
  });

  it('has the enums of the spec', () => {
    expect(known(MessageStatus)).toEqual(strings('components.schemas.MessageStatus.enum'));
    expect(known(SenderIdStatus)).toEqual(strings('components.schemas.SenderIdStatus.enum'));
    expect(known(RejectionReason)).toEqual(strings('components.schemas.RejectionReason.enum'));
    expect(known(Encoding)).toEqual(strings('components.schemas.Message.properties.encoding.enum'));
    expect(known(Encoding)).toEqual(strings('components.schemas.Estimate.properties.encoding.enum'));
    expect(known(OtpFailureReason)).toEqual(strings('components.schemas.OtpVerifyResult.properties.reason.oneOf.1.enum'));
    expect(known(OtpRateLimitScope)).toEqual(
      strings('components.schemas.OtpRateLimitedError.allOf.1.properties.error.properties.details.properties.scope.enum'),
    );
    expect(Object.values(WebhookEventType)).toEqual(strings('components.schemas.WebhookEnvelope.properties.event.enum'));
    expect(Object.values(WebhookEventType)).toEqual(Object.keys(dotted('webhooks') as Node));
    expect(at(['components', 'schemas', 'SendOtpRequest', 'properties', 'locale', 'enum'])).toEqual(['fr', 'en']);
  });

  it('handles every documented error code', () => {
    // Codes volontairement remontés en ApiError générique.
    const generic = ['HTTP_ERROR', 'INVALID_OTP_TEMPLATE'];
    for (const code of strings('components.schemas.ErrorCode.examples')) {
      expect(code in ERROR_CLASSES_BY_CODE || generic.includes(code), `${code} is not handled`).toBe(true);
    }
  });

  it('enforces the limits of the spec', () => {
    expect(smsLimits.MAX_BODY_LENGTH).toBe(dotted('components.schemas.SendSmsRequest.properties.body.maxLength'));
    expect(smsLimits.MAX_SENDER_LENGTH).toBe(dotted('components.schemas.SendSmsRequest.properties.sender.maxLength'));
    expect(smsLimits.MAX_REFERENCE_LENGTH).toBe(dotted('components.schemas.SendSmsRequest.properties.reference.maxLength'));
    expect(smsLimits.MAX_RECIPIENTS).toBe(dotted('components.schemas.SendSmsRequest.properties.recipients.maxItems'));
    expect(smsLimits.MAX_IDEMPOTENCY_KEY_LENGTH).toBe(dotted('components.parameters.IdempotencyKey.schema.maxLength'));
    expect(smsLimits.MAX_PER_PAGE).toBe(at(['paths', '/sms', 'get', 'parameters', '1', 'schema', 'maximum']));
    expect(otpLimits.MAX_TEMPLATE_LENGTH).toBe(dotted('components.schemas.SendOtpRequest.properties.template.maxLength'));
    expect(otpLimits.MAX_CODE_LENGTH).toBe(dotted('components.schemas.VerifyOtpRequest.properties.code.maxLength'));
  });

  it('uses a Message fixture with exactly the required fields of the spec', () => {
    expect(Object.keys(message()).sort()).toEqual([...strings('components.schemas.Message.required')].sort());
  });

  it('the mocked responses of the unit tests are valid responses of the spec', async () => {
    const fixtures: [string[], unknown][] = [
      [responseSchema('/sms', 'post', '202'), { data: [message(), message({ id: '1f0c7e52-8d3a-4b6e-9c2f-5a7d1e3b9f41', status: 'delivered', delivered_at: '2026-11-05T10:01:00+00:00' })] }],
      [responseSchema('/sms/{id}', 'get', '200'), { data: message({ reference: null, encoding: 'ucs2' }) }],
      [responseSchema('/balance', 'get', '200'), { data: { balance: 120, held: 5 } }],
      [
        responseSchema('/sms/estimate', 'post', '200'),
        { data: { encoding: 'ucs2', characters: 19, segments_per_message: 1, recipients_count: 1, total_credits: 1, balance_after: null } },
      ],
      [
        responseSchema('/sender-ids', 'get', '200'),
        {
          data: [
            { id: '3c9a1f7e-2b4d-4e6a-8f0c-9d1b5e7a3c20', value: 'MYSHOP', status: 'approved', purpose: null, rejection_reason: null, is_default: true, created_at: '2026-10-01T08:00:00+00:00' },
          ],
        },
      ],
      [
        responseSchema('/otp/send', 'post', '201'),
        { data: { otp_id: '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10', expires_at: '2026-11-05T10:05:00+00:00', max_attempts: 3, test_code: '482913' } },
      ],
      [responseSchema('/otp/verify', 'post', '200'), { data: { verified: false, reason: 'max_attempts_reached' } }],
      [['components', 'schemas', 'InsufficientCreditsError'], { error: { code: 'INSUFFICIENT_CREDITS', message: 'x', details: { available: 3, required: 12 } } }],
      [
        ['components', 'schemas', 'RecipientNotSendableError'],
        { error: { code: 'RECIPIENT_NOT_SENDABLE', message: 'x', details: { reason: 'blacklisted', rejected: [{ recipient: '243812345678', reason: 'blacklisted' }] } } },
      ],
      [['components', 'schemas', 'OtpRateLimitedError'], { error: { code: 'OTP_RATE_LIMITED', message: 'x', details: { scope: 'phone', limit: 5 } } }],
      [['components', 'schemas', 'OtpResendTooSoonError'], { error: { code: 'OTP_RESEND_TOO_SOON', message: 'x', details: { retry_after_seconds: 30 } } }],
      [['components', 'schemas', 'ValidationError'], { error: { code: 'VALIDATION_ERROR', message: 'x', details: { body: ['Le champ body est obligatoire.'] } } }],
    ];
    const listFixture = page([message()], { current_page: 1, last_page: 1, total: 1 });
    for (const [path, value] of fixtures) expectValid(path, value);
    expectValid(responseSchema('/sms', 'get', '200'), JSON.parse(await listFixture.text()));
  });

  it('the schemas reject what the SDK must not accept (sanity check of the validator)', () => {
    const validate = ajv.compile({ $ref: `spec#/${pointer(responseSchema('/balance', 'get', '200'))}` });
    expect(validate({ data: { balance: 'x', held: 0 } })).toBe(false);
    expect(validate({ data: { balance: 1 } })).toBe(false);
  });

  it('every request sent by the SDK is valid for the spec', async () => {
    const otpSent = json(201, { data: { otp_id: 'otp-1', expires_at: '2026-11-05T10:05:00+00:00', max_attempts: 3 } });
    const { client, requests } = harness([
      json(202, { data: [message()] }),
      json(202, { data: [message()] }),
      json(200, { data: { encoding: 'gsm7', characters: 5, segments_per_message: 1, recipients_count: 1, total_credits: 1, balance_after: 1 } }),
      otpSent,
      json(200, { data: { verified: true, reason: null } }),
    ]);

    await client.sms.send({ to: '0812345678', body: 'Hello', sender: 'MYSHOP', reference: 'order-1042' });
    await client.sms.sendMany({ recipients: ['0812345678', '+33612345678'], body: 'Hello' });
    await client.sms.estimate({ body: 'Hello', recipients: ['0812345678'] });
    await client.otp.send({ phone: '0812345678', template: 'Code: {code}', locale: 'fr' });
    await client.otp.verify({ otpId: 'otp-1', code: '482913' });

    const byPath = (r: RecordedRequest) => new URL(r.url).pathname.replace('/api/public/v1', '');
    for (const request of requests) {
      expectValid(requestSchema(byPath(request)), request.json);
    }
    const idempotencyKey = requests[0]!.headers['Idempotency-Key']!;
    expect(idempotencyKey.length).toBeLessThanOrEqual(smsLimits.MAX_IDEMPOTENCY_KEY_LENGTH);
  });

  it('parses the OTP verify examples of the spec', async () => {
    const examples = at(responseSchema('/otp/verify', 'post', '200').slice(0, -1).concat('examples')) as Record<string, { value: unknown }>;
    const values = Object.values(examples).map((e) => e.value);
    const { client } = harness(values.map((v) => json(200, v)));

    expect(await client.otp.verify({ otpId: 'otp-1', code: '1' })).toEqual({ verified: true, reason: null });
    expect(await client.otp.verify({ otpId: 'otp-1', code: '1' })).toEqual({ verified: false, reason: 'invalid_code' });
  });

  it('maps the error example of the spec', async () => {
    const example = dotted('components.responses.Unauthenticated.content') as Node;
    const { client } = harness([json(401, (example['application/json'] as { example: unknown }).example)]);
    await expect(client.balance.get()).rejects.toMatchObject({ code: 'UNAUTHENTICATED', status: 401, message: 'Clé API invalide.' });
  });

  it('parses every webhook example of the spec and reads every required data field', async () => {
    const camel = (s: string) => s.replace(/_([a-z])/g, (_m, c: string) => c.toUpperCase());
    for (const [type, item] of Object.entries(dotted('webhooks') as Record<string, Node>)) {
      const content = at(['webhooks', type, 'post', 'requestBody', 'content', 'application/json']) as Node;
      const example = content['example'] ?? { id: '00000000-0000-4000-8000-000000000000', event: type, data: {}, timestamp: 1794046500 };
      expect(item).toBeDefined();
      expectValid(['webhooks', type, 'post', 'requestBody', 'content', 'application/json', 'schema'], example);

      const body = JSON.stringify(example);
      const timestamp = (example as { timestamp: number }).timestamp;
      const event = await Webhooks.constructEvent(body, await Webhooks.sign(body, 'secret'), 'secret', { now: timestamp * 1000 });

      expect(event.event).toBe(type);
      const dataSchema = (at(['webhooks', type, 'post', 'requestBody', 'content', 'application/json', 'schema', 'allOf', '1', 'properties', 'data']) as Node);
      const ref = dataSchema['$ref'];
      if (typeof ref === 'string') {
        const required = at(ref.replace('#/', '').split('/').concat('required')) as string[];
        for (const field of required) {
          expect(Object.keys(event.data), `${type}.${field}`).toContain(camel(field));
        }
      }
    }
  });
});

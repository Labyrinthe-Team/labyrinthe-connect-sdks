// Test de contrat : appelle un backend réel avec une clé de TEST et vérifie
// que chaque réponse (statut + en-têtes communs + corps) est conforme à la spec.
//
//   LABYRINTHE_API_KEY=lsk_test_… [LABYRINTHE_BASE_URL=http://localhost:8000/api/public/v1] npm run test:contract
//
// Refuse une clé live : ce script envoie des SMS et des OTP. Il fait 8 appels
// à `POST /otp/send` (limite : 10 / minute par clé) : attendre une minute
// avant de le relancer.
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';
import { randomUUID } from 'node:crypto';
import { loadSpec } from './lib.mjs';

const BASE_URL = (process.env.LABYRINTHE_BASE_URL ?? 'http://localhost:8000/api/public/v1').replace(/\/$/, '');
const API_KEY = process.env.LABYRINTHE_API_KEY ?? '';
// Numéro fictif au format RDC (aucun SMS réel avec une clé de test).
const PHONE = process.env.LABYRINTHE_TEST_PHONE ?? '243810000001';

if (!API_KEY.startsWith('lsk_test_')) {
  console.error('LABYRINTHE_API_KEY doit être une clé de test (lsk_test_…).');
  process.exit(1);
}

const spec = loadSpec();
const ajv = new Ajv2020({ strict: false, allErrors: true });
addFormats(ajv);
ajv.addSchema(spec, 'openapi');

const pointer = (...parts) => parts.map((p) => String(p).replaceAll('~', '~0').replaceAll('/', '~1')).join('/');
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const ISO_8601 = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/;

async function call(method, path, { body, headers = {}, auth = API_KEY } = {}) {
  const res = await fetch(`${BASE_URL}${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(auth ? { Authorization: `Bearer ${auth}` } : {}),
      ...headers,
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let json;
  try { json = JSON.parse(text); } catch { json = text; }
  return { status: res.status, headers: res.headers, json };
}

let failures = 0;
const results = [];

/** Schéma de réponse documenté : celui de l'opération, ou une réponse commune (`x-common-responses`). */
function responseSchema(specPath, method, status, common) {
  if (common) {
    if (!spec['x-common-responses']?.[String(status)]) return { error: `statut ${status} absent de x-common-responses` };
    return { validate: ajv.getSchema(`openapi#/${pointer('x-common-responses', status, 'content', 'application/json', 'schema')}`) };
  }
  const op = spec.paths?.[specPath]?.[method];
  if (!op) return { error: `opération absente de la spec : ${method.toUpperCase()} ${specPath}` };
  const response = op.responses?.[String(status)];
  if (!response) return { error: `statut ${status} non documenté` };
  const ref = response.$ref
    ? `openapi${response.$ref}/content/application~1json/schema`
    : `openapi#/${pointer('paths', specPath, method, 'responses', status, 'content', 'application/json', 'schema')}`;
  return { validate: ajv.getSchema(ref) };
}

/**
 * Vérifie le statut attendu, sa présence dans la spec, la conformité du corps
 * et les règles communes à toutes les réponses : `X-Request-Id`,
 * `X-Api-Version: 1`, `error.details` toujours un objet.
 */
async function check(name, specPath, method, expectedStatus, request, extra, { common = false } = {}) {
  const urlPath = request.url ?? specPath;
  const res = await call(method.toUpperCase(), urlPath, request);
  const errors = [];

  if (res.status !== expectedStatus) errors.push(`statut ${res.status}, attendu ${expectedStatus} — ${JSON.stringify(res.json).slice(0, 300)}`);

  const { validate, error } = responseSchema(specPath, method, res.status, common);
  if (error) errors.push(error);
  else if (!validate) errors.push('schéma de réponse introuvable');
  else if (!validate(res.json)) errors.push(`corps non conforme : ${ajv.errorsText(validate.errors)}`);

  if (!res.headers.get('x-request-id')) errors.push('en-tête X-Request-Id absent');
  if (res.headers.get('x-api-version') !== '1') errors.push(`X-Api-Version=${res.headers.get('x-api-version')}, attendu 1`);
  if (res.status >= 400) {
    const details = res.json?.error?.details;
    if (details === null || typeof details !== 'object' || Array.isArray(details)) errors.push(`error.details n'est pas un objet : ${JSON.stringify(details)}`);
  }

  if (extra && errors.length === 0) {
    const msg = extra(res.json, res);
    if (msg) errors.push(msg);
  }

  results.push({ name, ok: errors.length === 0, errors });
  if (errors.length) failures++;
  return res;
}

// --- Authentification
await check('sans clé → 401', '/balance', 'get', 401, { auth: '' },
  (j) => (j.error.message === 'Clé API manquante.' ? null : `message=${j.error.message}`));
await check('clé invalide → 401', '/balance', 'get', 401, { auth: 'lsk_test_xxinvalidekey0000000000' });

// --- En-têtes communs, routes inconnues, méthodes
await check('X-Request-Id généré (UUID)', '/balance', 'get', 200, {},
  (_, r) => (UUID.test(r.headers.get('x-request-id')) ? null : `X-Request-Id=${r.headers.get('x-request-id')}`));
const incomingId = `contrat.${randomUUID().slice(0, 8)}:req-1_a`;
await check('X-Request-Id entrant valide réutilisé', '/balance', 'get', 200, { headers: { 'X-Request-Id': incomingId } },
  (_, r) => (r.headers.get('x-request-id') === incomingId ? null : `X-Request-Id=${r.headers.get('x-request-id')}, attendu ${incomingId}`));
await check('X-Request-Id entrant invalide remplacé', '/balance', 'get', 200, { headers: { 'X-Request-Id': 'pas valide !' } },
  (_, r) => (UUID.test(r.headers.get('x-request-id')) ? null : `X-Request-Id=${r.headers.get('x-request-id')}`));
await check('route inconnue → 404 « Route introuvable. »', '/inconnue', 'get', 404, {},
  (j) => (j.error.code === 'NOT_FOUND' && j.error.message === 'Route introuvable.' ? null : JSON.stringify(j.error)), { common: true });
await check('méthode non autorisée → 405 + Allow', '/balance', 'delete', 405, {},
  (j, r) => (j.error.code === 'HTTP_ERROR' && j.error.details.allowed_methods.includes('GET') && (r.headers.get('allow') ?? '').includes('GET')
    ? null : `${JSON.stringify(j.error)} Allow=${r.headers.get('allow')}`), { common: true });

// --- Compte
await check('solde', '/balance', 'get', 200, {});
await check('Sender IDs', '/sender-ids', 'get', 200, {},
  (j) => (j.data.every((s) => s.created_at == null || ISO_8601.test(s.created_at)) ? null : 'created_at hors format ISO 8601'));

// --- Estimation
await check('estimation', '/sms/estimate', 'post', 200, { body: { body: 'Bonjour, votre commande est prête.', recipients: [PHONE, '0899999999'] } },
  (j) => (j.data.recipients_count === 2 ? null : `recipients_count=${j.data.recipients_count}, attendu 2`));
await check('estimation dédoublonnée (formats du même numéro)', '/sms/estimate', 'post', 200,
  { body: { body: 'Bonjour', recipients: ['0812345678', '243812345678', '+243812345678', '812345678', '12'] } },
  (j) => (j.data.recipients_count === 1 && j.data.total_credits === j.data.segments_per_message ? null : JSON.stringify(j.data)));
await check('estimation UCS-2', '/sms/estimate', 'post', 200, { body: { body: 'Réunion — demain 😀', recipients: [PHONE] } },
  (j) => (j.data.encoding === 'ucs2' ? null : `encoding=${j.data.encoding}, attendu ucs2`));
await check('estimation strip_accents → GSM-7', '/sms/estimate', 'post', 200, { body: { body: 'Café « élégant » à Kinshasa', recipients: [PHONE], strip_accents: true } },
  (j) => (j.data.encoding === 'gsm7' ? null : `encoding=${j.data.encoding}, attendu gsm7`));
await check('estimation numéro étranger sans + conservé', '/sms/estimate', 'post', 200, { body: { body: 'Bonjour', recipients: ['33612345678'] } },
  (j) => (j.data.recipients_count === 1 ? null : `recipients_count=${j.data.recipients_count}`));
await check('estimation sans destinataires → 422', '/sms/estimate', 'post', 422, { body: { body: 'x' } });

// --- Envoi
const idem = randomUUID();
const sent = await check('envoi unitaire', '/sms', 'post', 202, { body: { recipient: PHONE, body: 'Test de contrat Labyrinthe' }, headers: { 'Idempotency-Key': idem } },
  (j, r) => {
    if (j.data.length !== 1) return `${j.data.length} messages, attendu 1`;
    if (r.headers.get('idempotent-replayed')) return 'Idempotent-Replayed présent sur un premier envoi';
    return j.data[0].encoding !== null && ISO_8601.test(j.data[0].created_at) ? null : `encoding=${j.data[0].encoding}, created_at=${j.data[0].created_at}`;
  });
const firstId = sent.json?.data?.[0]?.id;
await check('envoi rejoué (Idempotency-Key)', '/sms', 'post', 202, { body: { recipient: PHONE, body: 'Test de contrat Labyrinthe' }, headers: { 'Idempotency-Key': idem } },
  (j, r) => {
    if (j.data[0]?.id !== firstId) return 'idempotence non respectée : nouvel identifiant';
    return r.headers.get('idempotent-replayed') === 'true' ? null : 'Idempotent-Replayed absent';
  });
const idemLocal = randomUUID();
const local = await check('envoi format local (Idempotency-Key)', '/sms', 'post', 202, { body: { recipient: '0810000003', body: 'Rejeu normalisé' }, headers: { 'Idempotency-Key': idemLocal } });
await check('… même numéro au format international → rejeu', '/sms', 'post', 202, { body: { recipient: '243810000003', body: 'Rejeu normalisé' }, headers: { 'Idempotency-Key': idemLocal } },
  (j, r) => (j.data[0]?.id === local.json?.data?.[0]?.id && r.headers.get('idempotent-replayed') === 'true' ? null : 'empreinte non normalisée'));
await check('envoi multiple', '/sms', 'post', 202, { body: { recipients: [PHONE, '0810000002'], body: 'Test multiple' } },
  (j) => (j.data.length === 2 ? null : `${j.data.length} messages, attendu 2`));
await check('envoi numéro étranger sans + → conservé', '/sms', 'post', 202, { body: { recipient: '33612345678', body: 'Bonjour' } },
  (j) => (j.data[0]?.recipient === '33612345678' ? null : `recipient=${j.data[0]?.recipient}`));
await check('envoi sans body → 422 (message en français)', '/sms', 'post', 422, { body: { recipient: PHONE } },
  (j) => (j.error.details.body?.[0] === 'Le champ body est obligatoire.' ? null : JSON.stringify(j.error.details)));
await check('envoi sans destinataire → 422', '/sms', 'post', 422, { body: { body: 'x' } });
await check('numéro invalide → 422 RECIPIENT_NOT_SENDABLE', '/sms', 'post', 422, { body: { recipient: '12', body: 'x' } },
  (j) => (j.error.code === 'RECIPIENT_NOT_SENDABLE' ? null : `code=${j.error.code}`));
await check('Sender ID non approuvé → 422', '/sms', 'post', 422, { body: { recipient: PHONE, body: 'x', sender: 'ZZNOTMINE' } },
  (j) => (j.error.code === 'SENDER_ID_NOT_APPROVED' ? null : `code=${j.error.code}`));

await check('même Idempotency-Key, autre contenu → 409', '/sms', 'post', 409, { body: { recipient: PHONE, body: 'Autre texte' }, headers: { 'Idempotency-Key': idem } },
  (j) => (j.error.code === 'IDEMPOTENCY_KEY_REUSED' ? null : `code=${j.error.code}`));

// Tout ou rien : un destinataire refusé → aucun message pour cette référence.
const ref = `contrat-${randomUUID().slice(0, 8)}`;
await check('envoi multiple avec un refus → 422, rien envoyé', '/sms', 'post', 422, { body: { recipients: [PHONE, '12'], body: 'x', reference: ref } },
  (j) => (Array.isArray(j.error.details.rejected) && j.error.details.rejected.length === 1 ? null : JSON.stringify(j.error.details)));
await check('… et aucun message créé', '/sms', 'get', 200, { url: `/sms?reference=${ref}` },
  (j) => (j.data.length === 0 ? null : `${j.data.length} message(s) créé(s) malgré le refus`));
await check('envoi avec référence', '/sms', 'post', 202, { body: { recipient: PHONE, body: 'Avec reference', reference: ref } },
  (j) => (j.data[0].reference === ref ? null : `reference=${j.data[0].reference}`));
await check('filtre par référence', '/sms', 'get', 200, { url: `/sms?reference=${ref}&per_page=5` },
  (j) => (j.data.length === 1 && j.meta.per_page === 5 ? null : `${j.data.length} résultat(s), per_page=${j.meta.per_page}`));
await check('filtre invalide → 422', '/sms', 'get', 422, { url: '/sms?status=inconnu' });
await check('per_page > 100 → 422 (message en français)', '/sms', 'get', 422, { url: '/sms?per_page=101' },
  (j) => (j.error.details.per_page?.[0] === 'Le champ per_page ne doit pas être supérieur à 100.' ? null : JSON.stringify(j.error.details)));

await check('liste des messages', '/sms', 'get', 200, {});
if (firstId) {
  await check('détail d\'un message', '/sms/{id}', 'get', 200, { url: `/sms/${firstId}` },
    (j) => (j.data.id === firstId && ['gsm7', 'ucs2'].includes(j.data.encoding) ? null : JSON.stringify(j.data)));
}
await check('message inconnu → 404', '/sms/{id}', 'get', 404, { url: `/sms/${randomUUID()}` });

// --- OTP (numéros distincts à chaque exécution pour ne pas buter sur le délai anti-renvoi)
const stamp = String(Date.now()).slice(-7);
const otpPhone = `24381${stamp}`;
const otp = await check('envoi OTP (test_code, forme du code)', '/otp/send', 'post', 201, { body: { phone: otpPhone } },
  (j) => {
    const d = j.data;
    if (typeof d.test_code !== 'string') return 'test_code absent';
    if (d.test_code.length !== d.code_length) return `test_code de ${d.test_code.length} caractères, code_length=${d.code_length}`;
    if (d.code_type === 'numeric' && !/^\d+$/.test(d.test_code)) return `code_type=numeric, test_code non numérique`;
    if (!Number.isInteger(d.resend_after_seconds)) return `resend_after_seconds=${d.resend_after_seconds}`;
    return ISO_8601.test(d.expires_at) ? null : `expires_at=${d.expires_at}`;
  });
await check('renvoi immédiat → 429 OTP_RESEND_TOO_SOON + Retry-After', '/otp/send', 'post', 429, { body: { phone: otpPhone } },
  (j, r) => {
    if (j.error.code !== 'OTP_RESEND_TOO_SOON') return `code=${j.error.code}`;
    const header = r.headers.get('retry-after');
    return header === String(j.error.details.retry_after_seconds) ? null : `Retry-After=${header}, details=${j.error.details.retry_after_seconds}`;
  });
await check('modèle sans {code} → 422', '/otp/send', 'post', 422, { body: { phone: `24382${stamp}`, template: 'Pas de jeton' } },
  (j) => (j.error.code === 'INVALID_OTP_TEMPLATE' ? null : `code=${j.error.code}`));
await check('web_otp_domain invalide → 422', '/otp/send', 'post', 422, { body: { phone: `24382${stamp}`, web_otp_domain: 'https://exemple.com' } },
  (j) => (j.error.code === 'VALIDATION_ERROR' && Array.isArray(j.error.details.web_otp_domain) ? null : JSON.stringify(j.error)));

// Idempotence de /otp/send : rejeu = même otp_id et test_code, sans nouvel envoi.
const otpIdem = randomUUID();
const idemPhone = `24383${stamp}`;
const otpFirst = await check('envoi OTP avec Idempotency-Key (web_otp_domain, strip_accents)', '/otp/send', 'post', 201,
  { body: { phone: idemPhone, web_otp_domain: 'app.exemple.com', strip_accents: true }, headers: { 'Idempotency-Key': otpIdem } },
  (_, r) => (r.headers.get('idempotent-replayed') ? 'Idempotent-Replayed présent sur un premier envoi' : null));
await check('… rejoué (format local du même numéro) → même otp_id', '/otp/send', 'post', 201,
  { body: { phone: `0${idemPhone.slice(3)}`, web_otp_domain: 'app.exemple.com', strip_accents: true }, headers: { 'Idempotency-Key': otpIdem } },
  (j, r) => {
    if (j.data.otp_id !== otpFirst.json?.data?.otp_id || j.data.test_code !== otpFirst.json?.data?.test_code) return 'otp_id ou test_code différent';
    return r.headers.get('idempotent-replayed') === 'true' ? null : 'Idempotent-Replayed absent';
  });
await check('… même clé, autre numéro → 409 IDEMPOTENCY_KEY_REUSED', '/otp/send', 'post', 409, { body: { phone: `24384${stamp}` }, headers: { 'Idempotency-Key': otpIdem } },
  (j) => (j.error.code === 'IDEMPOTENCY_KEY_REUSED' ? null : `code=${j.error.code}`));

const otpId = otp.json?.data?.otp_id;
if (otpId) {
  const maxAttempts = otp.json.data.max_attempts;
  await check('code incorrect → verified=false, attempts_remaining', '/otp/verify', 'post', 200, { body: { otp_id: otpId, code: '00000000' } },
    (j) => (j.data.verified === false && j.data.reason === 'invalid_code' && j.data.attempts_remaining === maxAttempts - 1 ? null : JSON.stringify(j.data)));
  await check('bon code (test_code) → verified=true', '/otp/verify', 'post', 200, { body: { otp_id: otpId, code: otp.json.data.test_code } },
    (j) => (j.data.verified === true && j.data.reason === null && j.data.attempts_remaining === null ? null : JSON.stringify(j.data)));
  await check('code déjà utilisé → already_used', '/otp/verify', 'post', 200, { body: { otp_id: otpId, code: otp.json.data.test_code } },
    (j) => (j.data.reason === 'already_used' && j.data.attempts_remaining === null ? null : JSON.stringify(j.data)));
  await check('renvoi au même numéro juste après une vérification réussie → 201', '/otp/send', 'post', 201, { body: { phone: otpPhone } },
    (j) => (j.data.otp_id !== otpId ? null : 'même otp_id'));
}
await check('otp_id inconnu → 404', '/otp/verify', 'post', 404, { body: { otp_id: randomUUID(), code: '123456' } });
await check('vérification sans champs → 422 (messages en français)', '/otp/verify', 'post', 422, { body: {} },
  (j) => (j.error.details.otp_id?.[0] === 'Le champ otp_id est obligatoire.' ? null : JSON.stringify(j.error.details)));

// --- Rapport
for (const r of results) {
  console.log(`${r.ok ? '✔' : '✘'} ${r.name}`);
  for (const e of r.errors) console.log(`    ${e}`);
}
console.log(`\n${results.length - failures}/${results.length} vérifications conformes.`);
process.exit(failures ? 1 : 0);

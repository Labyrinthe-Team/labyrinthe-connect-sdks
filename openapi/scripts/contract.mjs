// Test de contrat : appelle un backend réel avec une clé de TEST et vérifie
// que chaque réponse (statut + corps) est conforme à la spec.
//
//   LABYRINTHE_API_KEY=lsk_test_… [LABYRINTHE_BASE_URL=http://localhost:8000/api/public/v1] npm run test:contract
//
// Refuse une clé live : ce script envoie des SMS et des OTP.
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
  return { status: res.status, json };
}

let failures = 0;
const results = [];

/** Vérifie le statut attendu, sa présence dans la spec et la conformité du corps. */
async function check(name, specPath, method, expectedStatus, request, extra) {
  const urlPath = request.url ?? specPath;
  const res = await call(method.toUpperCase(), urlPath, request);
  const errors = [];

  if (res.status !== expectedStatus) errors.push(`statut ${res.status}, attendu ${expectedStatus} — ${JSON.stringify(res.json).slice(0, 300)}`);

  const op = spec.paths?.[specPath]?.[method];
  if (!op) errors.push(`opération absente de la spec : ${method.toUpperCase()} ${specPath}`);
  else if (!op.responses?.[String(res.status)]) errors.push(`statut ${res.status} non documenté`);
  else {
    const ref = `openapi#/${pointer('paths', specPath, method, 'responses', res.status, 'content', 'application/json', 'schema')}`;
    const response = op.responses[String(res.status)];
    const resolved = response.$ref ? ajv.getSchema(`openapi${response.$ref}/content/application~1json/schema`) : ajv.getSchema(ref);
    if (!resolved) errors.push('schéma de réponse introuvable');
    else if (!resolved(res.json)) errors.push(`corps non conforme : ${ajv.errorsText(resolved.errors)}`);
  }

  if (extra && errors.length === 0) {
    const msg = extra(res.json);
    if (msg) errors.push(msg);
  }

  results.push({ name, ok: errors.length === 0, errors });
  if (errors.length) failures++;
  return res;
}

// --- Authentification
await check('sans clé → 401', '/balance', 'get', 401, { auth: '' });
await check('clé invalide → 401', '/balance', 'get', 401, { auth: 'lsk_test_xxinvalidekey0000000000' });

// --- Compte
await check('solde', '/balance', 'get', 200, {});
await check('Sender IDs', '/sender-ids', 'get', 200, {});

// --- SMS
await check('estimation', '/sms/estimate', 'post', 200, { body: { body: 'Bonjour, votre commande est prête.', recipients: [PHONE, '0899999999'] } },
  (j) => (j.data.recipients_count === 2 ? null : `recipients_count=${j.data.recipients_count}, attendu 2`));
await check('estimation UCS-2', '/sms/estimate', 'post', 200, { body: { body: 'Réunion — demain 😀', recipients: [PHONE] } },
  (j) => (j.data.encoding === 'ucs2' ? null : `encoding=${j.data.encoding}, attendu ucs2`));
await check('estimation sans destinataires → 422', '/sms/estimate', 'post', 422, { body: { body: 'x' } });

const idem = randomUUID();
const sent = await check('envoi unitaire', '/sms', 'post', 202, { body: { recipient: PHONE, body: 'Test de contrat Labyrinthe' }, headers: { 'Idempotency-Key': idem } },
  (j) => (j.data.length === 1 ? null : `${j.data.length} messages, attendu 1`));
const firstId = sent.json?.data?.[0]?.id;
await check('envoi rejoué (Idempotency-Key)', '/sms', 'post', 202, { body: { recipient: PHONE, body: 'Test de contrat Labyrinthe' }, headers: { 'Idempotency-Key': idem } },
  (j) => (j.data[0]?.id === firstId ? null : 'idempotence non respectée : nouvel identifiant'));
await check('envoi multiple', '/sms', 'post', 202, { body: { recipients: [PHONE, '0810000002'], body: 'Test multiple' } },
  (j) => (j.data.length === 2 ? null : `${j.data.length} messages, attendu 2`));
await check('envoi sans body → 422', '/sms', 'post', 422, { body: { recipient: PHONE } });
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

await check('liste des messages', '/sms', 'get', 200, {});
if (firstId) await check('détail d\'un message', '/sms/{id}', 'get', 200, { url: `/sms/${firstId}` });
await check('message inconnu → 404', '/sms/{id}', 'get', 404, { url: `/sms/${randomUUID()}` });

// --- OTP (numéro distinct à chaque exécution pour ne pas buter sur le délai anti-renvoi)
const otpPhone = `24381${String(Date.now()).slice(-7)}`;
const otp = await check('envoi OTP (test_code présent en mode test)', '/otp/send', 'post', 201, { body: { phone: otpPhone } },
  (j) => (typeof j.data.test_code === 'string' ? null : 'test_code absent'));
await check('renvoi immédiat → 429 OTP_RESEND_TOO_SOON', '/otp/send', 'post', 429, { body: { phone: otpPhone } },
  (j) => (j.error.code === 'OTP_RESEND_TOO_SOON' ? null : `code=${j.error.code}`));
await check('modèle sans {code} → 422', '/otp/send', 'post', 422, { body: { phone: `24382${String(Date.now()).slice(-7)}`, template: 'Pas de jeton' } });
const otpId = otp.json?.data?.otp_id;
if (otpId) {
  await check('code incorrect → verified=false', '/otp/verify', 'post', 200, { body: { otp_id: otpId, code: '00000000' } },
    (j) => (j.data.verified === false && j.data.reason === 'invalid_code' ? null : JSON.stringify(j.data)));
  await check('bon code (test_code) → verified=true', '/otp/verify', 'post', 200, { body: { otp_id: otpId, code: otp.json.data.test_code } },
    (j) => (j.data.verified === true && j.data.reason === null ? null : JSON.stringify(j.data)));
  await check('code déjà utilisé → already_used', '/otp/verify', 'post', 200, { body: { otp_id: otpId, code: otp.json.data.test_code } },
    (j) => (j.data.reason === 'already_used' ? null : JSON.stringify(j.data)));
}
await check('otp_id inconnu → 404', '/otp/verify', 'post', 404, { body: { otp_id: randomUUID(), code: '123456' } });

// --- Rapport
for (const r of results) {
  console.log(`${r.ok ? '✔' : '✘'} ${r.name}`);
  for (const e of r.errors) console.log(`    ${e}`);
}
console.log(`\n${results.length - failures}/${results.length} vérifications conformes.`);
process.exit(failures ? 1 : 0);

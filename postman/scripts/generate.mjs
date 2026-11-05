// Génère la collection Postman v2.1 depuis ../openapi/openapi.yaml (openapi-to-postmanv2),
// puis l'enrichit : variables, auth Bearer, scripts de test, enchaînement send → get / verify.
//
//   npm run generate   → écrit labyrinthe-connect.postman_collection.json
//   npm run check      → régénère en mémoire, échoue si le fichier versionné n'est pas à jour
//
// La sortie est déterministe : Math.random est remplacé par un générateur à graine fixe pendant
// la conversion (valeurs d'exemple factices), et tous les identifiants sont dérivés d'un hash stable.
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { parse } from 'yaml';

const root = new URL('..', import.meta.url);
const specPath = fileURLToPath(new URL('../openapi/openapi.yaml', root));
const outputPath = fileURLToPath(new URL('labyrinthe-connect.postman_collection.json', root));
const check = process.argv.includes('--check');

const specText = readFileSync(specPath, 'utf8');
const spec = parse(specText);

// Ordre d'exécution du parcours nominal, par operationId (send avant get, send OTP avant verify).
const ORDER = ['sendSms', 'listSms', 'getSms', 'estimateSms', 'getBalance', 'listSenderIds', 'sendOtp', 'verifyOtp'];

const collection = await convert(specText);
enrich(collection);
const output = `${JSON.stringify(sortKeys(collection), null, 2)}\n`;

if (check) {
  let current = '';
  try {
    current = readFileSync(outputPath, 'utf8').replace(/\r\n/g, '\n');
  } catch {
    // fichier absent : comparé à une chaîne vide
  }
  if (current !== output) {
    console.error('labyrinthe-connect.postman_collection.json is out of date: run `npm run generate` and commit the result.');
    process.exit(1);
  }
  console.log('Postman collection is up to date.');
} else {
  writeFileSync(outputPath, output);
  console.log(`Wrote ${outputPath}`);
}

// ---------------------------------------------------------------------------

async function convert(data) {
  const random = Math.random;
  const warn = console.warn;
  // Graine fixée AVANT le chargement du convertisseur : son générateur de valeurs factices capture Math.random.
  Math.random = seededRandom(0x1abc0de);
  const { default: Converter } = await import('openapi-to-postmanv2');
  // Le convertisseur journalise des avertissements de fusion allOf (schémas d'erreur) : ignorés,
  // ils ne touchent que les exemples de réponses d'erreur.
  console.warn = () => {};
  // Les dates factices sont calculées depuis « maintenant » : horloge figée pendant la conversion.
  const RealDate = Date;
  const FIXED_NOW = RealDate.UTC(2026, 10, 6);
  globalThis.Date = class extends RealDate {
    constructor(...args) {
      super(...(args.length === 0 ? [FIXED_NOW] : args));
    }

    static now() {
      return FIXED_NOW;
    }
  };
  try {
    const result = await new Promise((resolve, reject) => {
      Converter.convert(
        { type: 'string', data },
        {
          folderStrategy: 'Tags',
          requestParametersResolution: 'Example',
          exampleParametersResolution: 'Example',
          parametersResolution: 'Example',
          includeAuthInfoInExample: false,
          optimizeConversion: false,
          stackLimit: 10,
        },
        (error, res) => (error ? reject(error) : resolve(res)),
      );
    });
    if (!result.result) {
      throw new Error(`Conversion failed: ${result.reason}`);
    }
    return result.output[0].data;
  } finally {
    Math.random = random;
    console.warn = warn;
    globalThis.Date = RealDate;
  }
}

function enrich(c) {
  c.info._postman_id = stableId('collection');
  c.info.name = 'Labyrinthe Connect API';
  c.info.description = {
    type: 'text/markdown',
    content: [
      `Generated from \`openapi/openapi.yaml\` (spec ${spec.info.version}). Do not edit by hand: run \`npm run generate\` in \`postman/\`.`,
      '',
      'Set the `apiKey` variable (secret) to a **test key** (`lsk_test_…`): nothing is sent, nothing is billed, and',
      '`POST /otp/send` returns `test_code`, saved automatically so that `POST /otp/verify` can be run right after.',
      'Run the whole collection in order (Collection Runner or Newman) to play the nominal flow.',
      '',
      '---',
      '',
      spec.info.description.trim(),
    ].join('\n'),
  };

  c.variable = [
    { key: 'baseUrl', value: 'http://localhost:8000/api/public/v1', type: 'string', description: 'API root (see the environments).' },
    { key: 'apiKey', value: '', type: 'secret', description: 'lsk_test_… (sandbox) or lsk_live_…. Never commit it.' },
    { key: 'messageId', value: '', type: 'string', description: 'Set by "POST /sms", used by "GET /sms/:id".' },
    { key: 'otpPhone', value: '', type: 'string', description: 'Random DRC number generated before "POST /otp/send".' },
    { key: 'otpId', value: '', type: 'string', description: 'Set by "POST /otp/send", used by "POST /otp/verify".' },
    { key: 'otpTestCode', value: '', type: 'secret', description: 'test_code returned with a test key only.' },
  ];
  c.auth = { type: 'bearer', bearer: [{ key: 'token', value: '{{apiKey}}', type: 'string' }] };
  c.event = [];

  const operations = operationsByRoute();

  for (const folder of c.item) {
    folder.id = stableId(`folder:${folder.name}`);
    const tag = spec.tags.find((t) => t.name === folder.name);
    if (tag) folder.description = { type: 'text/markdown', content: tag.description.trim() };

    for (const item of folder.item) {
      const route = `${item.request.method} /${item.request.url.path.map((p) => (p.startsWith(':') ? `{${p.slice(1)}}` : p)).join('/')}`;
      const op = operations.get(route);
      if (!op) throw new Error(`Unknown operation for ${route}`);
      item.id = stableId(`item:${op.operationId}`);
      item.name = `${route.replace('{id}', ':id')} — ${op.summary}`;
      // Auth héritée de la collection.
      delete item.request.auth;
      item.request.auth = undefined;
      for (const [index, response] of (item.response ?? []).entries()) {
        response.id = stableId(`response:${op.operationId}:${index}`);
      }
      item.event = buildEvents(op.operationId, expectedStatus(op.operation));
      customize(op.operationId, item.request);
      item._order = ORDER.indexOf(op.operationId);
    }
    folder.item.sort((a, b) => a._order - b._order);
    for (const item of folder.item) delete item._order;
  }
}

function customize(operationId, request) {
  switch (operationId) {
    case 'sendSms':
      setHeader(request, 'Idempotency-Key', '{{$guid}}');
      break;
    case 'listSms':
      // Filtres facultatifs : désactivés par défaut (sauf la pagination).
      for (const q of request.url.query) {
        q.disabled = !['page', 'per_page'].includes(q.key);
        if (q.key === 'recipient') q.value = '243812345678';
        if (q.key === 'reference') q.value = 'RAPPEL-2026-11-06';
      }
      break;
    case 'getSms':
      for (const v of request.url.variable) if (v.key === 'id') v.value = '{{messageId}}';
      break;
    case 'sendOtp':
      setBody(request, { phone: '{{otpPhone}}', locale: 'fr' });
      break;
    case 'verifyOtp':
      setBody(request, { otp_id: '{{otpId}}', code: '{{otpTestCode}}' });
      break;
    default:
  }
}

function buildEvents(operationId, status) {
  const tests = [
    `pm.test('HTTP ${status}', function () {`,
    `  pm.response.to.have.status(${status});`,
    '});',
    "pm.test('JSON body with data', function () {",
    "  pm.expect(pm.response.json()).to.have.property('data');",
    '});',
  ];
  const prerequest = [];

  switch (operationId) {
    case 'sendSms':
      tests.push(
        'const messages = pm.response.json().data || [];',
        "pm.test('At least one message', function () { pm.expect(messages.length).to.be.above(0); });",
        "if (messages[0]) { pm.collectionVariables.set('messageId', messages[0].id); }",
      );
      break;
    case 'getSms':
      tests.push(
        "pm.test('Same message', function () { pm.expect(pm.response.json().data.id).to.eql(pm.collectionVariables.get('messageId')); });",
      );
      break;
    case 'sendOtp':
      prerequest.push(
        '// Numéro RDC aléatoire : les limites OTP par numéro ne se croisent pas entre deux exécutions.',
        "pm.collectionVariables.set('otpPhone', '0899' + String(Math.floor(Math.random() * 1e6)).padStart(6, '0'));",
      );
      tests.push(
        'const otp = pm.response.json().data || {};',
        "pm.test('otp_id returned', function () { pm.expect(otp.otp_id).to.be.a('string'); });",
        "pm.collectionVariables.set('otpId', otp.otp_id || '');",
        '// test_code n\'existe qu\'avec une clé lsk_test_ (aucun SMS envoyé).',
        "pm.collectionVariables.set('otpTestCode', otp.test_code || '');",
      );
      break;
    case 'verifyOtp':
      tests.push(
        "if (pm.collectionVariables.get('otpTestCode')) {",
        "  pm.test('Code verified (test mode)', function () { pm.expect(pm.response.json().data.verified).to.eql(true); });",
        '}',
      );
      break;
    default:
  }

  const events = [];
  if (prerequest.length > 0) {
    events.push({ listen: 'prerequest', script: { id: stableId(`pre:${operationId}`), type: 'text/javascript', exec: prerequest } });
  }
  events.push({ listen: 'test', script: { id: stableId(`test:${operationId}`), type: 'text/javascript', exec: tests } });
  return events;
}

function operationsByRoute() {
  const map = new Map();
  for (const [path, methods] of Object.entries(spec.paths)) {
    for (const [method, operation] of Object.entries(methods)) {
      map.set(`${method.toUpperCase()} ${path}`, { operationId: operation.operationId, summary: operation.summary, operation });
    }
  }
  return map;
}

function expectedStatus(operation) {
  const code = Object.keys(operation.responses).find((c) => /^2\d\d$/.test(c));
  if (!code) throw new Error(`No 2xx response for ${operation.operationId}`);
  return Number(code);
}

function setHeader(request, key, value) {
  const header = request.header.find((h) => h.key.toLowerCase() === key.toLowerCase());
  if (header) {
    header.value = value;
    header.disabled = false;
  } else {
    request.header.push({ key, value });
  }
}

function setBody(request, body) {
  request.body = { mode: 'raw', raw: JSON.stringify(body, null, 2), options: { raw: { language: 'json' } } };
}

function stableId(seed) {
  const hex = createHash('sha256').update(`labyrinthe-connect:${seed}`).digest('hex');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-4${hex.slice(13, 16)}-a${hex.slice(17, 20)}-${hex.slice(20, 32)}`;
}

function seededRandom(seed) {
  // mulberry32
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

// Clés triées pour une sortie stable ; l'ordre des tableaux (dossiers, requêtes, scripts) est conservé.
function sortKeys(value) {
  if (Array.isArray(value)) return value.map(sortKeys);
  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.keys(value)
        .filter((k) => value[k] !== undefined)
        .sort()
        .map((k) => [k, sortKeys(value[k])]),
    );
  }
  return value;
}

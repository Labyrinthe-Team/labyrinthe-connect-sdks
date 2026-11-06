# Labyrinthe Connect — SDK Python

SDK Python officiel de [Labyrinthe Connect](https://labyrinthe-rdc.com) : envoyez des SMS et vérifiez des numéros de
téléphone par code à usage unique (OTP), en RDC et en Afrique centrale.

*English version: [README.md](README.md).*

- Clients synchrone **et** asyncio, avec la même API. Typé à 100 % (`py.typed`, mypy `--strict`).
- Dataclasses gelées, enums `str`, `datetime` avec fuseau, une exception par erreur.
- Sûr par défaut : relances automatiques **uniquement** quand elles ne peuvent pas envoyer deux fois un SMS.
- Une seule dépendance : [`httpx`](https://www.python-httpx.org/). Intégrations Django, FastAPI et Flask facultatives.
- Ne journalise ni n'affiche jamais votre clé API, les codes OTP ou les numéros complets.

## Installation

```bash
pip install labyrinthe-connect
pip install "labyrinthe-connect[django]"     # ou [fastapi], [flask]
```

## Démarrage en 5 lignes

```python
from labyrinthe_connect import Labyrinthe

client = Labyrinthe(api_key="lsk_test_…")            # ou la variable LABYRINTHE_API_KEY
message = client.sms.send(to="0812345678", body="Votre commande 1042 est prête.")
print(message.status)                                 # queued
```

Créez vos clés dans l'espace client (*Développeurs > Clés API*). **Gardez-les côté serveur**, jamais dans un
navigateur ni une application mobile.

| Clé | Mode | Effet |
|---|---|---|
| `lsk_test_…` | Bac à sable | Rien n'est envoyé, aucun crédit consommé. Mêmes réponses qu'en production. L'envoi d'OTP renvoie le code (`test_code`). |
| `lsk_live_…` | Production | Les SMS partent réellement et consomment des crédits. |

`client.is_test_mode` indique le mode utilisé. Tous les exemples ci-dessous fonctionnent avec une clé de test.

### Synchrone et asynchrone

```python
from labyrinthe_connect import AsyncLabyrinthe, Labyrinthe

with Labyrinthe() as client:                          # ferme les connexions HTTP à la sortie
    client.balance.get()

async with AsyncLabyrinthe() as client:               # mêmes méthodes, avec await
    await client.balance.get()
```

## Envoyer des SMS

```python
message = client.sms.send(
    to="+243 81 234 5678",       # +243…, 00243…, 243…, 0812…, 812…, 33612345678 sont acceptés
    body="Votre commande 1042 est prête.",
    sender="MABOUTIQUE",         # facultatif : un Sender ID approuvé (client.sender_ids.list())
    reference="commande-1042",   # facultatif : votre référence, 64 caractères max.
)
message.id, message.recipient, message.status, message.segments, message.credits_charged, message.created_at
```

L'envoi est asynchrone : `202 Accepted` signifie *mis en file et payé*, pas *remis*. Suivez le statut par webhook
(`message.status_changed`, recommandé) ou avec `client.sms.get(message.id)` (`status.is_final`, `status.is_failed`).

### Coût avant l'envoi

1 crédit = 1 segment. Un message ASCII (GSM-7) tient en 160 caractères (153 par segment au-delà). **Tout caractère
non ASCII** (é, à, ç, emoji, guillemets typographiques…) passe en UCS-2 : 70 caractères (67 par segment au-delà).

```python
estimate = client.sms.estimate(body="Réunion demain à 9h", recipients=["0812345678", "0899999999"])
estimate.encoding, estimate.total_credits, estimate.is_affordable
```

`strip_accents=True` (sur `send`, `send_many`, `estimate` et `otp.send`) demande à l'API de remplacer accents et
caractères typographiques par leur équivalent ASCII : le message repasse en GSM-7, souvent deux fois moins cher. La
translittération dépend du serveur (`Café` peut devenir `Cafe` ou `Caf'e`). L'estimation compte les destinataires
comme l'envoi (normalisés, doublons fusionnés).

### Plusieurs destinataires : tout ou rien

```python
from labyrinthe_connect import RecipientNotSendableError

try:
    batch = client.sms.send_many(recipients=["0812345678", "0899999999"], body="Réunion demain à 9h.")
except RecipientNotSendableError as error:
    # RIEN n'a été envoyé : corrigez la liste et recommencez.
    for rejected in error.rejected:
        print(rejected.recipient, rejected.reason)    # invalid_phone, blacklisted, opted_out
```

Jusqu'à 1000 destinataires par appel. Destinataires, Sender ID et solde sont vérifiés **avant** tout envoi : un seul
refus (ou un solde insuffisant) et aucun SMS ne part. Les numéros manifestement invalides sont refusés localement
(`InvalidArgumentError`, avec leur seule position), sans appel réseau.

### Idempotence (jamais de double envoi)

Chaque envoi porte un `Idempotency-Key`. Sans clé fournie, le SDK génère un `uuid4` et le réutilise pour ses propres
relances : une erreur réseau ne provoque jamais un second SMS. Pour vous protéger aussi de **vos** relances (files de
tâches, double clic, plantage), passez une clé tirée de votre événement métier ; un rejeu dans les 24 h renvoie les
messages d'origine sans rien envoyer ni débiter :

```python
batch = client.sms.send_many(recipients=["0812345678"], body="Commande 1042 prête.",
                             idempotency_key="commande-1042-prete")
batch.replayed   # True si c'était un rejeu
```

La même clé avec un autre contenu est refusée : `IdempotencyKeyReusedError` (409). L'empreinte porte sur les
destinataires normalisés et dédoublonnés (`0812345678` puis `243812345678` avec la même clé = rejeu). Si la requête
d'origine est encore en cours (`409 IDEMPOTENCY_REQUEST_IN_PROGRESS`), le SDK attend `Retry-After` (5 s) et relance
avec la même clé ; ses relances épuisées, il lève `IdempotencyRequestInProgressError` (relancer reste sans risque).

Si la requête d'origine a échoué de façon inattendue côté API, son résultat est inconnu (un SMS ou un code OTP a pu
partir) et tout rejeu avec cette clé reçoit `409 IDEMPOTENCY_KEY_UNRESOLVED`, levée en `IdempotencyKeyUnresolvedError`.
Le SDK ne la relance jamais, et **vous ne devez pas la relancer automatiquement** non plus :

1. vérifiez ce qui s'est passé : `client.sms.list(reference="commande-1042")` pour un SMS (passez une `reference` pour
   cela), ou demandez à l'utilisateur s'il a reçu un code pour un OTP ;
2. seulement si rien n'est parti, renvoyez avec une **nouvelle** `Idempotency-Key` (l'ancienne reste non résolue).

```python
from labyrinthe_connect import IdempotencyKeyUnresolvedError

try:
    client.sms.send(to="0812345678", body="Commande prête", reference="commande-1042",
                    idempotency_key="commande-1042-prete")
except IdempotencyKeyUnresolvedError as error:
    log.warning("Résultat inconnu, requête %s : vérifier avant de renvoyer", error.request_id)
    deja_envoye = client.sms.list(reference="commande-1042", per_page=1).data
```

### Lister les messages

```python
page = client.sms.list(status="failed", from_=date(2026, 11, 1), per_page=50)
for message in client.sms.list(reference="commande-1042").auto_paging_iter():   # toutes les pages
    ...
async for message in async_client.sms.list(reference="commande-1042").auto_paging_iter():
    ...
```

Filtres : `status`, `recipient`, `reference`, `from_`, `to` (une `date` couvre la journée ; un `datetime` doit avoir
un fuseau), `page`, `per_page` (100 max.). Seuls les messages envoyés **avec la clé appelante** sont listés.

### Solde et Sender IDs

```python
client.balance.get()        # balance, held
client.sender_ids.list()    # noms approuvés, utilisables comme `sender`
```

## OTP : vérifier un numéro

```python
from labyrinthe_connect import OtpFailureReason

# 1. Envoyer le code. Garder otp_id CÔTÉ SERVEUR (session), jamais dans le navigateur.
otp = client.otp.send(phone="0812345678")
request.session["otp_id"] = otp.otp_id
otp.code_length, otp.code_type      # 6, OtpCodeType.NUMERIC (ou ALPHANUMERIC) : choisir le bon champ de saisie
otp.resend_after_seconds            # 60 : compte à rebours avant « Renvoyer le code »

# 2. L'utilisateur saisit le code reçu par SMS.

# 3. Vérifier.
result = client.otp.verify(otp_id=request.session["otp_id"], code=code_saisi)
if result.verified:
    del request.session["otp_id"]          # connecter l'utilisateur
elif result.reason is OtpFailureReason.INVALID_CODE:
    ...                                     # mauvais code : result.attempts_remaining essais restants
else:
    ...                                     # EXPIRED, ALREADY_USED, MAX_ATTEMPTS_REACHED : redemander un code
```

- Un mauvais code n'est **pas** une exception : `verified=False` et un motif.
- Un nouvel envoi au même numéro invalide le code précédent.
- Longueur, validité, tentatives, Sender ID et modèle viennent des paramètres OTP du compte ; `template` (doit
  contenir `{code}`) et `locale` (`"fr"` ou `"en"`) permettent de les surcharger.
- `attempts_remaining` : entier après `invalid_code`, `0` avec `max_attempts_reached`, `None` si vérifié, déjà
  utilisé ou expiré.
- `web_otp_domain="app.exemple.com"` ajoute la ligne `@app.exemple.com #123456` de la
  [Web OTP API](https://developer.mozilla.org/docs/Web/API/WebOTP_API) (remplissage automatique sous Chrome,
  Android) ; `strip_accents=True` garde le SMS en GSM-7.
- Limites : `OtpResendTooSoonError` (attendre `retry_after` secondes ; un renvoi est accepté tout de suite après une
  vérification réussie), `OtpRateLimitedError` (`scope`, `limit`, `retry_after`).
- **Mode test** : avec une clé `lsk_test_`, aucun SMS n'est envoyé et `otp.test_code` contient le code pour tester
  le parcours complet. Toujours `None` en live ; masqué dans `repr()` et retiré lors d'un pickle.

Comme les SMS, chaque `otp.send()` porte un `Idempotency-Key` (un `uuid4`, ou le vôtre : `idempotency_key=`)
réutilisé par les relances du SDK : un rejeu renvoie le même `otp_id` (et le même `test_code` en mode test) sans
second SMS, avec `otp.replayed` à `True`. `otp.send()` est donc relancé après une erreur réseau, une 5xx, un
`TOO_MANY_REQUESTS` ou un `IDEMPOTENCY_REQUEST_IN_PROGRESS`, jamais après `OTP_RESEND_TOO_SOON` ni `OTP_RATE_LIMITED`.

## Webhooks

Les événements arrivent en `POST` JSON, signés (`X-Signature: sha256=<HMAC-SHA256 du corps brut>`). Le SDK vérifie
la signature en temps constant (`hmac.compare_digest`) sur le **corps brut**, puis l'horodatage signé (5 min par
défaut, anti-rejeu).

```python
from labyrinthe_connect import MessageStatusChanged, webhooks

event = webhooks.construct_event(corps_brut, headers.get("X-Signature"), secret)
if isinstance(event, MessageStatusChanged):
    maj_message(event.data.message_id, event.data.status)
```

Événements : `MessageStatusChanged` (dont `reference`, `error_code`, `error_message`), `CampaignCompleted`,
`BalanceLow`, `OtpVerified` et `OtpFailed` (`otp_id`, `phone`, `status`, `reason`, `verified_at` ; jamais le code),
`TestPing`, `UnknownEvent` (type ajouté après cette version : répondre 2xx). Dédoublonnez sur `event.id` (jusqu'à 5 tentatives, ordre non garanti).
`SignatureVerificationError` / `InvalidWebhookPayloadError` : répondre 400. Secret par défaut des intégrations :
`LABYRINTHE_WEBHOOK_SECRET`.

### Django

```python
from labyrinthe_connect.contrib.django import WebhookView, message_status_changed
urlpatterns = [path("webhooks/labyrinthe/", WebhookView.as_view())]   # settings.LABYRINTHE_WEBHOOK_SECRET

@receiver(message_status_changed)
def on_status(sender, event, **kwargs): ...
```

Signaux : `webhook_received`, `message_status_changed`, `campaign_completed`, `balance_low`, `otp_verified`,
`otp_failed`, `test_ping`, `unknown_event`.

### FastAPI

```python
from labyrinthe_connect.contrib.fastapi import WebhookVerifier, create_webhook_router

@app.post("/webhooks/labyrinthe")
async def webhook(event: WebhookEvent = Depends(WebhookVerifier())):
    return {"received": True}

app.include_router(create_webhook_router(on_event=traiter))   # ou une route prête à l'emploi
```

### Flask

```python
from labyrinthe_connect.contrib.flask import create_webhook_blueprint
app.register_blueprint(create_webhook_blueprint(traiter))     # POST /webhooks/labyrinthe ; signal webhook_received
```

## Erreurs

Toutes héritent de `LabyrintheError` (`code`, `message`, `status`, `details` toujours un `dict`, `request_id`) ;
les erreurs API de `ApiError` (`headers`, `api_version`). Même hiérarchie que les SDK PHP et Node :
`AuthenticationError` (401), `PermissionError` (403), `AccountSuspendedError` (403 `ACCOUNT_SUSPENDED`, compte
suspendu, sous-classe de `PermissionError`), `NotFoundError` (404), `ValidationError` (`field_errors`), `RecipientNotSendableError` (`rejected`),
`SenderIdNotApprovedError`, `InvalidPhoneError`, `InsufficientCreditsError` (`available`, `required`),
`IdempotencyKeyReusedError`, `IdempotencyRequestInProgressError` (`retry_after`), `IdempotencyKeyUnresolvedError` (résultat inconnu, jamais relancée), `RateLimitError` (`retry_after`), `OtpResendTooSoonError`, `OtpRateLimitedError`,
`ServiceUnavailableError`, `ApiError` (code inconnu), `APIConnectionError`, `APITimeoutError`,
`InvalidArgumentError`, `UnexpectedResponseError`. Testez `code`, jamais `message` (en français, pour les humains).

### Identifiant de requête

Chaque réponse porte l'en-tête `X-Request-Id`, à communiquer au support : le SDK l'expose en `request_id` sur chaque
erreur et sur chaque objet renvoyé (`Message`, `MessageBatch`, `Estimate`, `Balance`, `SenderId`, `OtpSendResult`,
`OtpVerifyResult`, pages ; hors égalité et `repr()`). Chaque méthode accepte `request_id=` pour envoyer le vôtre
(1 à 128 caractères parmi `A-Z a-z 0-9 . _ : -`, vérifié localement), réutilisé par chaque relance et renvoyé par
l'API — y compris sur une erreur réseau.

## Relances

2 relances par défaut, backoff exponentiel 0,5 s → 1 s → 2 s avec jitter, **uniquement sans risque** :

| Requête | Erreur réseau / timeout | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` |
|---|---|---|---|---|
| lectures, `estimate` | relancée | relancée | après `Retry-After` | — |
| `send` / `send_many` / `otp.send` (toujours avec `Idempotency-Key`) | relancée, même clé | relancée, même clé | après `Retry-After` | après `Retry-After`, même clé |
| `otp.verify` | **jamais** | **jamais** | relancée | — |

Jamais de relance sur une autre erreur métier (4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, `IDEMPOTENCY_KEY_UNRESOLVED`). Au-delà de
`max_retry_wait` (10 s), `RateLimitError` est levée sans attendre. Les redirections ne sont jamais suivies.

## Configuration

`Labyrinthe(api_key=..., base_url=..., timeout=30.0, max_retries=2, max_retry_wait=10.0, default_country_code="243",
default_sender=None, http_client=None)` — clé et URL lues par défaut dans `LABYRINTHE_API_KEY` et
`LABYRINTHE_BASE_URL` (un hôte seul est complété par `/api/public/v1`). Journalisation via le logger
`labyrinthe_connect` (clé, codes, corps et numéros complets jamais journalisés). `repr(client)` affiche
`lsk_test_****` ; un client ne se « pickle » pas. `phone.normalize()` applique les règles de l'API : sans `+`,
11 chiffres ou plus ne commençant pas par 0 = déjà international (`33612345678` reste tel quel) ; sinon numéro local
complété par l'indicatif par défaut. Pour un numéro étranger court (10 chiffres ou moins), toujours utiliser le `+`.
Les requêtes envoient le numéro normalisé au format E.164 avec son `+` (`+243812345678`) : un numéro étranger court
garde ainsi son indicatif.

## Développement

```bash
pip install -r requirements-dev.txt -e .
ruff check . && ruff format --check . && mypy && pytest
python -m build && twine check --strict dist/*
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 pytest -m integration
```

## Licence

MIT — voir [LICENSE](LICENSE).

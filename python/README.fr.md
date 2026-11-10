# Labyrinthe Connect — SDK Python

[![CI](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/python.yml/badge.svg)](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/python.yml)
[![PyPI](https://img.shields.io/pypi/v/labyrinthe-connect.svg)](https://pypi.org/project/labyrinthe-connect/)
![Python](https://img.shields.io/badge/python-3.9%20%E2%86%92%203.13-3776AB)
![Typé](https://img.shields.io/badge/typing-mypy%20strict-2A6DB2)
[![Licence : MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

SDK Python officiel de [Labyrinthe Connect](https://connect.labyrinthe-rdc.com) : envoyez des SMS et vérifiez des numéros de
téléphone par code à usage unique (OTP), en RDC et en Afrique centrale.

*English version: [README.md](README.md).*

- Clients synchrone **et** asyncio, avec la même API. Typé à 100 % (`py.typed`, mypy `--strict`).
- Dataclasses gelées, enums `str`, `datetime` avec fuseau, une exception par erreur.
- Sûr par défaut : relances automatiques **uniquement** quand elles ne peuvent pas envoyer deux fois un SMS.
- Une seule dépendance : [`httpx`](https://www.python-httpx.org/). Intégrations Django, FastAPI et Flask facultatives.
- Ne journalise ni n'affiche jamais votre clé API, les codes OTP ou les numéros complets.

SDK **1.2.1**, construit sur la spécification de l'API **1.3.1** ([`openapi/openapi.yaml`](../openapi/openapi.yaml)).

## Sommaire

- [Prérequis](#prérequis) · [Installation](#installation) · [Démarrage rapide](#démarrage-rapide) ·
  [Configuration](#configuration)
- [Envoyer des SMS](#envoyer-des-sms) · [Solde et Sender IDs](#solde-et-sender-ids) ·
  [OTP](#otp--vérifier-un-numéro) · [Webhooks](#webhooks)
- [Erreurs](#erreurs) · [Relances et idempotence](#relances-et-idempotence) · [Mode test](#mode-test) ·
  [Journalisation et sécurité](#journalisation-et-sécurité) · [Identifiants de requête](#identifiants-de-requête) ·
  [Typage](#typage)
- [Sans le package (HTTP brut)](#sans-le-package-http-brut) · [Mise à jour](#mise-à-jour) ·
  [Contribuer](#contribuer) · [Licence](#licence)

## Prérequis

- Python **3.9 à 3.13** (CPython).
- `httpx` 0.25 ou plus récent (installé automatiquement).
- Facultatif : Django 3.2+, FastAPI 0.95+ ou Flask 2.3+ pour les intégrations de webhooks.
- Une clé API Labyrinthe Connect, créée dans l'espace client (*Développeurs > Clés API*). Commencez avec une **clé de
  test** (`lsk_test_…`) : rien n'est envoyé, rien n'est facturé.

## Installation

```bash
pip install labyrinthe-connect
pip install "labyrinthe-connect[django]"        # ou [fastapi], [flask]

uv add labyrinthe-connect                       # uv
uv add "labyrinthe-connect[fastapi]"

poetry add labyrinthe-connect                   # Poetry
poetry add "labyrinthe-connect[flask]"
```

## Démarrage rapide

```python
from labyrinthe_connect import Labyrinthe

client = Labyrinthe(api_key="lsk_test_…")            # ou la variable LABYRINTHE_API_KEY
message = client.sms.send(to="0812345678", body="Votre commande 1042 est prête.")
print(message.status)                                 # queued
```

**Gardez vos clés côté serveur**, jamais dans un navigateur ni une application mobile.

| Clé | Mode | Effet |
|---|---|---|
| `lsk_test_…` | Bac à sable | Rien n'est envoyé, aucun crédit consommé. Mêmes réponses qu'en production. L'envoi d'OTP renvoie le code (`test_code`). |
| `lsk_live_…` | Production | Les SMS partent réellement et consomment des crédits. |

`client.is_test_mode` (et `client.is_live_mode`) indique le mode utilisé. Tous les exemples ci-dessous fonctionnent
avec une clé de test.

### Synchrone et asynchrone

```python
from labyrinthe_connect import AsyncLabyrinthe, Labyrinthe

with Labyrinthe() as client:                          # ferme les connexions HTTP à la sortie
    client.balance.get()

async with AsyncLabyrinthe() as client:               # mêmes méthodes, avec await
    await client.balance.get()
```

`AsyncLabyrinthe` a exactement les mêmes ressources et paramètres (`await client.sms.send(...)`,
`await client.otp.verify(...)`…). Fermez-le avec `async with` ou `await client.aclose()`, et le client synchrone avec
`with` ou `client.close()`. Créez un client par processus et réutilisez-le : il garde les connexions HTTP ouvertes.

## Configuration

```python
import os

import httpx
from labyrinthe_connect import Labyrinthe

client = Labyrinthe(
    api_key=os.environ["LABYRINTHE_API_KEY"],   # par défaut : la variable d'environnement LABYRINTHE_API_KEY
    base_url="https://api.connect.labyrinthe-rdc.com/api/public/v1",  # défaut (ou LABYRINTHE_BASE_URL) ; un hôte nu est complété
    timeout=30.0,                # secondes, par tentative
    max_retries=2,               # 0 désactive les relances (10 max.)
    max_retry_wait=10.0,         # plus long Retry-After que le SDK accepte d'attendre
    default_country_code="243",  # ajouté aux numéros locaux : 0812345678 → 243812345678
    default_sender="MABOUTIQUE", # utilisé quand send() / send_many() ne reçoivent pas de sender
    http_client=httpx.Client(proxy="http://proxy:3128"),   # votre propre client (jamais fermé par le SDK)
)
```

| Variable d'environnement | Rôle |
|---|---|
| `LABYRINTHE_API_KEY` | clé API quand `api_key` n'est pas passé |
| `LABYRINTHE_BASE_URL` | racine de l'API quand `base_url` n'est pas passé (ex. `http://localhost:8000`, complété avec `/api/public/v1`) |
| `LABYRINTHE_WEBHOOK_SECRET` | secret de webhook des intégrations Django, FastAPI et Flask |

`client.base_url` renvoie la racine de l'API utilisée. Chaque méthode accepte aussi `timeout=` (secondes, par
tentative) et `request_id=` pour un seul appel. Le client asynchrone prend un `httpx.AsyncClient` comme `http_client`.

## Envoyer des SMS

```python
message = client.sms.send(
    to="+243 81 234 5678",       # +243…, 00243…, 243…, 0812…, 812…, 33612345678 sont acceptés
    body="Votre commande 1042 est prête.",
    sender="MABOUTIQUE",         # facultatif : un Sender ID approuvé (client.sender_ids.list())
    reference="commande-1042",   # facultatif : votre référence, 64 caractères max.
    strip_accents=False,         # facultatif : voir « Coût avant l'envoi »
    idempotency_key=None,        # facultatif : voir « Relances et idempotence »
)

message.id                # "9d5c0a3e-…"
message.recipient         # "243812345678"
message.status            # MessageStatus.QUEUED
message.segments          # 1
message.credits_charged   # 1
message.created_at        # datetime (UTC, avec fuseau)
```

`body` contient jusqu'à 1600 caractères. L'envoi est asynchrone : `202 Accepted` signifie *mis en file et payé*, pas
*remis*. Suivez le statut par webhook (`message.status_changed`, recommandé) ou en interrogeant l'API :

```python
from labyrinthe_connect import MessageStatus

latest = client.sms.get(message.id)
if latest.status.is_final:
    print("Remis" if latest.status is MessageStatus.DELIVERED else f"Échec : {latest.error_code}")
```

`MessageStatus` : `queued`, `submitted`, `sent`, `delivered`, `failed`, `rejected`, `expired`, `undeliverable`, et
`unknown` pour un statut ajouté après cette version du SDK. Aides : `is_final`, `is_failed`, `is_pending`.

### Plusieurs destinataires : tout ou rien

```python
from labyrinthe_connect import RecipientNotSendableError

try:
    batch = client.sms.send_many(recipients=["0812345678", "0899999999"], body="Réunion demain à 9h.")
    batch.messages        # un message par numéro distinct (les doublons sont fusionnés)
    batch.replayed        # True quand l'Idempotency-Key a été rejouée
except RecipientNotSendableError as error:
    # RIEN n'a été envoyé : corrigez la liste puis réessayez.
    for rejected in error.rejected:
        print(rejected.recipient, rejected.reason)    # invalid_phone, blacklisted, opted_out
```

Jusqu'à 1000 destinataires par appel. Chaque destinataire, le Sender ID et le solde sont vérifiés **avant** tout
envoi : un seul numéro refusé (ou un solde insuffisant) et aucun SMS ne part. Les numéros que le SDK sait déjà
invalides sont refusés localement (`InvalidArgumentError`, avec leur seul indice), avant tout appel réseau.
`send()` renvoie un `Message` ; `send_many()` renvoie un `MessageBatch` (`messages`, `idempotency_key`, `replayed`).

### Coût avant l'envoi

1 crédit = 1 segment SMS. Un message en ASCII pur (GSM-7) tient en 160 caractères (153 par segment au-delà). **Tout
caractère non ASCII** (é, à, ç, emoji, guillemets typographiques…) passe en UCS-2 : 70 caractères (67 par segment
au-delà).

```python
estimate = client.sms.estimate(body="Réunion demain à 9h", recipients=["0812345678", "0899999999"])
estimate.encoding              # Encoding.UCS2
estimate.characters            # 19
estimate.segments_per_message  # 1
estimate.recipients_count      # 2 (normalisés, doublons fusionnés, numéros invalides ignorés)
estimate.total_credits         # 2
estimate.balance_after         # solde après un tel envoi
estimate.is_affordable         # le solde couvre-t-il l'envoi ?
```

`strip_accents=True` (sur `send`, `send_many`, `estimate` et `otp.send`) demande à l'API de remplacer accents et
caractères typographiques par leur équivalent ASCII avant l'envoi : le message repasse en GSM-7, souvent deux fois
moins cher. La translittération dépend du serveur (`Café` peut devenir `Cafe` ou `Caf'e`) : ne vous y fiez pas pour un
texte exact.

```python
client.sms.estimate(body="Votre réservation est confirmée", recipients="0812345678", strip_accents=True).encoding
# Encoding.GSM7
```

### Lister les messages et pagination

```python
from datetime import date, datetime, timezone

page = client.sms.list(status="failed", from_=date(2026, 11, 1), to=datetime.now(timezone.utc), per_page=50)
page.data, page.total, page.current_page, page.last_page, page.has_more
next_page = page.next_page()                     # None sur la dernière page

for message in client.sms.list(reference="commande-1042").auto_paging_iter():   # toutes les pages, à la demande
    ...

async for message in async_client.sms.list(reference="commande-1042").auto_paging_iter():
    ...
first_page = await async_client.sms.list()       # attendre le paginateur asynchrone donne la première page
```

Filtres : `status`, `recipient` (tout format), `reference`, `from_`, `to` (une `date` couvre toute la journée ; un
`datetime` doit avoir un fuseau), `page`, `per_page` (100 max.). Seuls les messages envoyés **avec la clé appelante**
sont listés. `client.sms.get(id)` lit un message du compte, par son `id` uniquement.

## Solde et Sender IDs

```python
balance = client.balance.get()
balance.balance, balance.held          # crédits disponibles, crédits réservés par les envois en cours

for sender_id in client.sender_ids.list():   # Sender IDs approuvés, utilisables comme `sender`
    sender_id.value, sender_id.status, sender_id.is_default
```

## OTP : vérifier un numéro

Le code est généré, envoyé par SMS et vérifié par Labyrinthe Connect. Il n'est jamais renvoyé par l'API (sauf avec
une clé de test) et jamais journalisé par le SDK.

```python
from labyrinthe_connect import OtpFailureReason

# 1. Envoyer le code. Conserver otp_id CÔTÉ SERVEUR (session, cache, base), jamais dans le navigateur.
otp = client.otp.send(phone="0812345678")
request.session["otp_id"] = otp.otp_id
otp.code_length, otp.code_type      # 6, OtpCodeType.NUMERIC (ou ALPHANUMERIC) : choisir le bon champ de saisie
otp.expires_at, otp.max_attempts    # datetime avec fuseau, 5
otp.resend_after_seconds            # 60 : compte à rebours avant « Renvoyer le code »

# 2. L'utilisateur saisit le code reçu par SMS.

# 3. Le vérifier.
result = client.otp.verify(otp_id=request.session["otp_id"], code=typed_code)
if result.verified:
    del request.session["otp_id"]          # connecter l'utilisateur / marquer le numéro comme vérifié
elif result.reason is OtpFailureReason.INVALID_CODE:
    ...                                     # mauvais code : encore result.attempts_remaining essais
else:
    ...                                     # EXPIRED, ALREADY_USED, MAX_ATTEMPTS_REACHED : demander un nouveau code
```

- Un mauvais code n'est **pas** une exception : `verify()` renvoie `OtpVerifyResult(verified=False, reason=…)`. La
  vérification est gratuite.
- Un nouvel `send()` vers le même numéro invalide le code précédent.
- Longueur, validité, tentatives, Sender ID et modèle viennent des paramètres OTP du compte. Vous pouvez remplacer le
  modèle (`template="Votre code MaBoutique : {code}"`, doit contenir `{code}` ; `{app_name}` et `{minutes}`
  facultatifs) et la langue (`locale="fr"` ou `"en"`, ou `OtpLocale`).
- `attempts_remaining` : un entier après `invalid_code`, `0` avec `max_attempts_reached`, `None` quand le code est
  vérifié, déjà utilisé ou expiré.
- `web_otp_domain="app.exemple.com"` ajoute la ligne `@app.exemple.com #123456` utilisée par la
  [Web OTP API](https://developer.mozilla.org/docs/Web/API/WebOTP_API) pour remplir le code automatiquement (Chrome,
  Android). `strip_accents=True` garde le message en GSM-7.
- Limites : `OtpResendTooSoonError` (attendre `retry_after` secondes ; un nouvel envoi est accepté tout de suite
  après une vérification réussie) et `OtpRateLimitedError` (`scope` = `phone` ou `account`, `limit`,
  `retry_after`). L'API accepte aussi au plus 10 appels `otp/send` par minute et par clé.
- Comme pour les SMS, chaque `otp.send()` porte une `Idempotency-Key` (un `uuid4`, ou la vôtre :
  `idempotency_key=`) : un rejeu renvoie le même `otp_id` (et le même `test_code` en mode test) sans second SMS, avec
  `otp.replayed` à `True`.

Voir [`examples/otp_login.py`](examples/otp_login.py) pour le parcours complet.

## Webhooks

Configurez un point de réception dans l'espace client (*Développeurs > Webhooks*) et copiez son secret. Les
événements sont envoyés en `POST` JSON `{ id, event, data, timestamp }` et signés
(`X-Signature: sha256=<HMAC-SHA256 du corps brut>`). Le SDK vérifie la signature en temps constant
(`hmac.compare_digest`) sur le **corps brut**, puis l'horodatage signé (300 secondes par défaut, `tolerance=`).

```python
from labyrinthe_connect import MessageStatusChanged, SignatureVerificationError, webhooks

try:
    event = webhooks.construct_event(raw_body, headers.get("X-Signature"), secret)   # bytes, jamais du JSON décodé
except SignatureVerificationError:
    ...   # répondre 400
if isinstance(event, MessageStatusChanged):
    update_message(event.data.message_id, event.data.status)
```

**Passez toujours le corps brut** (les octets exacts reçus) : `request.body` avec Django, `await request.body()` avec
FastAPI / Starlette, `request.get_data()` avec Flask. Re-sérialiser du JSON décodé change les octets et casse la
signature.

Chaque événement a `id` (dédoublonnez dessus : les livraisons sont retentées jusqu'à 5 fois et peuvent arriver dans
le désordre), `type` (le nom de l'événement tel qu'envoyé), `timestamp` (`datetime` avec fuseau), `raw` (l'enveloppe
décodée) et un `data` typé :

| Classe | `event.type` | `data` |
|---|---|---|
| `MessageStatusChanged` | `message.status_changed` | `message_id`, `recipient`, `status`, `provider_message_id`, `campaign_id`, `reference`, `error_code`, `error_message` |
| `CampaignCompleted` | `campaign.completed` | `campaign_id`, `name`, `recipients_count`, `messages_by_status`, `credits_charged`, `completed_at` |
| `BalanceLow` | `balance.low` | `balance`, `threshold` |
| `OtpVerified` | `otp.verified` | `otp_id`, `phone`, `status` (`verified`), `reason` (`None`), `verified_at` |
| `OtpFailed` | `otp.failed` | `otp_id`, `phone`, `status` (`failed` / `expired`), `reason` (`max_attempts_reached` / `expired`), `verified_at` (`None`) |
| `TestPing` | `test.ping` | `{}` |
| `UnknownEvent` | un type ajouté après cette version du SDK | tel qu'envoyé ; l'acquitter par un 2xx |

Signature invalide ou horodatage expiré : `SignatureVerificationError` ; corps signé qui n'est pas une enveloppe :
`InvalidWebhookPayloadError` — répondre `400` dans les deux cas. Répondez `2xx` sous 10 secondes ; mettez les
traitements longs en file. `webhooks.sign(body, secret)` construit un en-tête valide pour vos tests et
`webhooks.verify_signature(body, header, secret)` ne vérifie que la signature. Les trois intégrations ci-dessous
lisent le secret dans `LABYRINTHE_WEBHOOK_SECRET` par défaut.

### Django

```python
# urls.py
from django.urls import path
from labyrinthe_connect.contrib.django import WebhookView

urlpatterns = [path("webhooks/labyrinthe/", WebhookView.as_view())]   # settings.LABYRINTHE_WEBHOOK_SECRET

# receivers.py (importé depuis AppConfig.ready())
from django.dispatch import receiver
from labyrinthe_connect.contrib.django import message_status_changed, otp_verified

@receiver(message_status_changed)
def on_status(sender, event, **kwargs):
    Order.objects.filter(sms_id=event.data.message_id).update(sms_status=event.data.status)

@receiver(otp_verified)
def on_otp_verified(sender, event, **kwargs):
    PhoneCheck.objects.filter(otp_id=event.data.otp_id).update(verified=True)
```

Le secret vient de `WebhookView.as_view(secret=...)`, puis de `settings.LABYRINTHE_WEBHOOK_SECRET`, puis de la
variable d'environnement. Exemptée de CSRF, POST uniquement, `400` sur une mauvaise signature,
`200 {"received": true}` sinon. Signaux : `webhook_received` (tous), `message_status_changed`, `campaign_completed`,
`balance_low`, `otp_verified`, `otp_failed`, `test_ping`, `unknown_event` ; les récepteurs reçoivent `sender` (la
classe de l'événement), `event` et `request`. Un récepteur qui lève une exception donne un `500` (la livraison est
retentée). Surchargez `WebhookView.handle_event()` pour traiter les événements sans signaux.

### FastAPI

```python
from fastapi import Depends, FastAPI
from labyrinthe_connect import WebhookEvent
from labyrinthe_connect.contrib.fastapi import WebhookVerifier, create_webhook_router

app = FastAPI()
verify_webhook = WebhookVerifier()          # secret lu dans LABYRINTHE_WEBHOOK_SECRET (ou WebhookVerifier(secret=...))

@app.post("/webhooks/labyrinthe")
async def labyrinthe_webhook(event: WebhookEvent = Depends(verify_webhook)) -> dict:
    ...
    return {"received": True}

# ou une route toute faite qui appelle un gestionnaire synchrone ou asynchrone :
app.include_router(create_webhook_router(on_event=handle_event, path="/hooks/labyrinthe"))
```

La dépendance lit le corps brut (`await request.body()`) et lève `HTTPException(400)` sur une mauvaise signature.
Voir [`examples/webhook_fastapi.py`](examples/webhook_fastapi.py).

### Flask

```python
from flask import Flask
from labyrinthe_connect import MessageStatusChanged
from labyrinthe_connect.contrib.flask import create_webhook_blueprint

def handle_event(event):
    if isinstance(event, MessageStatusChanged):
        update_message(event.data.message_id, event.data.status)

app = Flask(__name__)
app.config["LABYRINTHE_WEBHOOK_SECRET"] = "…"                       # ou la variable d'environnement
app.register_blueprint(create_webhook_blueprint(handle_event))     # POST /webhooks/labyrinthe
```

Options : `secret=`, `tolerance=`, `rule=`, `name=`, `url_prefix=`. Chaque événement vérifié est aussi émis par le
signal blinker `webhook_received` (`labyrinthe_connect.contrib.flask.webhook_received`). Voir
[`examples/webhook_flask.py`](examples/webhook_flask.py).

## Erreurs

Toutes les exceptions héritent de `LabyrintheError` et ont `code` (stable, pour votre code), `message`, `status`
(statut HTTP, ou `None` sans réponse), `details` (toujours un `dict`) et `request_id`. Les erreurs de l'API héritent
de `ApiError` et exposent aussi `headers` et `api_version` (`X-Api-Version`). Les messages de l'API sont en français :
testez `code` ou la classe d'exception, jamais le message.

| Exception | Quand |
|---|---|
| `AuthenticationError` | 401 — clé absente, invalide ou révoquée |
| `PermissionError` | 403 — IP non autorisée (`FORBIDDEN`), OTP désactivé pour le compte (`OTP_DISABLED_FOR_ACCOUNT`) |
| `AccountSuspendedError` | 403 `ACCOUNT_SUSPENDED` — compte suspendu par la plateforme (hérite de `PermissionError`) |
| `NotFoundError` | 404 — message ou `otp_id` inconnu |
| `ValidationError` | 422 `VALIDATION_ERROR` — `field_errors` |
| `RecipientNotSendableError` | 422 — `rejected`, `reason` ; rien n'a été envoyé |
| `SenderIdNotApprovedError` | 422 — `sender` n'est pas un Sender ID approuvé |
| `InvalidPhoneError` | 422 `INVALID_PHONE` (OTP) |
| `InsufficientCreditsError` | 409 — `available`, `required` ; rien n'a été envoyé |
| `IdempotencyKeyReusedError` | 409 — même clé, requête différente |
| `IdempotencyRequestInProgressError` | 409 — requête d'origine encore en cours (déjà relancée) ; `retry_after` |
| `IdempotencyKeyUnresolvedError` | 409 `IDEMPOTENCY_KEY_UNRESOLVED` — issue de la requête d'origine inconnue ; jamais relancée : vérifier, puis renvoyer avec une nouvelle clé |
| `RateLimitError` | 429 `TOO_MANY_REQUESTS` — `retry_after` (secondes) |
| `OtpResendTooSoonError`, `OtpRateLimitedError` | 429 — héritent de `RateLimitError` ; `retry_after`, `scope`, `limit` |
| `ServiceUnavailableError` | 5xx |
| `FeatureDisabledError` | 503 `FEATURE_DISABLED` / `OTP_SERVICE_DISABLED` — maintenance, rien n'a été fait (hérite de `ServiceUnavailableError`) ; `feature`, `blocked_by`, `admin_message`, `until`, `retry_after` |
| `ApiError` | tout autre code (liste ouverte : de nouveaux codes peuvent apparaître, ex. `INVALID_OTP_TEMPLATE`) |
| `APIConnectionError`, `APITimeoutError` | erreur réseau (`CONNECTION_ERROR`) ou délai dépassé (`TIMEOUT`) |
| `InvalidArgumentError` | entrée invalide détectée par le SDK, avant tout appel (aussi une `ValueError`) |
| `UnexpectedResponseError` | une réponse que le SDK ne sait pas lire |
| `SignatureVerificationError`, `InvalidWebhookPayloadError` | vérification d'un webhook (répondre 400) |

`PermissionError` masque l'exception native du même nom : préférez `from labyrinthe_connect import errors` et
`errors.PermissionError`.

```python
import logging

from labyrinthe_connect import ApiError, InsufficientCreditsError, LabyrintheError, ValidationError

logger = logging.getLogger(__name__)

try:
    client.sms.send(to="0812345678", body="Bonjour")
except InsufficientCreditsError as error:
    alert_finance(error.available, error.required)
except ValidationError as error:
    logger.warning("Champs invalides : %s", error.field_errors)    # {"body": ("Le champ body est obligatoire.",)}
except ApiError as error:
    logger.error("Erreur Labyrinthe Connect %s (HTTP %s, requête %s)", error.code, error.status, error.request_id)
except LabyrintheError as error:                                   # réseau, délai, validation locale
    logger.error("Labyrinthe Connect injoignable : %s (requête %s)", error.code, error.request_id)
```

### Maintenance (`FeatureDisabledError`)

La plateforme peut couper toute l'API (`blocked_by == "api"`, refus avant l'authentification) ou une fonction
(`api.sms_send`, `api.otp_send`, `api.otp_verify`, ou tout le service OTP `otp` avec `OTP_SERVICE_DISABLED`).
Rien n'est envoyé, aucun crédit n'est consommé. `admin_message` est le message de l'administrateur (`None` s'il n'y
en a pas), `until` la réactivation programmée (`datetime` avec fuseau, `None` si inconnue) et `retry_after` les
secondes restantes (`Retry-After`, `None` quand aucune date n'est programmée). Le SDK a déjà attendu et relancé quand
`retry_after` tient dans `max_retry_wait` ; ne relancez jamais en boucle : mettez le travail en file ou prévenez
l'utilisateur.

```python
from labyrinthe_connect import FeatureDisabledError

try:
    client.sms.send(to="0812345678", body="Votre commande a été expédiée")
except FeatureDisabledError as error:
    # Rien n'a été envoyé : réessayer plus tard, après error.until quand elle est connue.
    queue.enqueue_in(error.retry_after or 600, send_shipping_sms, order_id)
    logger.warning("SMS suspendus par Labyrinthe Connect (%s) : %s", error.blocked_by, error.admin_message or error.message)
```

Quand seul le service OTP est coupé, les codes déjà envoyés peuvent toujours être vérifiés.

## Relances et idempotence

### Relances et délais

Le SDK relance automatiquement (par défaut : 2 relances, backoff exponentiel 0,5 s → 1 s → 2 s avec jitter)
**uniquement quand un rejeu ne peut pas produire deux fois un effet** :

| Requête | Erreur réseau / délai | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` | 503 `FEATURE_DISABLED` / `OTP_SERVICE_DISABLED` |
|---|---|---|---|---|---|
| `get`, `list`, `balance.get`, `sender_ids.list`, `estimate` | relancée | relancée | relancée après `Retry-After` | — | seulement après `Retry-After` |
| `send` / `send_many` / `otp.send` (toujours avec une `Idempotency-Key`) | relancée, même clé | relancée, même clé | relancée après `Retry-After` | relancée après `Retry-After`, même clé | seulement après `Retry-After`, même clé |
| `otp.verify` | **jamais** | **jamais** | relancée (refusée avant traitement) | — | seulement après `Retry-After` (refusée avant traitement) |

Les erreurs métier (autres 4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, `IDEMPOTENCY_KEY_UNRESOLVED`) ne sont
jamais relancées. Quand l'API demande d'attendre plus que `max_retry_wait` (10 s par défaut), le SDK lève l'erreur
(`RateLimitError`…) au lieu d'attendre. Une 503 de maintenance (`FeatureDisabledError`) n'est jamais relancée par
backoff : seulement après exactement `Retry-After`, s'il est présent et dans `max_retry_wait`. Les redirections ne sont
jamais suivies. `timeout=` (secondes) s'applique à chaque tentative.

### Idempotence (jamais de double envoi)

Chaque envoi porte une `Idempotency-Key`. Si vous n'en donnez pas, le SDK génère un `uuid4` et le réutilise pour ses
propres relances : une erreur réseau ne produit jamais un second SMS. Pour vous protéger aussi de **vos** relances
(files de tâches, double clic, plantage), passez une clé dérivée de votre événement métier (255 caractères max.). Un
rejeu dans les 24 heures renvoie les messages d'origine sans nouvel envoi ni débit :

```python
batch = client.sms.send_many(recipients=["0812345678"], body="Votre commande 1042 est prête.",
                             idempotency_key="commande-1042-prete")
batch.replayed          # True s'il s'agit d'un rejeu
batch.idempotency_key   # "commande-1042-prete" (ou l'uuid4 généré)
```

La même clé avec un autre contenu est refusée par `IdempotencyKeyReusedError` (409). L'empreinte utilise les
destinataires normalisés et dédoublonnés : `0812345678` puis `243812345678` avec la même clé est un rejeu. Tant que la
requête d'origine est en cours, l'API répond `409 IDEMPOTENCY_REQUEST_IN_PROGRESS` : le SDK attend `Retry-After`
(5 s) et relance avec la même clé ; une fois ses relances épuisées, il lève `IdempotencyRequestInProgressError`, et
relancer avec la même clé reste sans risque. `/sms` et `/otp/send` ont des espaces de clés séparés.

Si la requête d'origine a échoué de façon inattendue côté API, son issue est inconnue (un SMS ou un code OTP a pu
partir) et tout rejeu avec cette clé reçoit `409 IDEMPOTENCY_KEY_UNRESOLVED`, levée comme
`IdempotencyKeyUnresolvedError`. Le SDK ne la relance jamais, et **vous ne devez pas la relancer automatiquement**
non plus :

1. vérifiez ce qui s'est passé : `client.sms.list(reference="commande-1042")` pour un SMS (envoyez une `reference`
   pour cela), ou demandez à l'utilisateur si un code est arrivé pour un OTP ;
2. seulement si rien n'est parti, renvoyez avec une **nouvelle** `Idempotency-Key` (l'ancienne reste non résolue).

```python
from labyrinthe_connect import IdempotencyKeyUnresolvedError

try:
    client.sms.send(to="0812345678", body="Commande prête", reference="commande-1042",
                    idempotency_key="commande-1042-prete")
except IdempotencyKeyUnresolvedError as error:
    logger.warning("Issue inconnue, requête %s : vérifier avant de renvoyer", error.request_id)
    already_sent = client.sms.list(reference="commande-1042", per_page=1).data
```

`otp.send()` est relancé après une erreur réseau, une 5xx, un `TOO_MANY_REQUESTS` ou un
`IDEMPOTENCY_REQUEST_IN_PROGRESS` (même clé, donc jamais un second code), jamais après `OTP_RESEND_TOO_SOON` ou
`OTP_RATE_LIMITED`. Passez votre propre clé (par ex. dérivée de la tentative de connexion) pour sécuriser aussi vos
propres relances.

## Mode test

Avec une clé `lsk_test_…`, le fournisseur est simulé : **rien n'est envoyé, aucun crédit n'est consommé**, et les
réponses ont la même forme qu'en production, à deux différences près :

- le solde n'est pas vérifié : `InsufficientCreditsError` n'est jamais levée en bac à sable ;
- `otp.send()` renvoie le code dans `otp.test_code`, pour tester tout le parcours OTP de bout en bout. Il vaut
  toujours `None` avec une clé live, est masqué dans `repr()` et retiré quand le résultat est sérialisé (pickle).

Toutes les autres vérifications (validation, Sender IDs, idempotence, limites de débit, maintenance) se comportent
comme en production. Utilisez `client.is_test_mode` pour distinguer les cas, par exemple pour lire le code en test :

```python
otp = client.otp.send(phone="0812345678")
code = otp.test_code if client.is_test_mode else input("Code : ")
```

## Journalisation et sécurité

- **Journalisation** : le logger `labyrinthe_connect` (`logging`) émet des enregistrements debug/info/warning/error —
  jamais la clé, un code, un corps ni un numéro complet (`24381*****78`). Rien n'est affiché tant que votre
  application ne configure pas la journalisation, par ex. `logging.getLogger("labyrinthe_connect").setLevel(logging.DEBUG)`.
- `repr(client)` affiche `lsk_test_****` ; les clients refusent d'être sérialisés (ils contiennent la clé) : créez-en
  un par processus.
- Gardez la clé dans une variable d'environnement ou un gestionnaire de secrets, jamais dans le code source, un
  navigateur ou une application mobile. Une clé peut être restreinte à une liste d'adresses IP (403 `FORBIDDEN`
  sinon).
- Les numéros sont des données personnelles : masquez-les dans vos propres journaux avec
  `labyrinthe_connect.phone.mask()`.
- Aides pour les numéros : `labyrinthe_connect.phone.normalize("0812345678")` renvoie `"243812345678"` (ou `None`)
  avec les mêmes règles que l'API : sans `+`, 11 chiffres ou plus ne commençant pas par 0 sont déjà internationaux
  (`33612345678` reste `33612345678`) ; sinon un numéro local reçoit l'indicatif par défaut. Pour un numéro étranger
  court (10 chiffres ou moins), utilisez toujours le `+`. Les requêtes envoient le numéro normalisé en E.164 avec son
  `+` (`+243812345678`), donc un numéro étranger court garde son indicatif. `is_valid()` et `mask()` sont aussi
  disponibles. Les numéros internationaux ne sont jamais bloqués.
- `User-Agent: labyrinthe-python/<version> Python/<version>`.

## Identifiants de requête

Chaque réponse de l'API porte un en-tête `X-Request-Id`, à communiquer au support. Le SDK l'expose comme
`request_id` sur chaque erreur et chaque objet renvoyé (`Message`, `MessageBatch`, `Estimate`, `Balance`, `SenderId`,
`OtpSendResult`, `OtpVerifyResult`, pages) ; il est exclu de l'égalité et de `repr()`. Chaque méthode accepte aussi
`request_id=` pour envoyer votre propre identifiant (1 à 128 caractères parmi `A-Z a-z 0-9 . _ : -`, vérifié
localement), réutilisé par chaque relance de l'appel et renvoyé par l'API — pratique pour corréler avec vos propres
journaux, y compris sur une erreur réseau.

```python
balance = client.balance.get(request_id="paiement-1042")
balance.request_id   # "paiement-1042"
```

## Typage

Le package fournit `py.typed` et passe `mypy --strict`. Les résultats sont des dataclasses gelées (`Message`,
`MessageBatch`, `Estimate`, `Balance`, `SenderId`, `OtpVerifyResult`…) avec des `datetime` à fuseau. Les statuts sont
des enums `str` (`MessageStatus`, `Encoding`, `SenderIdStatus`, `RejectionReason`, `OtpFailureReason`,
`OtpCodeType`, `OtpRateLimitScope`, `OtpVerificationStatus`, `WebhookEventType`) égales à leur valeur
(`message.status == "queued"`) ; une valeur ajoutée plus tard à l'API est lue comme `UNKNOWN`. `WebhookEvent` est une
`Union` des classes d'événements : affinez-la avec `isinstance` (ou `match` sur Python 3.10+).

```python
from labyrinthe_connect import Message, MessageStatus, SyncPage

def failed(page: SyncPage[Message]) -> list[Message]:
    return [m for m in page.data if m.status is MessageStatus.FAILED]
```

## Sans le package (HTTP brut)

L'API est du JSON sur HTTPS ([`openapi/openapi.yaml`](../openapi/openapi.yaml)) : vous pouvez l'appeler directement
avec `httpx` ou `requests`. Vous gérez alors vous-même ce que fait le SDK : l'`Idempotency-Key`, les relances sûres,
la correspondance des erreurs et la vérification des webhooks.

```python
import os
import uuid

import httpx

BASE_URL = "https://api.connect.labyrinthe-rdc.com/api/public/v1"
headers = {"Authorization": f"Bearer {os.environ['LABYRINTHE_API_KEY']}", "Accept": "application/json"}

response = httpx.post(
    f"{BASE_URL}/sms",
    headers={**headers, "Idempotency-Key": str(uuid.uuid4())},   # réutiliser la MÊME clé en cas de relance
    json={"recipient": "+243812345678", "body": "Votre commande 1042 est prête.", "reference": "commande-1042"},
    timeout=30,
)
if response.status_code == 202:
    message = response.json()["data"][0]           # une liste de messages, un par destinataire
    print(message["id"], message["status"])
else:
    error = response.json()["error"]               # {"code", "message", "details"}
    print(error["code"], response.headers.get("X-Request-Id"))
```

Avec `requests`, l'appel est identique (`requests.post(url, headers=..., json=..., timeout=30)`). Autres routes :
`POST /sms` avec `recipients` (liste), `POST /sms/estimate`, `GET /sms`, `GET /sms/{id}`, `GET /balance`,
`GET /sender-ids`, `POST /otp/send` (`phone`), `POST /otp/verify` (`otp_id`, `code`).

Vérifier un webhook sur le corps brut, en temps constant, et refuser les horodatages trop anciens :

```python
import hashlib
import hmac
import json
import time

def verify(raw_body: bytes, signature_header: str, secret: str) -> dict:
    expected = "sha256=" + hmac.new(secret.encode(), raw_body, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, (signature_header or "").strip().lower()):
        raise ValueError("signature invalide")     # répondre 400
    event = json.loads(raw_body)
    if abs(time.time() - event["timestamp"]) > 300:
        raise ValueError("horodatage trop ancien") # répondre 400 (rejeu)
    return event                                   # {"id", "event", "data", "timestamp"} : dédoublonner sur id
```

## Mise à jour

Le SDK suit [SemVer](https://semver.org/lang/fr/) ; voir le [CHANGELOG](CHANGELOG.md).

- **1.2.0 → 1.2.1** (API 1.3.1) : aucun changement de code. L'URL par défaut de l'API est désormais
  `https://api.connect.labyrinthe-rdc.com/api/public/v1` (celle de la 1.2.0 ne répondait pas). Mettez à jour, ou indiquez cette URL
  explicitement si vous restez en 1.2.0.
- **1.1.x → 1.2.0** (spec 1.3.0, rétrocompatible) : nouvelle `FeatureDisabledError` (503 `FEATURE_DISABLED`), sous-classe
  de `ServiceUnavailableError` : les blocs `except ServiceUnavailableError` existants l'attrapent toujours.
  `OTP_SERVICE_DISABLED` est désormais levée aussi comme `FeatureDisabledError`. Les 503 de maintenance ne sont
  relancées qu'après `Retry-After`, jamais par backoff.
- **1.1.0 → 1.1.1** : `IdempotencyKeyUnresolvedError` ; les numéros sont envoyés en E.164 avec leur `+`.
- **1.0 → 1.1** : `request_id` partout, `Idempotency-Key` automatique sur `otp.send()`, `strip_accents`,
  `web_otp_domain`, webhooks OTP.

L'API ajoute des champs, des codes d'erreur et des événements de webhook sans préavis en v1 : le SDK ignore les champs
inconnus et lit les valeurs inconnues comme `UNKNOWN` / `UnknownEvent` / `ApiError`, donc une version plus ancienne du
SDK continue de fonctionner.

## Contribuer

Les issues et pull requests vont au monorepo
[`Labyrinthe-Team/labyrinthe-connect-sdks`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks) ; voir
[`CONTRIBUTING.md`](../CONTRIBUTING.md). Pour un problème de sécurité, écrivez à connect@labyrinthe-rdc.com plutôt que
d'ouvrir une issue publique.

```bash
python -m venv .venv && . .venv/bin/activate     # Windows : .venv\Scripts\activate
pip install -r requirements-dev.txt -e .
ruff check . && ruff format --check . && mypy
pytest                         # tests unitaires + contrat OpenAPI (transport simulé, sans réseau)
python -m build && twine check --strict dist/*

# tests d'intégration contre un backend local, avec une clé de TEST uniquement
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 pytest -m integration
```

Le contrat de l'API est [`openapi/openapi.yaml`](../openapi/openapi.yaml) ; les tests de contrat gardent le SDK
aligné dessus. Exemples : [`examples/`](examples) (`send_sms.py`, `async_send.py`, `otp_login.py`,
`webhook_fastapi.py`, `webhook_flask.py`). Mettez à jour le [CHANGELOG](CHANGELOG.md) sous `[Unreleased]`.

## Licence

MIT — voir [LICENSE](LICENSE).

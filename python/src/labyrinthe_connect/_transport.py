"""Envoi des requêtes, politique de relance et conversion des erreurs.

Mêmes règles que les SDK PHP (php/src/Internal/Transport.php) et Node
(node/src/internal/transport.ts). Le cœur est commun ; seules les boucles
synchrone et asynchrone diffèrent. Usage interne.
"""

from __future__ import annotations

import asyncio
import enum
import json
import logging
import platform
import random
import re
import time
import uuid
from dataclasses import dataclass
from email.utils import parsedate_to_datetime
from typing import Any, Awaitable, Callable, Dict, List, Mapping, Optional, Tuple, Union
from urllib.parse import urlsplit

import httpx

from ._redact import QueryValue, redact_api_key, redact_path, strip_query_strings
from ._version import __version__
from .errors import (
    APIConnectionError,
    ApiError,
    APITimeoutError,
    InvalidArgumentError,
    UnexpectedResponseError,
    _to_int,
    error_from_response,
)

logger = logging.getLogger("labyrinthe_connect")

DEFAULT_BASE_URL = "https://api.labyrinthe-rdc.com/api/public/v1"
API_PATH = "/api/public/v1"

DEFAULT_TIMEOUT = 30.0
DEFAULT_MAX_RETRIES = 2
DEFAULT_MAX_RETRY_WAIT = 10.0
MAX_RETRIES_LIMIT = 10

#: Longueur maximale d'une ``Idempotency-Key`` (spec : ``components.parameters.IdempotencyKey``).
MAX_IDEMPOTENCY_KEY_LENGTH = 255
#: Forme d'un ``X-Request-Id`` réutilisé par l'API (spec : ``components.parameters.RequestId``).
REQUEST_ID_PATTERN = r"^[A-Za-z0-9._:-]{1,128}$"
_REQUEST_ID = re.compile(r"[A-Za-z0-9._:-]{1,128}")

#: Requête d'origine encore en cours : relancée après Retry-After en mode SAFE (même Idempotency-Key).
_IN_PROGRESS = "IDEMPOTENCY_REQUEST_IN_PROGRESS"

_BASE_DELAY = 0.5
_MAX_BACKOFF = 8.0

Json = Dict[str, Any]
Query = Mapping[str, Optional[QueryValue]]


class RetryMode(enum.Enum):
    """Quand une requête peut être renvoyée automatiquement.

    - ``SAFE`` : le rejeu ne peut pas produire deux fois un effet (GET, estimate,
      POST /sms et POST /otp/send avec Idempotency-Key). Relancée sur erreur réseau, 5xx,
      429 TOO_MANY_REQUESTS et 409 IDEMPOTENCY_REQUEST_IN_PROGRESS (après Retry-After).
    - ``THROTTLED_ONLY`` : POST /otp/verify. Relancée seulement sur 429 TOO_MANY_REQUESTS
      (refusée avant traitement), jamais sur erreur réseau ni 5xx (risque d'une tentative consommée).
    """

    SAFE = "safe"
    THROTTLED_ONLY = "throttled-only"


@dataclass(frozen=True)
class ApiResponse:
    status: int
    body: Json
    #: En-têtes, noms en minuscules.
    headers: Dict[str, str]

    @property
    def request_id(self) -> Optional[str]:
        """``X-Request-Id`` de la réponse."""
        return self.headers.get("x-request-id")

    @property
    def replayed(self) -> bool:
        """``Idempotent-Replayed: true`` : rejeu d'une requête précédente."""
        return self.headers.get("idempotent-replayed", "").strip().lower() == "true"


@dataclass(frozen=True)
class _Call:
    method: str
    url: str
    log_path: str
    mode: RetryMode
    content: Optional[bytes]
    headers: Dict[str, str]
    timeout: float
    params: List[Tuple[str, str]]
    request_id: Optional[str]


@dataclass(frozen=True)
class _Failure:
    timeout: bool
    reason: str


def user_agent() -> str:
    """``labyrinthe-python/1.0.0 Python/3.12.4``"""
    return f"labyrinthe-python/{__version__} Python/{platform.python_version()}"


def normalize_base_url(base_url: str) -> str:
    trimmed = base_url.strip().rstrip("/")
    parts = urlsplit(trimmed)
    if parts.scheme not in ("http", "https") or not parts.netloc:
        raise InvalidArgumentError(
            "base_url must be an absolute http(s) URL, e.g. https://api.labyrinthe-rdc.com/api/public/v1."
        )
    # Un hôte seul ("http://localhost:8000") désigne la racine du backend.
    return trimmed + API_PATH if parts.path in ("", "/") else trimmed


def validate_request_id(request_id: Optional[str]) -> Optional[str]:
    """Un ``X-Request-Id`` fourni par l'appelant, tel quel ; refusé s'il serait remplacé par l'API."""
    if request_id is None:
        return None
    if not isinstance(request_id, str) or not _REQUEST_ID.fullmatch(request_id):
        raise InvalidArgumentError("request_id must be 1 to 128 characters among A-Z a-z 0-9 . _ : -")
    return request_id


def idempotency_key_or_new(idempotency_key: Optional[str]) -> str:
    """La clé fournie (validée) ou un UUID4 : générée une fois, réutilisée par chaque relance de l'appel."""
    if idempotency_key is None:
        return str(uuid.uuid4())
    if not isinstance(idempotency_key, str) or not idempotency_key or len(idempotency_key) > MAX_IDEMPOTENCY_KEY_LENGTH:
        raise InvalidArgumentError(f"idempotency_key must be 1 to {MAX_IDEMPOTENCY_KEY_LENGTH} characters.")
    return idempotency_key


def validate_options(timeout: float, max_retries: int, max_retry_wait: float) -> None:
    if isinstance(timeout, bool) or not isinstance(timeout, (int, float)) or not timeout > 0:
        raise InvalidArgumentError("timeout must be a number of seconds greater than 0.")
    if isinstance(max_retries, bool) or not isinstance(max_retries, int) or not 0 <= max_retries <= MAX_RETRIES_LIMIT:
        raise InvalidArgumentError(f"max_retries must be an integer between 0 and {MAX_RETRIES_LIMIT}.")
    if isinstance(max_retry_wait, bool) or not isinstance(max_retry_wait, (int, float)) or max_retry_wait < 0:
        raise InvalidArgumentError("max_retry_wait cannot be negative.")


class _BaseTransport:
    """Construction des requêtes et décisions de relance, sans entrée/sortie."""

    def __init__(
        self,
        api_key: str,
        base_url: str,
        timeout: float,
        max_retries: int,
        max_retry_wait: float,
    ) -> None:
        self.__api_key = api_key
        self.base_url = base_url
        self.timeout = float(timeout)
        self.max_retries = max_retries
        self.max_retry_wait = float(max_retry_wait)

    # -- préparation -------------------------------------------------------------

    def _prepare(
        self,
        method: str,
        path: str,
        mode: RetryMode,
        query: Optional[Query],
        body: Optional[Json],
        headers: Optional[Mapping[str, str]],
        timeout: Optional[float],
        request_id: Optional[str],
    ) -> _Call:
        request_id = validate_request_id(request_id)
        params: List[Tuple[str, str]] = [(k, str(v)) for k, v in (query or {}).items() if v is not None]
        if timeout is not None:
            validate_options(timeout, self.max_retries, self.max_retry_wait)
        all_headers = {
            "Authorization": f"Bearer {self.__api_key}",
            "Accept": "application/json",
            "User-Agent": user_agent(),
            **(headers or {}),
        }
        if request_id is not None:
            # Même identifiant pour chaque tentative de cet appel.
            all_headers["X-Request-Id"] = request_id
        content: Optional[bytes] = None
        if body is not None:
            # Sérialisé une seule fois : chaque relance renvoie exactement les mêmes octets.
            content = json.dumps(body, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
            all_headers["Content-Type"] = "application/json"
        return _Call(
            method=method,
            url=self.base_url + path,
            log_path=redact_path(path, params),
            mode=mode,
            content=content,
            headers=all_headers,
            timeout=float(timeout) if timeout is not None else self.timeout,
            params=params,
            request_id=request_id,
        )

    def _build_request(self, client: Union[httpx.Client, httpx.AsyncClient], call: _Call) -> httpx.Request:
        return client.build_request(
            call.method,
            call.url,
            params=tuple(call.params) if call.params else None,
            content=call.content,
            headers=call.headers,
            timeout=httpx.Timeout(call.timeout),
        )

    # -- décisions ---------------------------------------------------------------

    def _after_network_failure(self, call: _Call, attempt: int, failure: _Failure) -> float:
        """Délai avant la relance, ou lève l'erreur réseau (hors de tout bloc ``except``)."""
        if call.mode is RetryMode.SAFE and attempt < self.max_retries:
            delay = self._backoff(attempt)
            logger.warning(
                "Labyrinthe Connect network error, retrying %s %s (attempt %d, in %.2f s, timeout=%s)",
                call.method,
                call.log_path,
                attempt + 1,
                delay,
                failure.timeout,
            )
            return delay
        logger.error(
            "Labyrinthe Connect network error on %s %s (timeout=%s)", call.method, call.log_path, failure.timeout
        )
        if call.mode is RetryMode.THROTTLED_ONLY:
            suffix = " The request may have been processed; it was not retried automatically."
        elif "Idempotency-Key" in call.headers:
            # POST /sms, /otp/send : un nouvel essai avec la même clé ne renvoie jamais rien en double.
            suffix = " Retrying with the same idempotency_key is safe: nothing is sent twice."
        else:
            suffix = ""
        path = call.log_path
        if failure.timeout:
            raise APITimeoutError(
                f"The Labyrinthe Connect API did not answer within {call.timeout:g} s ({call.method} {path}).{suffix}",
                request_id=call.request_id,
            )
        raise APIConnectionError(
            f"Could not reach the Labyrinthe Connect API ({call.method} {path}): {failure.reason}.{suffix}",
            request_id=call.request_id,
        )

    def _after_response(
        self,
        call: _Call,
        attempt: int,
        status: int,
        content: bytes,
        raw_headers: httpx.Headers,
        started: float,
    ) -> Union[ApiResponse, float]:
        """La réponse décodée, ou le délai avant une relance ; lève l'erreur API sinon."""
        headers = {k.lower(): v for k, v in raw_headers.items()}
        logger.debug(
            "Labyrinthe Connect response %s %s: HTTP %d in %d ms%s (request %s)",
            call.method,
            call.log_path,
            status,
            round((time.monotonic() - started) * 1000),
            " (idempotent replay)" if headers.get("idempotent-replayed") == "true" else "",
            headers.get("x-request-id", "-"),
        )
        if 200 <= status < 300:
            return ApiResponse(status=status, body=_decode(content, status), headers=headers)

        error = error_from_response(status, content, headers)
        if attempt < self.max_retries and _is_retryable(call.mode, error):
            delay = self._retry_delay(error, attempt)
            if delay is not None:
                logger.warning(
                    "Labyrinthe Connect request failed, retrying %s %s: HTTP %d %s (attempt %d, in %.2f s, request %s)",
                    call.method,
                    call.log_path,
                    status,
                    error.code,
                    attempt + 1,
                    delay,
                    error.request_id or "-",
                )
                return delay
        logger.info(
            "Labyrinthe Connect API error on %s %s: HTTP %d %s (request %s)",
            call.method,
            call.log_path,
            status,
            error.code,
            error.request_id or "-",
        )
        raise error

    def _retry_delay(self, error: ApiError, attempt: int) -> Optional[float]:
        """Délai avant la prochaine tentative ; ``None`` pour abandonner (attente demandée trop longue)."""
        if error.status == 429 or error.code == _IN_PROGRESS:
            retry_after = _retry_after_seconds(error)
            if retry_after is not None:
                return retry_after if retry_after <= self.max_retry_wait else None
        return self._backoff(attempt)

    def _backoff(self, attempt: int) -> float:
        """Backoff exponentiel avec jitter : 0,5 s, 1 s, 2 s… (50 à 100 %), plafonné."""
        delay = min(_MAX_BACKOFF, _BASE_DELAY * 2.0**attempt)
        jitter = 0.5 + random.random() * 0.5  # noqa: S311 - jitter, not a secret
        return min(self.max_retry_wait, delay * jitter)

    def _log_attempt(self, call: _Call, attempt: int) -> None:
        logger.debug("Labyrinthe Connect request %s %s (attempt %d)", call.method, call.log_path, attempt + 1)

    def __repr__(self) -> str:
        return (
            f"{type(self).__name__}(api_key={redact_api_key(self.__api_key)!r}, "
            f"base_url={self.base_url!r}, max_retries={self.max_retries!r})"
        )

    def __reduce__(self) -> Tuple[Any, ...]:
        raise TypeError(f"{type(self).__name__} cannot be pickled: it holds an API key.")


class SyncTransport(_BaseTransport):
    def __init__(
        self,
        api_key: str,
        base_url: str,
        timeout: float,
        max_retries: int,
        max_retry_wait: float,
        http_client: Optional[httpx.Client],
    ) -> None:
        super().__init__(api_key, base_url, timeout, max_retries, max_retry_wait)
        if http_client is not None and not isinstance(http_client, httpx.Client):
            raise InvalidArgumentError(
                "http_client must be an httpx.Client (use httpx.AsyncClient with AsyncLabyrinthe)."
            )
        self._owns_client = http_client is None
        self._client = http_client if http_client is not None else httpx.Client(follow_redirects=False)
        #: Attente entre deux tentatives (remplacée dans les tests).
        self.sleep: Callable[[float], None] = time.sleep

    def request(
        self,
        method: str,
        path: str,
        mode: RetryMode,
        *,
        query: Optional[Query] = None,
        body: Optional[Json] = None,
        headers: Optional[Mapping[str, str]] = None,
        timeout: Optional[float] = None,
        request_id: Optional[str] = None,
    ) -> ApiResponse:
        call = self._prepare(method, path, mode, query, body, headers, timeout, request_id)
        attempt = 0
        while True:
            self._log_attempt(call, attempt)
            started = time.monotonic()
            failure: Optional[_Failure] = None
            response: Optional[httpx.Response] = None
            try:
                response = self._client.send(self._build_request(self._client, call), follow_redirects=False)
            except httpx.TimeoutException:
                failure = _Failure(timeout=True, reason="timeout")
            except httpx.RequestError as exc:
                failure = _Failure(timeout=False, reason=_describe(exc))
            # Hors du bloc except : l'exception httpx (et sa requête, avec l'en-tête
            # Authorization) n'est jamais chaînée à l'erreur levée.
            if failure is not None or response is None:
                delay = self._after_network_failure(call, attempt, failure or _Failure(False, "no response"))
            else:
                outcome = self._after_response(
                    call, attempt, response.status_code, response.content, response.headers, started
                )
                if isinstance(outcome, ApiResponse):
                    return outcome
                delay = outcome
            self.sleep(delay)
            attempt += 1

    def close(self) -> None:
        if self._owns_client:
            self._client.close()


class AsyncTransport(_BaseTransport):
    def __init__(
        self,
        api_key: str,
        base_url: str,
        timeout: float,
        max_retries: int,
        max_retry_wait: float,
        http_client: Optional[httpx.AsyncClient],
    ) -> None:
        super().__init__(api_key, base_url, timeout, max_retries, max_retry_wait)
        if http_client is not None and not isinstance(http_client, httpx.AsyncClient):
            raise InvalidArgumentError("http_client must be an httpx.AsyncClient (use httpx.Client with Labyrinthe).")
        self._owns_client = http_client is None
        self._client = http_client if http_client is not None else httpx.AsyncClient(follow_redirects=False)
        #: Attente entre deux tentatives (remplacée dans les tests).
        self.sleep: Callable[[float], Awaitable[None]] = asyncio.sleep

    async def request(
        self,
        method: str,
        path: str,
        mode: RetryMode,
        *,
        query: Optional[Query] = None,
        body: Optional[Json] = None,
        headers: Optional[Mapping[str, str]] = None,
        timeout: Optional[float] = None,
        request_id: Optional[str] = None,
    ) -> ApiResponse:
        call = self._prepare(method, path, mode, query, body, headers, timeout, request_id)
        attempt = 0
        while True:
            self._log_attempt(call, attempt)
            started = time.monotonic()
            failure: Optional[_Failure] = None
            response: Optional[httpx.Response] = None
            try:
                response = await self._client.send(self._build_request(self._client, call), follow_redirects=False)
            except httpx.TimeoutException:
                failure = _Failure(timeout=True, reason="timeout")
            except httpx.RequestError as exc:
                failure = _Failure(timeout=False, reason=_describe(exc))
            if failure is not None or response is None:
                delay = self._after_network_failure(call, attempt, failure or _Failure(False, "no response"))
            else:
                outcome = self._after_response(
                    call, attempt, response.status_code, response.content, response.headers, started
                )
                if isinstance(outcome, ApiResponse):
                    return outcome
                delay = outcome
            await self.sleep(delay)
            attempt += 1

    async def aclose(self) -> None:
        if self._owns_client:
            await self._client.aclose()


def _is_retryable(mode: RetryMode, error: ApiError) -> bool:
    # Seulement la limite générique : OTP_RESEND_TOO_SOON / OTP_RATE_LIMITED sont des limites métier.
    if error.status == 429:
        return error.code == "TOO_MANY_REQUESTS"
    if mode is not RetryMode.SAFE:
        return False
    # Requête d'origine encore en cours (même Idempotency-Key) : relancer après Retry-After est sans risque.
    return error.code == _IN_PROGRESS or error.status >= 500


def _retry_after_seconds(error: ApiError) -> Optional[float]:
    header = error.headers.get("retry-after", "").strip()
    if header:
        try:
            seconds = float(header)
        except ValueError:
            seconds = -1.0
            try:
                at = parsedate_to_datetime(header)
            except (TypeError, ValueError, IndexError):
                at = None
            if at is not None and at.tzinfo is not None:
                return max(0.0, at.timestamp() - time.time())
        if seconds >= 0:
            return seconds
    value = _to_int(error.details.get("retry_after_seconds"))
    return float(value) if value is not None else None


def _decode(content: bytes, status: int) -> Json:
    if not content.strip():
        return {}
    try:
        decoded = json.loads(content.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        decoded = None
        valid = False
    else:
        valid = True
    if not valid:
        raise UnexpectedResponseError("Unexpected API response: the body is not valid JSON.", status=status)
    if not isinstance(decoded, dict):
        raise UnexpectedResponseError("Unexpected API response: a JSON object was expected.", status=status)
    return decoded


def _describe(exc: Exception) -> str:
    """Message court et sans URL : l'exception d'origine n'est jamais chaînée (sa requête porte la clé)."""
    message = strip_query_strings(str(exc)).strip()
    name = type(exc).__name__
    return f"{name}: {message}" if message else name

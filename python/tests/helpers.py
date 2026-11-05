"""Outils de test : transport httpx simulé, réponses types, client sync/async interchangeables."""

from __future__ import annotations

import asyncio
import inspect
import json
from typing import Any, Callable, Dict, List, Mapping, Optional, Sequence, Union

import httpx

from labyrinthe_connect import AsyncLabyrinthe, Labyrinthe

TEST_KEY = "lsk_test_s3cr3tK3yV4lu3_doNotLeak"
LIVE_KEY = "lsk_live_s3cr3tK3yV4lu3_doNotLeak"
BASE_URL = "https://api.test.local/api/public/v1"

Responder = Callable[[httpx.Request], httpx.Response]


def message(**overrides: Any) -> Dict[str, Any]:
    """Un ``Message`` avec exactement les champs obligatoires de la spec."""
    data: Dict[str, Any] = {
        "id": "9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10",
        "reference": "order-1042",
        "recipient": "243812345678",
        "sender": "MYSHOP",
        "encoding": "gsm7",
        "status": "queued",
        "segments": 1,
        "credits_charged": 1,
        "campaign_id": None,
        "provider_message_id": None,
        "error_code": None,
        "error_message": None,
        "created_at": "2026-11-05T10:00:00+00:00",
        "submitted_at": None,
        "delivered_at": None,
    }
    data.update(overrides)
    return data


def json_response(status: int, body: Any, headers: Optional[Mapping[str, str]] = None) -> Responder:
    def respond(request: httpx.Request) -> httpx.Response:
        return httpx.Response(
            status,
            content=json.dumps(body).encode(),
            headers={
                "Content-Type": "application/json",
                **(headers or {}),
            },
        )

    return respond


def raw_response(status: int, content: bytes, headers: Optional[Mapping[str, str]] = None) -> Responder:
    def respond(request: httpx.Request) -> httpx.Response:
        return httpx.Response(status, content=content, headers=dict(headers or {}))

    return respond


def api_error(
    status: int,
    code: str,
    details: Any = None,
    headers: Optional[Mapping[str, str]] = None,
    message_text: str = "Erreur de test.",
) -> Responder:
    return json_response(
        status,
        {"error": {"code": code, "message": message_text, "details": {} if details is None else details}},
        headers,
    )


def network_error(text: str = "Connection refused") -> Responder:
    def respond(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError(text, request=request)

    return respond


def timeout_error() -> Responder:
    def respond(request: httpx.Request) -> httpx.Response:
        raise httpx.ReadTimeout("timed out", request=request)

    return respond


def balance(value: int = 5) -> Responder:
    return json_response(200, {"data": {"balance": value, "held": 0}})


def page(items: Sequence[Dict[str, Any]], **meta: int) -> Responder:
    body = {
        "data": list(items),
        "links": {"first": None, "last": None, "prev": None, "next": None},
        "meta": {
            "current_page": 1,
            "from": 1,
            "last_page": 1,
            "path": BASE_URL + "/sms",
            "per_page": 15,
            "to": 1,
            "total": len(items),
            **meta,
        },
    }
    return json_response(200, body)


class Recorder:
    """Transport simulé : sert les réponses dans l'ordre et enregistre les requêtes."""

    def __init__(self, responses: Sequence[Responder]) -> None:
        self.responses: List[Responder] = list(responses)
        self.requests: List[httpx.Request] = []
        self.sleeps: List[float] = []

    def __call__(self, request: httpx.Request) -> httpx.Response:
        request.read()
        self.requests.append(request)
        if not self.responses:
            raise AssertionError(f"Unexpected request: {request.method} {request.url}")
        return self.responses.pop(0)(request)

    def json(self, index: int = -1) -> Any:
        return json.loads(self.requests[index].content)

    @property
    def paths(self) -> List[str]:
        return [r.url.path.replace("/api/public/v1", "") for r in self.requests]


def sync_client(recorder: Recorder, **options: Any) -> Labyrinthe:
    options.setdefault("api_key", TEST_KEY)
    options.setdefault("base_url", BASE_URL)
    client = Labyrinthe(http_client=httpx.Client(transport=httpx.MockTransport(recorder)), **options)
    client._transport.sleep = recorder.sleeps.append
    return client


def async_client(recorder: Recorder, **options: Any) -> AsyncLabyrinthe:
    options.setdefault("api_key", TEST_KEY)
    options.setdefault("base_url", BASE_URL)
    client = AsyncLabyrinthe(http_client=httpx.AsyncClient(transport=httpx.MockTransport(recorder)), **options)

    async def sleep(delay: float) -> None:
        recorder.sleeps.append(delay)

    client._transport.sleep = sleep
    return client


class Harness:
    """Même test pour les deux clients : ``call`` attend le résultat si la méthode est asynchrone."""

    def __init__(self, kind: str, responses: Sequence[Responder], **options: Any) -> None:
        self.kind = kind
        self.recorder = Recorder(responses)
        self.client: Union[Labyrinthe, AsyncLabyrinthe] = (
            sync_client(self.recorder, **options) if kind == "sync" else async_client(self.recorder, **options)
        )

    def call(self, fn: Callable[[Any], Any]) -> Any:
        result = fn(self.client)
        if inspect.isawaitable(result):

            async def wait() -> Any:
                return await result

            return asyncio.run(wait())
        return result

    @property
    def requests(self) -> List[httpx.Request]:
        return self.recorder.requests

    @property
    def sleeps(self) -> List[float]:
        return self.recorder.sleeps

    def json(self, index: int = -1) -> Any:
        return self.recorder.json(index)


HarnessFactory = Callable[..., Harness]

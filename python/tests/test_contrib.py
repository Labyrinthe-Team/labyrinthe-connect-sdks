"""Intégrations Django, FastAPI et Flask (ignorées si le framework n'est pas installé)."""

from __future__ import annotations

import time
from typing import Any, Dict, List

import pytest

from labyrinthe_connect import (
    BalanceLow,
    InvalidArgumentError,
    MessageStatusChanged,
    OtpFailed,
    OtpVerificationStatus,
    OtpVerified,
    UnknownEvent,
    WebhookEvent,
    webhooks,
)

from .test_webhooks import SECRET, envelope


def signed(event: str = "message.status_changed", data: Any = None) -> Dict[str, Any]:
    body = envelope(event, data, timestamp=int(time.time()))
    return {"body": body, "signature": webhooks.sign(body, SECRET)}


# --- Django --------------------------------------------------------------------------------------


@pytest.fixture(scope="module")
def django_setup() -> None:
    pytest.importorskip("django")
    import django
    from django.conf import settings

    if not settings.configured:
        settings.configure(
            DEBUG=False,
            SECRET_KEY="tests-only",
            ROOT_URLCONF=__name__,
            ALLOWED_HOSTS=["testserver"],
            MIDDLEWARE=["django.middleware.csrf.CsrfViewMiddleware"],
            LABYRINTHE_WEBHOOK_SECRET=SECRET,
            USE_TZ=True,
        )
        django.setup()


urlpatterns: List[Any] = []


def _django_routes() -> None:
    from django.urls import path

    from labyrinthe_connect.contrib.django import WebhookView

    urlpatterns[:] = [
        path("hook/", WebhookView.as_view()),
        path("hook-explicit/", WebhookView.as_view(secret="explicit-secret", tolerance=0)),
    ]


def test_django_view(django_setup: None) -> None:
    from django.test import Client

    from labyrinthe_connect.contrib import django as contrib

    _django_routes()
    received: List[WebhookEvent] = []
    typed: List[WebhookEvent] = []

    def on_any(sender: Any, event: WebhookEvent, **kwargs: Any) -> None:
        assert sender is type(event)
        assert "request" in kwargs
        received.append(event)

    def on_status(sender: Any, event: WebhookEvent, **kwargs: Any) -> None:
        typed.append(event)

    contrib.webhook_received.connect(on_any, weak=False)
    contrib.message_status_changed.connect(on_status, weak=False)
    try:
        client = Client(enforce_csrf_checks=True)
        ok = signed()
        response = client.post("/hook/", ok["body"], content_type="application/json", HTTP_X_SIGNATURE=ok["signature"])
        assert response.status_code == 200
        assert response.json() == {"received": True}
        assert len(received) == 1
        assert isinstance(typed[0], MessageStatusChanged)

        bad = client.post("/hook/", ok["body"], content_type="application/json", HTTP_X_SIGNATURE="sha256=00")
        assert bad.status_code == 400
        assert bad.json()["error"]["code"] == "SIGNATURE_VERIFICATION_FAILED"
        assert client.get("/hook/").status_code == 405
        assert len(received) == 1

        unknown = signed("something.new", {"x": 1})
        response = client.post(
            "/hook/", unknown["body"], content_type="application/json", HTTP_X_SIGNATURE=unknown["signature"]
        )
        assert response.status_code == 200
        assert isinstance(received[-1], UnknownEvent)
    finally:
        contrib.webhook_received.disconnect(on_any)
        contrib.message_status_changed.disconnect(on_status)


def test_django_view_with_an_explicit_secret_and_failing_receiver(django_setup: None) -> None:
    from django.test import Client

    from labyrinthe_connect.contrib import django as contrib

    _django_routes()
    body = envelope("balance.low", {"balance": 1, "threshold": 2}, timestamp=1)
    client = Client(raise_request_exception=True)
    response = client.post(
        "/hook-explicit/",
        body,
        content_type="application/json",
        HTTP_X_SIGNATURE=webhooks.sign(body, "explicit-secret"),
    )
    assert response.status_code == 200

    def failing(sender: Any, event: WebhookEvent, **kwargs: Any) -> None:
        raise RuntimeError("database down")

    contrib.balance_low.connect(failing, weak=False)
    try:
        with pytest.raises(RuntimeError, match="database down"):
            client.post(
                "/hook-explicit/",
                body,
                content_type="application/json",
                HTTP_X_SIGNATURE=webhooks.sign(body, "explicit-secret"),
            )
    finally:
        contrib.balance_low.disconnect(failing)


def test_django_view_without_secret(django_setup: None, monkeypatch: pytest.MonkeyPatch) -> None:
    from django.conf import settings
    from django.core.exceptions import ImproperlyConfigured
    from django.test import Client

    _django_routes()
    monkeypatch.setattr(settings, "LABYRINTHE_WEBHOOK_SECRET", None)
    with pytest.raises(ImproperlyConfigured, match="LABYRINTHE_WEBHOOK_SECRET"):
        Client(raise_request_exception=True).post("/hook/", b"{}", content_type="application/json")


# --- FastAPI -------------------------------------------------------------------------------------


def test_fastapi_dependency(monkeypatch: pytest.MonkeyPatch) -> None:
    pytest.importorskip("fastapi")
    pytest.importorskip("httpx")
    from fastapi import Depends, FastAPI
    from fastapi.testclient import TestClient

    from labyrinthe_connect.contrib.fastapi import WebhookVerifier

    monkeypatch.setenv("LABYRINTHE_WEBHOOK_SECRET", SECRET)
    app = FastAPI()
    verify = WebhookVerifier()
    seen: List[WebhookEvent] = []

    @app.post("/hook")
    async def hook(event: WebhookEvent = Depends(verify)) -> Dict[str, str]:  # noqa: B008
        seen.append(event)
        return {"type": event.type}

    client = TestClient(app)
    ok = signed()
    response = client.post("/hook", content=ok["body"], headers={"X-Signature": ok["signature"]})
    assert response.status_code == 200
    assert response.json() == {"type": "message.status_changed"}
    assert isinstance(seen[0], MessageStatusChanged)

    bad = client.post("/hook", content=ok["body"], headers={"X-Signature": "sha256=" + "0" * 64})
    assert bad.status_code == 400
    assert bad.json()["detail"]["code"] == "SIGNATURE_VERIFICATION_FAILED"

    garbage = b"not json"
    invalid = client.post("/hook", content=garbage, headers={"X-Signature": webhooks.sign(garbage, SECRET)})
    assert invalid.status_code == 400
    assert invalid.json()["detail"]["code"] == "INVALID_WEBHOOK_PAYLOAD"
    assert len(seen) == 1
    assert "tolerance=300" in repr(verify)


def test_fastapi_router_with_sync_and_async_handlers() -> None:
    pytest.importorskip("fastapi")
    from fastapi import FastAPI
    from fastapi.testclient import TestClient

    from labyrinthe_connect.contrib.fastapi import create_webhook_router

    seen: List[WebhookEvent] = []

    async def on_async(event: WebhookEvent) -> None:
        seen.append(event)

    def on_sync(event: WebhookEvent) -> None:
        if isinstance(event, BalanceLow):
            raise RuntimeError("handler failed")
        seen.append(event)

    app = FastAPI()
    app.include_router(create_webhook_router(on_async, secret=SECRET))
    app.include_router(create_webhook_router(on_sync, path="/sync", secret=SECRET))

    client = TestClient(app, raise_server_exceptions=False)
    ok = signed()
    assert client.post("/webhooks/labyrinthe", content=ok["body"], headers={"X-Signature": ok["signature"]}).json() == {
        "received": True
    }
    assert client.post("/sync", content=ok["body"], headers={"X-Signature": ok["signature"]}).status_code == 200
    assert len(seen) == 2

    low = signed("balance.low", {"balance": 1, "threshold": 2})
    assert client.post("/sync", content=low["body"], headers={"X-Signature": low["signature"]}).status_code == 500
    assert client.post("/sync", content=low["body"]).status_code == 400


def test_fastapi_requires_a_secret() -> None:
    pytest.importorskip("fastapi")
    from fastapi import Depends, FastAPI
    from fastapi.testclient import TestClient

    from labyrinthe_connect.contrib.fastapi import WebhookVerifier

    app = FastAPI()

    @app.post("/hook")
    async def hook(event: WebhookEvent = Depends(WebhookVerifier())) -> None:  # noqa: B008
        return None

    with pytest.raises(InvalidArgumentError, match="LABYRINTHE_WEBHOOK_SECRET"):
        TestClient(app).post("/hook", content=b"{}")


# --- Flask ---------------------------------------------------------------------------------------


def test_flask_blueprint() -> None:
    pytest.importorskip("flask")
    from flask import Flask

    from labyrinthe_connect.contrib.flask import create_webhook_blueprint, webhook_received

    seen: List[WebhookEvent] = []
    signalled: List[WebhookEvent] = []

    def on_signal(sender: Any, event: WebhookEvent, **kwargs: Any) -> None:
        signalled.append(event)

    app = Flask(__name__)
    app.config["LABYRINTHE_WEBHOOK_SECRET"] = SECRET
    app.register_blueprint(create_webhook_blueprint(seen.append))
    app.register_blueprint(create_webhook_blueprint(name="prefixed", url_prefix="/v2", secret="other"))

    webhook_received.connect(on_signal, weak=False)
    try:
        client = app.test_client()
        ok = signed()
        response = client.post("/webhooks/labyrinthe", data=ok["body"], headers={"X-Signature": ok["signature"]})
        assert response.status_code == 200
        assert response.get_json() == {"received": True}
        assert isinstance(seen[0], MessageStatusChanged)
        assert len(signalled) == 1

        bad = client.post("/webhooks/labyrinthe", data=ok["body"], headers={"X-Signature": "sha256=nope"})
        assert bad.status_code == 400
        assert bad.get_json()["error"]["code"] == "SIGNATURE_VERIFICATION_FAILED"

        # Le secret explicite du second blueprint l'emporte sur la configuration.
        other = envelope(timestamp=int(time.time()))
        response = client.post(
            "/v2/webhooks/labyrinthe", data=other, headers={"X-Signature": webhooks.sign(other, "other")}
        )
        assert response.status_code == 200
        assert client.get("/webhooks/labyrinthe").status_code == 405
        assert len(seen) == 1
        assert len(signalled) == 2
    finally:
        webhook_received.disconnect(on_signal)


def test_flask_without_secret() -> None:
    pytest.importorskip("flask")
    from flask import Flask

    from labyrinthe_connect.contrib.flask import create_webhook_blueprint

    app = Flask(__name__)
    app.register_blueprint(create_webhook_blueprint())
    app.config["PROPAGATE_EXCEPTIONS"] = True
    with pytest.raises(InvalidArgumentError):
        app.test_client().post("/webhooks/labyrinthe", data=b"{}")


# --- Événements OTP (API 1.2.0) ------------------------------------------------------------------

OTP_DATA = {
    "otp_id": "9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10",
    "phone": "243812345678",
    "status": "verified",
    "reason": None,
    "verified_at": "2026-11-06T09:41:02+00:00",
}
OTP_FAILED_DATA = {**OTP_DATA, "status": "failed", "reason": "max_attempts_reached", "verified_at": None}


def test_django_otp_signals(django_setup: None) -> None:
    from django.test import Client

    from labyrinthe_connect.contrib import django as contrib

    _django_routes()
    verified: List[WebhookEvent] = []
    failed: List[WebhookEvent] = []

    def on_verified(sender: Any, event: WebhookEvent, **kwargs: Any) -> None:
        verified.append(event)

    def on_failed(sender: Any, event: WebhookEvent, **kwargs: Any) -> None:
        failed.append(event)

    contrib.otp_verified.connect(on_verified, weak=False)
    contrib.otp_failed.connect(on_failed, weak=False)
    try:
        client = Client()
        for name, data in (("otp.verified", OTP_DATA), ("otp.failed", OTP_FAILED_DATA)):
            hook = signed(name, data)
            response = client.post(
                "/hook/", hook["body"], content_type="application/json", HTTP_X_SIGNATURE=hook["signature"]
            )
            assert response.status_code == 200
        assert len(verified) == 1
        assert isinstance(verified[0], OtpVerified)
        assert len(failed) == 1
        assert isinstance(failed[0], OtpFailed)
        assert failed[0].data.status is OtpVerificationStatus.FAILED
    finally:
        contrib.otp_verified.disconnect(on_verified)
        contrib.otp_failed.disconnect(on_failed)


def test_fastapi_receives_otp_events() -> None:
    pytest.importorskip("fastapi")
    from fastapi import FastAPI
    from fastapi.testclient import TestClient

    from labyrinthe_connect.contrib.fastapi import create_webhook_router

    seen: List[WebhookEvent] = []
    app = FastAPI()
    app.include_router(create_webhook_router(seen.append, secret=SECRET))
    client = TestClient(app)
    for name, data in (("otp.verified", OTP_DATA), ("otp.failed", OTP_FAILED_DATA)):
        hook = signed(name, data)
        response = client.post("/webhooks/labyrinthe", content=hook["body"], headers={"X-Signature": hook["signature"]})
        assert response.status_code == 200
    assert [type(e) for e in seen] == [OtpVerified, OtpFailed]


def test_flask_receives_otp_events() -> None:
    pytest.importorskip("flask")
    from flask import Flask

    from labyrinthe_connect.contrib.flask import create_webhook_blueprint

    seen: List[WebhookEvent] = []
    app = Flask(__name__)
    app.register_blueprint(create_webhook_blueprint(seen.append, secret=SECRET))
    client = app.test_client()
    for name, data in (("otp.verified", OTP_DATA), ("otp.failed", OTP_FAILED_DATA)):
        hook = signed(name, data)
        response = client.post("/webhooks/labyrinthe", data=hook["body"], headers={"X-Signature": hook["signature"]})
        assert response.status_code == 200
    assert [type(e) for e in seen] == [OtpVerified, OtpFailed]

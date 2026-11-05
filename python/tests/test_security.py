"""Aucune fuite : clé API, codes OTP, corps de requête et numéros complets restent hors des logs et des erreurs."""

from __future__ import annotations

import logging
import pickle
import socket
import threading
import time
import traceback
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Iterator

import pytest

from labyrinthe_connect import (
    APIConnectionError,
    APITimeoutError,
    AsyncLabyrinthe,
    Labyrinthe,
    LabyrintheError,
)

from .helpers import TEST_KEY, HarnessFactory, api_error, json_response, message, network_error

#: Les tracebacks citent le code source des tests : le numéro n'y figure qu'à travers cette constante.
LOCAL_NUMBER = "08" + "12345678"
SECRET_PARTS = (TEST_KEY, "s3cr3t", "482913", "Votre code", "812345678", "Bearer")


def assert_clean(text: str) -> None:
    for part in SECRET_PARTS:
        assert part not in text, f"{part!r} leaked in {text!r}"


def full_traceback(error: BaseException) -> str:
    return "".join(traceback.format_exception(type(error), error, error.__traceback__))


def test_logs_never_contain_the_key_codes_bodies_or_full_numbers(
    harness: HarnessFactory, caplog: pytest.LogCaptureFixture
) -> None:
    caplog.set_level(logging.DEBUG, logger="labyrinthe_connect")
    otp = {
        "data": {"otp_id": "otp-1", "expires_at": "2026-11-05T10:05:00+00:00", "max_attempts": 3, "test_code": "482913"}
    }
    h = harness(
        [
            network_error(),
            json_response(202, {"data": [message()]}, {"Idempotent-Replayed": "true"}),
            json_response(201, otp),
            json_response(200, {"data": {"verified": True, "reason": None}}),
            json_response(200, {"data": [], "meta": {"current_page": 1, "last_page": 1, "per_page": 15, "total": 0}}),
            api_error(422, "VALIDATION_ERROR", {"phone": ["Votre code 482913"]}),
        ]
    )
    h.call(lambda c: c.sms.send(to="0812345678", body="Votre code 482913"))
    h.call(lambda c: c.otp.send(phone="0812345678", template="Votre code {code}"))
    h.call(lambda c: c.otp.verify(otp_id="otp-1", code="482913"))
    h.call(lambda c: c.sms.list(recipient="0812345678"))
    with pytest.raises(LabyrintheError):
        h.call(lambda c: c.otp.send(phone="0812345678"))

    messages = [record.getMessage() for record in caplog.records]
    assert any("POST /sms" in m for m in messages)
    assert any("retrying" in m for m in messages)
    assert any("idempotent replay" in m for m in messages)
    assert any("recipient=24381*****78" in m for m in messages)
    assert any("VALIDATION_ERROR" in m for m in messages)
    for text in messages:
        assert_clean(text)
    for record in caplog.records:
        assert_clean(repr(record.args))


def test_logs_nothing_without_configuration(harness: HarnessFactory, capsys: pytest.CaptureFixture[str]) -> None:
    h = harness([network_error(), json_response(200, {"data": {"balance": 1, "held": 0}})])
    h.call(lambda c: c.balance.get())
    captured = capsys.readouterr()
    assert captured.out == ""
    assert captured.err == ""


def test_network_errors_do_not_chain_the_httpx_exception(harness: HarnessFactory) -> None:
    h = harness([network_error("boom https://api.test.local/sms?recipient=243812345678 failed")], max_retries=0)
    with pytest.raises(APIConnectionError) as info:
        h.call(lambda c: c.balance.get())
    error = info.value
    assert error.__cause__ is None
    assert error.__context__ is None
    assert "?…" in error.message
    assert_clean(full_traceback(error))
    assert_clean(repr(error))


def test_errors_never_contain_the_authorization_header(harness: HarnessFactory) -> None:
    h = harness([api_error(401, "UNAUTHENTICATED")])
    with pytest.raises(LabyrintheError) as info:
        h.call(lambda c: c.balance.get())
    error = info.value
    assert_clean(full_traceback(error))
    assert_clean(repr(error.__dict__))
    assert_clean(repr(pickle.dumps(error)))


class _SlowHandler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:
        time.sleep(1.0)
        try:
            self.send_response(200)
            self.end_headers()
        except OSError:
            pass

    def log_message(self, format: str, *args: Any) -> None:  # noqa: A002 - signature imposée
        return


@pytest.fixture
def slow_server() -> Iterator[str]:
    server = ThreadingHTTPServer(("127.0.0.1", 0), _SlowHandler)
    server.daemon_threads = True
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    yield f"http://127.0.0.1:{server.server_address[1]}"
    server.shutdown()
    server.server_close()


@pytest.fixture
def closed_port() -> str:
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    return f"http://127.0.0.1:{port}"


def test_real_timeout(slow_server: str) -> None:
    with Labyrinthe(api_key=TEST_KEY, base_url=slow_server, timeout=0.2, max_retries=0) as client:
        started = time.monotonic()
        with pytest.raises(APITimeoutError) as info:
            client.balance.get()
    assert time.monotonic() - started < 0.9
    assert info.value.__context__ is None
    assert_clean(full_traceback(info.value))


async def test_real_timeout_async(slow_server: str) -> None:
    async with AsyncLabyrinthe(api_key=TEST_KEY, base_url=slow_server, timeout=0.2, max_retries=0) as client:
        with pytest.raises(APITimeoutError) as info:
            await client.balance.get()
    assert info.value.__context__ is None
    assert_clean(full_traceback(info.value))


def test_real_connection_refused(closed_port: str) -> None:
    client = Labyrinthe(api_key=TEST_KEY, base_url=closed_port, max_retries=0)
    with client, pytest.raises(APIConnectionError) as info:
        client.sms.list(recipient=LOCAL_NUMBER)
    assert not isinstance(info.value, APITimeoutError)
    assert info.value.__context__ is None
    assert_clean(full_traceback(info.value))


async def test_real_connection_refused_async(closed_port: str) -> None:
    async with AsyncLabyrinthe(api_key=TEST_KEY, base_url=closed_port, max_retries=0) as client:
        with pytest.raises(APIConnectionError) as info:
            await client.otp.send(phone=LOCAL_NUMBER)
    assert "may have been processed" in info.value.message
    assert_clean(full_traceback(info.value))

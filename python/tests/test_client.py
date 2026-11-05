from __future__ import annotations

import pickle
import platform
import re

import httpx
import pytest

import labyrinthe_connect
from labyrinthe_connect import DEFAULT_BASE_URL, AsyncLabyrinthe, InvalidArgumentError, Labyrinthe

from .helpers import LIVE_KEY, TEST_KEY, HarnessFactory, Recorder, balance, json_response, sync_client


def test_requires_an_api_key() -> None:
    with pytest.raises(InvalidArgumentError, match="LABYRINTHE_API_KEY"):
        Labyrinthe()
    with pytest.raises(InvalidArgumentError):
        AsyncLabyrinthe(api_key="   ")


def test_reads_the_key_and_base_url_from_the_environment(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("LABYRINTHE_API_KEY", TEST_KEY)
    monkeypatch.setenv("LABYRINTHE_BASE_URL", "http://localhost:8000")
    client = Labyrinthe()
    assert client.is_test_mode
    assert not client.is_live_mode
    assert client.base_url == "http://localhost:8000/api/public/v1"
    client.close()


def test_defaults_to_production() -> None:
    with Labyrinthe(api_key=LIVE_KEY) as client:
        assert client.base_url == DEFAULT_BASE_URL
        assert client.is_live_mode
        assert not client.is_test_mode


@pytest.mark.parametrize(
    ("given", "expected"),
    [
        ("http://localhost:8000", "http://localhost:8000/api/public/v1"),
        ("http://localhost:8000/", "http://localhost:8000/api/public/v1"),
        ("https://api.example.com/api/public/v1/", "https://api.example.com/api/public/v1"),
        ("https://proxy.example.com/labyrinthe", "https://proxy.example.com/labyrinthe"),
    ],
)
def test_normalizes_the_base_url(given: str, expected: str) -> None:
    with Labyrinthe(api_key=TEST_KEY, base_url=given) as client:
        assert client.base_url == expected


@pytest.mark.parametrize("base_url", ["localhost:8000", "ftp://example.com", "not a url", "https://"])
def test_rejects_invalid_base_urls(base_url: str) -> None:
    with pytest.raises(InvalidArgumentError, match="base_url"):
        Labyrinthe(api_key=TEST_KEY, base_url=base_url)


@pytest.mark.parametrize(
    "options",
    [
        {"timeout": 0},
        {"timeout": -1},
        {"max_retries": -1},
        {"max_retries": 11},
        {"max_retries": 1.5},
        {"max_retry_wait": -1},
        {"default_country_code": "+243"},
        {"default_country_code": "0243"},
    ],
)
def test_validates_options(options: dict[str, object]) -> None:
    with pytest.raises(InvalidArgumentError):
        Labyrinthe(api_key=TEST_KEY, **options)  # type: ignore[arg-type]


def test_rejects_an_http_client_of_the_wrong_kind() -> None:
    with pytest.raises(InvalidArgumentError, match=r"httpx\.Client"):
        Labyrinthe(api_key=TEST_KEY, http_client=httpx.AsyncClient())  # type: ignore[arg-type]
    with pytest.raises(InvalidArgumentError, match=r"httpx\.AsyncClient"):
        AsyncLabyrinthe(api_key=TEST_KEY, http_client=httpx.Client())  # type: ignore[arg-type]


def test_sends_authentication_identity_and_accept_headers(harness: HarnessFactory) -> None:
    h = harness([balance()])
    h.call(lambda c: c.balance.get())
    request = h.requests[0]
    assert request.headers["authorization"] == f"Bearer {TEST_KEY}"
    assert request.headers["accept"] == "application/json"
    assert request.headers["user-agent"] == (
        f"labyrinthe-python/{labyrinthe_connect.__version__} Python/{platform.python_version()}"
    )
    assert re.fullmatch(r"labyrinthe-python/\d+\.\d+\.\d+ Python/\d+\.\d+\.\d+\S*", request.headers["user-agent"])
    assert "content-type" not in request.headers
    assert str(request.url) == "https://api.test.local/api/public/v1/balance"


def test_uses_the_timeout_per_attempt(harness: HarnessFactory) -> None:
    h = harness([balance(), balance()], timeout=12.5)
    h.call(lambda c: c.balance.get())
    h.call(lambda c: c.balance.get(timeout=3))
    assert h.requests[0].extensions["timeout"] == httpx.Timeout(12.5).as_dict()
    assert h.requests[1].extensions["timeout"] == httpx.Timeout(3).as_dict()


def test_does_not_follow_redirects(harness: HarnessFactory) -> None:
    h = harness([json_response(302, {}, {"Location": "https://evil.example.com/steal"})])
    with pytest.raises(labyrinthe_connect.ApiError) as info:
        h.call(lambda c: c.balance.get())
    assert info.value.status == 302
    assert len(h.requests) == 1


def test_never_closes_a_caller_owned_http_client() -> None:
    recorder = Recorder([balance(), balance()])
    http = httpx.Client(transport=httpx.MockTransport(recorder))
    with Labyrinthe(api_key=TEST_KEY, http_client=http) as client:
        client.balance.get()
    assert not http.is_closed
    Labyrinthe(api_key=TEST_KEY, http_client=http).balance.get()
    http.close()


def test_closes_its_own_http_client() -> None:
    client = Labyrinthe(api_key=TEST_KEY)
    client.close()
    assert client._transport._client.is_closed


async def test_async_context_manager_closes_its_own_client() -> None:
    async with AsyncLabyrinthe(api_key=TEST_KEY) as client:
        assert client.is_test_mode
    assert client._transport._client.is_closed


def test_repr_hides_the_key() -> None:
    client = sync_client(Recorder([]))
    for text in (
        repr(client),
        str(client),
        repr(client.sms),
        repr(client.otp),
        repr(client.balance),
        repr(client.sender_ids),
        repr(client._transport),
    ):
        assert TEST_KEY not in text
        assert "s3cr3t" not in text
    assert "lsk_test_****" in repr(client)


def test_clients_cannot_be_pickled() -> None:
    client = sync_client(Recorder([]))
    with pytest.raises(TypeError, match="API key"):
        pickle.dumps(client)
    with pytest.raises(TypeError, match="API key"):
        pickle.dumps(AsyncLabyrinthe(api_key=TEST_KEY))
    with pytest.raises(TypeError):
        pickle.dumps(client._transport)


def test_exposes_the_version() -> None:
    assert re.fullmatch(r"\d+\.\d+\.\d+", labyrinthe_connect.__version__)


def test_version_matches_pyproject() -> None:
    from pathlib import Path

    pyproject = Path(__file__).resolve().parents[1] / "pyproject.toml"
    if not pyproject.exists():
        pytest.skip("pyproject.toml not shipped")
    declared = re.search(r'^version = "([^"]+)"', pyproject.read_text(encoding="utf-8"), re.MULTILINE)
    assert declared is not None
    assert declared.group(1) == labyrinthe_connect.__version__

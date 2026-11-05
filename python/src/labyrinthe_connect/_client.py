"""Clients synchrone et asynchrone (même surface)."""

from __future__ import annotations

import os
from types import TracebackType
from typing import Any, Optional, Tuple, Type

import httpx

from ._redact import redact_api_key
from ._transport import (
    DEFAULT_BASE_URL,
    DEFAULT_MAX_RETRIES,
    DEFAULT_MAX_RETRY_WAIT,
    DEFAULT_TIMEOUT,
    AsyncTransport,
    SyncTransport,
    normalize_base_url,
    validate_options,
)
from .errors import InvalidArgumentError
from .phone import DEFAULT_COUNTRY_CODE, assert_country_code
from .resources.account import AsyncBalanceResource, AsyncSenderIds, BalanceResource, SenderIds
from .resources.otp import AsyncOtp, Otp
from .resources.sms import AsyncSms, Sms

__all__ = ["DEFAULT_BASE_URL", "AsyncLabyrinthe", "Labyrinthe"]


def _env(name: str) -> Optional[str]:
    value = os.environ.get(name, "").strip()
    return value or None


class _Settings:
    """Options validées, communes aux deux clients."""

    def __init__(
        self,
        api_key: Optional[str],
        base_url: Optional[str],
        timeout: float,
        max_retries: int,
        max_retry_wait: float,
        default_country_code: str,
    ) -> None:
        key = (api_key if api_key is not None else _env("LABYRINTHE_API_KEY") or "").strip()
        if not key:
            raise InvalidArgumentError(
                "A Labyrinthe Connect API key is required (lsk_live_… or lsk_test_…): "
                "pass api_key or set LABYRINTHE_API_KEY."
            )
        validate_options(timeout, max_retries, max_retry_wait)
        assert_country_code(default_country_code)
        self.api_key = key
        self.base_url = normalize_base_url(base_url or _env("LABYRINTHE_BASE_URL") or DEFAULT_BASE_URL)


class _ClientMixin:
    _api_key: str
    _base_url: str

    @property
    def is_test_mode(self) -> bool:
        """True with a sandbox key (``lsk_test_…``): nothing is sent, no credit is used."""
        return self._api_key.startswith("lsk_test_")

    @property
    def is_live_mode(self) -> bool:
        """True with a production key (``lsk_live_…``)."""
        return self._api_key.startswith("lsk_live_")

    @property
    def base_url(self) -> str:
        """The API root used by this client."""
        return self._base_url

    def __repr__(self) -> str:
        return (
            f"{type(self).__name__}(api_key={redact_api_key(self._api_key)!r}, "
            f"base_url={self._base_url!r}, test_mode={self.is_test_mode})"
        )

    def __reduce__(self) -> Tuple[Any, ...]:
        raise TypeError(f"{type(self).__name__} cannot be pickled: it holds an API key. Create one per process.")


class Labyrinthe(_ClientMixin):
    """Labyrinthe Connect API client (synchronous).

    >>> client = Labyrinthe(api_key="lsk_test_…")             # or set LABYRINTHE_API_KEY
    >>> message = client.sms.send(to="0812345678", body="Hello!")

    Keys starting with ``lsk_test_`` use the sandbox: nothing is sent, no credit
    is used, responses have the same shape as in production. Use it as a
    context manager (``with Labyrinthe() as client:``) or call ``close()``.
    """

    #: Send and track SMS.
    sms: Sms
    #: Credit balance.
    balance: BalanceResource
    #: Approved Sender IDs.
    sender_ids: SenderIds
    #: One-time passwords by SMS.
    otp: Otp

    def __init__(
        self,
        api_key: Optional[str] = None,
        *,
        base_url: Optional[str] = None,
        timeout: float = DEFAULT_TIMEOUT,
        max_retries: int = DEFAULT_MAX_RETRIES,
        max_retry_wait: float = DEFAULT_MAX_RETRY_WAIT,
        default_country_code: str = DEFAULT_COUNTRY_CODE,
        default_sender: Optional[str] = None,
        http_client: Optional[httpx.Client] = None,
    ) -> None:
        """
        :param api_key: ``lsk_live_…`` or ``lsk_test_…``; defaults to ``LABYRINTHE_API_KEY``. Keep it server-side.
        :param base_url: API root; defaults to ``LABYRINTHE_BASE_URL``, then production. A bare host
            (``"http://localhost:8000"``) is completed with ``/api/public/v1``.
        :param timeout: timeout of each attempt, in seconds.
        :param max_retries: automatic retries, only when they are safe (0 disables them, max 10).
        :param max_retry_wait: longest wait accepted before a retry (``Retry-After``), in seconds.
        :param default_country_code: added to local numbers (``"0812345678"`` → ``"243812345678"``).
        :param default_sender: Sender ID used when ``sms.send()`` gets none.
        :param http_client: your own ``httpx.Client`` (proxies, TLS…); the SDK never closes it.
        """
        settings = _Settings(api_key, base_url, timeout, max_retries, max_retry_wait, default_country_code)
        self._api_key = settings.api_key
        self._base_url = settings.base_url
        self._transport = SyncTransport(
            settings.api_key, settings.base_url, timeout, max_retries, max_retry_wait, http_client
        )
        self.sms = Sms(self._transport, default_country_code, default_sender)
        self.balance = BalanceResource(self._transport)
        self.sender_ids = SenderIds(self._transport)
        self.otp = Otp(self._transport, default_country_code)

    def close(self) -> None:
        """Closes the HTTP connections (not those of an ``http_client`` you passed)."""
        self._transport.close()

    def __enter__(self) -> Labyrinthe:
        return self

    def __exit__(
        self,
        exc_type: Optional[Type[BaseException]],
        exc: Optional[BaseException],
        traceback: Optional[TracebackType],
    ) -> None:
        self.close()


class AsyncLabyrinthe(_ClientMixin):
    """Labyrinthe Connect API client (asyncio), with the same surface as :class:`Labyrinthe`.

    >>> async with AsyncLabyrinthe(api_key="lsk_test_…") as client:
    ...     message = await client.sms.send(to="0812345678", body="Hello!")
    """

    sms: AsyncSms
    balance: AsyncBalanceResource
    sender_ids: AsyncSenderIds
    otp: AsyncOtp

    def __init__(
        self,
        api_key: Optional[str] = None,
        *,
        base_url: Optional[str] = None,
        timeout: float = DEFAULT_TIMEOUT,
        max_retries: int = DEFAULT_MAX_RETRIES,
        max_retry_wait: float = DEFAULT_MAX_RETRY_WAIT,
        default_country_code: str = DEFAULT_COUNTRY_CODE,
        default_sender: Optional[str] = None,
        http_client: Optional[httpx.AsyncClient] = None,
    ) -> None:
        """Same options as :class:`Labyrinthe`; ``http_client`` is an ``httpx.AsyncClient``."""
        settings = _Settings(api_key, base_url, timeout, max_retries, max_retry_wait, default_country_code)
        self._api_key = settings.api_key
        self._base_url = settings.base_url
        self._transport = AsyncTransport(
            settings.api_key, settings.base_url, timeout, max_retries, max_retry_wait, http_client
        )
        self.sms = AsyncSms(self._transport, default_country_code, default_sender)
        self.balance = AsyncBalanceResource(self._transport)
        self.sender_ids = AsyncSenderIds(self._transport)
        self.otp = AsyncOtp(self._transport, default_country_code)

    async def aclose(self) -> None:
        """Closes the HTTP connections (not those of an ``http_client`` you passed)."""
        await self._transport.aclose()

    async def __aenter__(self) -> AsyncLabyrinthe:
        return self

    async def __aexit__(
        self,
        exc_type: Optional[Type[BaseException]],
        exc: Optional[BaseException],
        traceback: Optional[TracebackType],
    ) -> None:
        await self.aclose()

"""Credit balance (``client.balance``) and approved Sender IDs (``client.sender_ids``)."""

from __future__ import annotations

from typing import TYPE_CHECKING, List, Optional

from .._data import api, to_balance, to_sender_id
from .._transport import RetryMode
from ..types import Balance, SenderId

if TYPE_CHECKING:
    from .._transport import AsyncTransport, SyncTransport

__all__ = ["AsyncBalanceResource", "AsyncSenderIds", "BalanceResource", "SenderIds"]


class BalanceResource:
    """Credit balance (``client.balance``)."""

    def __init__(self, transport: SyncTransport) -> None:
        self._transport = transport

    def get(self, *, timeout: Optional[float] = None) -> Balance:
        response = self._transport.request("GET", "/balance", RetryMode.SAFE, timeout=timeout)
        return to_balance(api.obj(response.body, "data"))

    def __repr__(self) -> str:
        return f"BalanceResource({self._transport!r})"


class AsyncBalanceResource:
    """Credit balance (``client.balance`` of :class:`~labyrinthe_connect.AsyncLabyrinthe`)."""

    def __init__(self, transport: AsyncTransport) -> None:
        self._transport = transport

    async def get(self, *, timeout: Optional[float] = None) -> Balance:
        response = await self._transport.request("GET", "/balance", RetryMode.SAFE, timeout=timeout)
        return to_balance(api.obj(response.body, "data"))

    def __repr__(self) -> str:
        return f"AsyncBalanceResource({self._transport!r})"


class SenderIds:
    """Approved Sender IDs (``client.sender_ids``)."""

    def __init__(self, transport: SyncTransport) -> None:
        self._transport = transport

    def list(self, *, timeout: Optional[float] = None) -> List[SenderId]:
        """Sender IDs with the ``approved`` status, usable as ``sender``."""
        response = self._transport.request("GET", "/sender-ids", RetryMode.SAFE, timeout=timeout)
        return [to_sender_id(item) for item in api.objects(response.body, "data")]

    def __repr__(self) -> str:
        return f"SenderIds({self._transport!r})"


class AsyncSenderIds:
    """Approved Sender IDs (``client.sender_ids`` of :class:`~labyrinthe_connect.AsyncLabyrinthe`)."""

    def __init__(self, transport: AsyncTransport) -> None:
        self._transport = transport

    async def list(self, *, timeout: Optional[float] = None) -> List[SenderId]:
        """Sender IDs with the ``approved`` status, usable as ``sender``."""
        response = await self._transport.request("GET", "/sender-ids", RetryMode.SAFE, timeout=timeout)
        return [to_sender_id(item) for item in api.objects(response.body, "data")]

    def __repr__(self) -> str:
        return f"AsyncSenderIds({self._transport!r})"

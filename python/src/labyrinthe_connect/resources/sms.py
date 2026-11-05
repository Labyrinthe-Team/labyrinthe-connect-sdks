"""SMS: send, estimate, get and list (``client.sms``)."""

from __future__ import annotations

import uuid
from datetime import date, datetime, timezone
from typing import TYPE_CHECKING, Any, Dict, List, Optional, Sequence, Union
from urllib.parse import quote

from .._data import api, to_estimate, to_message
from .._transport import ApiResponse, Json, RetryMode
from ..errors import InvalidArgumentError, UnexpectedResponseError
from ..pagination import AsyncPage, AsyncPaginator, SyncPage
from ..phone import normalize, normalize_or_raise
from ..types import Estimate, Message, MessageBatch, MessageStatus

if TYPE_CHECKING:
    from .._transport import AsyncTransport, SyncTransport

__all__ = [
    "MAX_BODY_LENGTH",
    "MAX_IDEMPOTENCY_KEY_LENGTH",
    "MAX_PER_PAGE",
    "MAX_RECIPIENTS",
    "MAX_REFERENCE_LENGTH",
    "MAX_SENDER_LENGTH",
    "AsyncSms",
    "Sms",
]

MAX_BODY_LENGTH = 1600
MAX_RECIPIENTS = 1000
MAX_SENDER_LENGTH = 11
MAX_REFERENCE_LENGTH = 64
MAX_IDEMPOTENCY_KEY_LENGTH = 255
MAX_PER_PAGE = 100
DEFAULT_PER_PAGE = 15

DateFilter = Union[date, datetime, str]


class _SmsCore:
    """Validation et construction des requêtes, communes aux clients synchrone et asynchrone."""

    def __init__(self, default_country_code: str, default_sender: Optional[str]) -> None:
        self._default_country_code = default_country_code
        self._default_sender = default_sender or None

    def _single(self, to: str) -> Json:
        return {"recipient": normalize_or_raise(to, self._default_country_code, "to")}

    def _many(self, recipients: Sequence[str]) -> Json:
        if isinstance(recipients, (str, bytes)) or not isinstance(recipients, Sequence):
            raise InvalidArgumentError("recipients must be a list of phone numbers.")
        if not recipients:
            raise InvalidArgumentError("At least one recipient is required.")
        if len(recipients) > MAX_RECIPIENTS:
            raise InvalidArgumentError(f"At most {MAX_RECIPIENTS} recipients per request.")
        normalized: List[str] = []
        invalid: List[int] = []
        for index, recipient in enumerate(recipients):
            number = normalize(recipient, self._default_country_code) if isinstance(recipient, str) else None
            if number is None:
                invalid.append(index)
            else:
                normalized.append(number)
        if invalid:
            shown = ", ".join(str(i) for i in invalid[:20]) + ("…" if len(invalid) > 20 else "")
            # Les numéros eux-mêmes ne figurent jamais dans le message (seulement leur position).
            raise InvalidArgumentError(f"Invalid phone number(s) at recipients index {shown}. Nothing was sent.")
        return {"recipients": normalized}

    def _dispatch_request(
        self,
        target: Json,
        body: str,
        sender: Optional[str],
        reference: Optional[str],
        idempotency_key: Optional[str],
    ) -> tuple[Json, Dict[str, str], str]:
        _assert_body(body)
        sender = sender if sender is not None else self._default_sender
        if sender is not None and (not isinstance(sender, str) or not sender or len(sender) > MAX_SENDER_LENGTH):
            raise InvalidArgumentError(f"sender must be 1 to {MAX_SENDER_LENGTH} characters.")
        if reference is not None and (not isinstance(reference, str) or len(reference) > MAX_REFERENCE_LENGTH):
            raise InvalidArgumentError(f"reference must be at most {MAX_REFERENCE_LENGTH} characters.")
        if idempotency_key is not None and (
            not isinstance(idempotency_key, str)
            or not idempotency_key
            or len(idempotency_key) > MAX_IDEMPOTENCY_KEY_LENGTH
        ):
            raise InvalidArgumentError(f"idempotency_key must be 1 to {MAX_IDEMPOTENCY_KEY_LENGTH} characters.")

        # Générée une fois et réutilisée par chaque relance de cet appel.
        key = idempotency_key if idempotency_key is not None else str(uuid.uuid4())
        payload: Json = {**target, "body": body}
        if sender is not None:
            payload["sender"] = sender
        if reference:
            payload["reference"] = reference
        return payload, {"Idempotency-Key": key}, key

    @staticmethod
    def _batch(response: ApiResponse, idempotency_key: str) -> MessageBatch:
        return MessageBatch(
            messages=tuple(to_message(item) for item in api.objects(response.body, "data")),
            idempotency_key=idempotency_key,
            replayed=response.headers.get("idempotent-replayed") == "true",
        )

    @staticmethod
    def _first(batch: MessageBatch) -> Message:
        if not batch.messages:
            raise UnexpectedResponseError("Unexpected API response: no message was returned.")
        return batch.messages[0]

    def _estimate_body(self, body: str, recipients: Union[str, Sequence[str]]) -> Json:
        _assert_body(body)
        items = [recipients] if isinstance(recipients, str) else list(recipients)
        if not items:
            raise InvalidArgumentError("At least one recipient is required.")
        # Les numéros invalides sont transmis tels quels : l'API les ignore dans le décompte.
        return {
            "body": body,
            "recipients": [normalize(r, self._default_country_code) or r for r in items if isinstance(r, str)],
        }

    @staticmethod
    def _message_path(message_id: str) -> str:
        if not isinstance(message_id, str) or not message_id.strip():
            raise InvalidArgumentError("The message id cannot be empty.")
        return f"/sms/{quote(message_id, safe='')}"

    def _list_query(
        self,
        status: Union[MessageStatus, str, None],
        recipient: Optional[str],
        reference: Optional[str],
        from_: Optional[DateFilter],
        to: Optional[DateFilter],
        page: int,
        per_page: int,
    ) -> Dict[str, Any]:
        if isinstance(page, bool) or not isinstance(page, int) or page < 1:
            raise InvalidArgumentError("page must be an integer >= 1.")
        if isinstance(per_page, bool) or not isinstance(per_page, int) or not 1 <= per_page <= MAX_PER_PAGE:
            raise InvalidArgumentError(f"per_page must be an integer between 1 and {MAX_PER_PAGE}.")
        status_value: Optional[str] = None
        if status is not None:
            parsed = MessageStatus.parse(status)
            if parsed is MessageStatus.UNKNOWN:
                raise InvalidArgumentError(f"Unknown message status {status!r}.")
            status_value = parsed.value
        return {
            "status": status_value,
            "recipient": None if recipient is None else (normalize(recipient, self._default_country_code) or recipient),
            "reference": reference,
            "from": _format_date(from_),
            "to": _format_date(to),
            "per_page": per_page,
        }

    @staticmethod
    def _page_values(response: ApiResponse, page: int) -> tuple[List[Message], int, int, int, int]:
        meta = api.optional_obj(response.body, "meta")
        data = [to_message(item) for item in api.objects(response.body, "data")]
        current = api.nullable_integer(meta, "current_page")
        last = api.nullable_integer(meta, "last_page")
        per = api.nullable_integer(meta, "per_page")
        total = api.nullable_integer(meta, "total")
        return (
            data,
            current if current is not None else page,
            last if last is not None else page,
            per if per is not None else len(data),
            total if total is not None else len(data),
        )


class Sms(_SmsCore):
    """Send and track SMS (``client.sms``)."""

    def __init__(self, transport: SyncTransport, default_country_code: str, default_sender: Optional[str]) -> None:
        super().__init__(default_country_code, default_sender)
        self._transport = transport

    def send(
        self,
        *,
        to: str,
        body: str,
        sender: Optional[str] = None,
        reference: Optional[str] = None,
        idempotency_key: Optional[str] = None,
        timeout: Optional[float] = None,
    ) -> Message:
        """Sends one SMS. Asynchronous: the returned message is ``queued``.

        Follow its status with webhooks (``message.status_changed``) or ``get()``.
        An ``Idempotency-Key`` is always sent (a UUID4 when you give none), so
        network errors are retried safely: the SMS is never sent twice.
        """
        return self._first(self._dispatch(self._single(to), body, sender, reference, idempotency_key, timeout))

    def send_many(
        self,
        *,
        recipients: Sequence[str],
        body: str,
        sender: Optional[str] = None,
        reference: Optional[str] = None,
        idempotency_key: Optional[str] = None,
        timeout: Optional[float] = None,
    ) -> MessageBatch:
        """Sends the same SMS to up to 1000 recipients, all or nothing.

        If a single recipient is refused, nothing is sent and a
        :class:`~labyrinthe_connect.RecipientNotSendableError` lists every refused
        number. Duplicates are merged by the API (one message per distinct number).
        """
        return self._dispatch(self._many(recipients), body, sender, reference, idempotency_key, timeout)

    def estimate(
        self, *, body: str, recipients: Union[str, Sequence[str]], timeout: Optional[float] = None
    ) -> Estimate:
        """Computes encoding, segments and credits without sending anything."""
        response = self._transport.request(
            "POST", "/sms/estimate", RetryMode.SAFE, body=self._estimate_body(body, recipients), timeout=timeout
        )
        return to_estimate(api.obj(response.body, "data"))

    def get(self, id: str, *, timeout: Optional[float] = None) -> Message:  # noqa: A002 - API name
        """One message of the account, by id. Raises :class:`~labyrinthe_connect.NotFoundError` when unknown."""
        response = self._transport.request("GET", self._message_path(id), RetryMode.SAFE, timeout=timeout)
        return to_message(api.obj(response.body, "data"))

    def list(
        self,
        *,
        status: Union[MessageStatus, str, None] = None,
        recipient: Optional[str] = None,
        reference: Optional[str] = None,
        from_: Optional[DateFilter] = None,
        to: Optional[DateFilter] = None,
        page: int = 1,
        per_page: int = DEFAULT_PER_PAGE,
        timeout: Optional[float] = None,
    ) -> SyncPage[Message]:
        """Messages sent with this API key, newest first (one page; iterate it for every page).

        ``from_`` / ``to`` are inclusive: a ``date`` (or ``"2026-11-01"``) covers the
        whole day; a ``datetime`` must be timezone-aware.
        """
        query = self._list_query(status, recipient, reference, from_, to, page, per_page)

        def fetch(number: int) -> SyncPage[Message]:
            response = self._transport.request(
                "GET", "/sms", RetryMode.SAFE, query={**query, "page": number}, timeout=timeout
            )
            return SyncPage(*self._page_values(response, number), fetch_page=fetch)

        return fetch(page)

    def _dispatch(
        self,
        target: Json,
        body: str,
        sender: Optional[str],
        reference: Optional[str],
        idempotency_key: Optional[str],
        timeout: Optional[float],
    ) -> MessageBatch:
        payload, headers, key = self._dispatch_request(target, body, sender, reference, idempotency_key)
        response = self._transport.request(
            "POST", "/sms", RetryMode.SAFE, body=payload, headers=headers, timeout=timeout
        )
        return self._batch(response, key)

    def __repr__(self) -> str:
        return f"Sms({self._transport!r})"


class AsyncSms(_SmsCore):
    """Send and track SMS (``client.sms`` of :class:`~labyrinthe_connect.AsyncLabyrinthe`)."""

    def __init__(self, transport: AsyncTransport, default_country_code: str, default_sender: Optional[str]) -> None:
        super().__init__(default_country_code, default_sender)
        self._transport = transport

    async def send(
        self,
        *,
        to: str,
        body: str,
        sender: Optional[str] = None,
        reference: Optional[str] = None,
        idempotency_key: Optional[str] = None,
        timeout: Optional[float] = None,
    ) -> Message:
        """Sends one SMS. See :meth:`Sms.send`."""
        return self._first(await self._dispatch(self._single(to), body, sender, reference, idempotency_key, timeout))

    async def send_many(
        self,
        *,
        recipients: Sequence[str],
        body: str,
        sender: Optional[str] = None,
        reference: Optional[str] = None,
        idempotency_key: Optional[str] = None,
        timeout: Optional[float] = None,
    ) -> MessageBatch:
        """Sends the same SMS to up to 1000 recipients, all or nothing. See :meth:`Sms.send_many`."""
        return await self._dispatch(self._many(recipients), body, sender, reference, idempotency_key, timeout)

    async def estimate(
        self, *, body: str, recipients: Union[str, Sequence[str]], timeout: Optional[float] = None
    ) -> Estimate:
        """Computes encoding, segments and credits without sending anything."""
        response = await self._transport.request(
            "POST", "/sms/estimate", RetryMode.SAFE, body=self._estimate_body(body, recipients), timeout=timeout
        )
        return to_estimate(api.obj(response.body, "data"))

    async def get(self, id: str, *, timeout: Optional[float] = None) -> Message:  # noqa: A002 - API name
        """One message of the account, by id."""
        response = await self._transport.request("GET", self._message_path(id), RetryMode.SAFE, timeout=timeout)
        return to_message(api.obj(response.body, "data"))

    def list(
        self,
        *,
        status: Union[MessageStatus, str, None] = None,
        recipient: Optional[str] = None,
        reference: Optional[str] = None,
        from_: Optional[DateFilter] = None,
        to: Optional[DateFilter] = None,
        page: int = 1,
        per_page: int = DEFAULT_PER_PAGE,
        timeout: Optional[float] = None,
    ) -> AsyncPaginator[Message]:
        """Messages sent with this API key: ``await`` it for one page, ``async for`` for every page."""
        query = self._list_query(status, recipient, reference, from_, to, page, per_page)

        async def fetch(number: int) -> AsyncPage[Message]:
            response = await self._transport.request(
                "GET", "/sms", RetryMode.SAFE, query={**query, "page": number}, timeout=timeout
            )
            return AsyncPage(*self._page_values(response, number), fetch_page=fetch)

        return AsyncPaginator(lambda: fetch(page))

    async def _dispatch(
        self,
        target: Json,
        body: str,
        sender: Optional[str],
        reference: Optional[str],
        idempotency_key: Optional[str],
        timeout: Optional[float],
    ) -> MessageBatch:
        payload, headers, key = self._dispatch_request(target, body, sender, reference, idempotency_key)
        response = await self._transport.request(
            "POST", "/sms", RetryMode.SAFE, body=payload, headers=headers, timeout=timeout
        )
        return self._batch(response, key)

    def __repr__(self) -> str:
        return f"AsyncSms({self._transport!r})"


def _assert_body(body: Any) -> None:
    if not isinstance(body, str) or not body:
        raise InvalidArgumentError("The SMS body cannot be empty.")
    if len(body) > MAX_BODY_LENGTH:
        raise InvalidArgumentError(f"The SMS body must be at most {MAX_BODY_LENGTH} characters.")


def _format_date(value: Optional[DateFilter]) -> Optional[str]:
    if value is None or isinstance(value, str):
        return value
    if isinstance(value, datetime):
        if value.tzinfo is None or value.utcoffset() is None:
            raise InvalidArgumentError("Date-time filters must be timezone-aware (e.g. datetime.now(timezone.utc)).")
        return value.astimezone(timezone.utc).replace(microsecond=0).strftime("%Y-%m-%dT%H:%M:%SZ")
    if isinstance(value, date):
        return value.isoformat()
    raise InvalidArgumentError("Date filters must be a date, an aware datetime or an ISO 8601 string.")

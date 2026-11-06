"""Webhook verification and typed events.

::

    from labyrinthe_connect import webhooks

    event = webhooks.construct_event(request_body_bytes, request.headers.get("X-Signature"), secret)
    if isinstance(event, webhooks.MessageStatusChanged):
        update_message(event.data.message_id, event.data.status)
    elif isinstance(event, webhooks.OtpVerified):
        mark_phone_verified(event.data.otp_id)

The signature (``X-Signature: sha256=<hex HMAC-SHA256 of the raw body>``) is
checked in constant time (:func:`hmac.compare_digest`) on the RAW body, before
any JSON parsing; then the signed ``timestamp`` must be within the tolerance
(replay protection). Deduplicate on ``event.id``: deliveries are retried up
to 5 times and may arrive out of order.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any, ClassVar, Dict, Mapping, Optional, Union

from ._data import Json, Reader
from .errors import (
    InvalidArgumentError,
    InvalidWebhookPayloadError,
    SignatureVerificationError,
)
from .types import MessageStatus, OtpFailureReason, OtpVerificationStatus, WebhookEventType

__all__ = [
    "DEFAULT_TOLERANCE",
    "SIGNATURE_HEADER",
    "BalanceLow",
    "BalanceLowData",
    "CampaignCompleted",
    "CampaignCompletedData",
    "MessageStatusChanged",
    "MessageStatusChangedData",
    "OtpFailed",
    "OtpVerified",
    "OtpWebhookData",
    "RawBody",
    "TestPing",
    "UnknownEvent",
    "WebhookEvent",
    "construct_event",
    "sign",
    "verify_signature",
]

#: Name of the signature header.
SIGNATURE_HEADER = "X-Signature"
#: Default maximum age of a delivery, in seconds.
DEFAULT_TOLERANCE = 300
_PREFIX = "sha256="
_HEX = frozenset("0123456789abcdef")

#: Raw request body: the exact bytes received (or the text decoded as UTF-8). Never a parsed object.
RawBody = Union[bytes, bytearray, memoryview, str]

_reader = Reader("Invalid webhook payload", InvalidWebhookPayloadError)


@dataclass(frozen=True)
class _Event:
    #: Event identifier, identical across delivery attempts: deduplicate on it.
    id: str
    #: Event name exactly as sent by the API, e.g. ``"message.status_changed"`` (also for unknown events).
    type: str
    #: Time of this delivery attempt (signed), timezone-aware (UTC).
    timestamp: datetime
    #: Decoded envelope ``{"id", "event", "data", "timestamp"}``, as sent by the API.
    raw: Mapping[str, Any]


@dataclass(frozen=True)
class MessageStatusChangedData:
    message_id: str
    #: International format without "+". Personal data: mask it in your logs.
    recipient: str
    status: MessageStatus
    provider_message_id: Optional[str]
    campaign_id: Optional[str]
    #: ``reference`` given at send time (``sms.send(reference=...)``).
    reference: Optional[str] = None
    #: Provider or platform error code when the message failed.
    error_code: Optional[str] = None
    error_message: Optional[str] = None


@dataclass(frozen=True)
class MessageStatusChanged(_Event):
    """``message.status_changed``: the status of a message changed."""

    event: ClassVar[WebhookEventType] = WebhookEventType.MESSAGE_STATUS_CHANGED
    data: MessageStatusChangedData


@dataclass(frozen=True)
class CampaignCompletedData:
    campaign_id: str
    name: str
    recipients_count: int
    #: Number of messages per final status, e.g. ``{"delivered": 1150, "failed": 30}``.
    messages_by_status: Dict[str, int]
    credits_charged: int
    completed_at: Optional[datetime]


@dataclass(frozen=True)
class CampaignCompleted(_Event):
    """``campaign.completed``: every message of a campaign reached a final status."""

    event: ClassVar[WebhookEventType] = WebhookEventType.CAMPAIGN_COMPLETED
    data: CampaignCompletedData


@dataclass(frozen=True)
class BalanceLowData:
    #: Balance after the operation that crossed the threshold.
    balance: int
    threshold: int


@dataclass(frozen=True)
class BalanceLow(_Event):
    """``balance.low``: the balance went below the account's low-balance threshold."""

    event: ClassVar[WebhookEventType] = WebhookEventType.BALANCE_LOW
    data: BalanceLowData


@dataclass(frozen=True)
class OtpWebhookData:
    """Outcome of an OTP verification. Never contains the code."""

    otp_id: str
    #: Normalised number (international, without "+"). Personal data: mask it in your logs.
    phone: str
    #: ``verified`` with ``otp.verified``; ``failed`` or ``expired`` with ``otp.failed``.
    status: OtpVerificationStatus
    #: ``None`` when verified; ``max_attempts_reached`` (``failed``) or ``expired`` (``expired``).
    reason: Optional[OtpFailureReason]
    #: Verification time; ``None`` for ``otp.failed``.
    verified_at: Optional[datetime]


@dataclass(frozen=True)
class OtpVerified(_Event):
    """``otp.verified``: ``otp.verify()`` received the right code (sent once)."""

    event: ClassVar[WebhookEventType] = WebhookEventType.OTP_VERIFIED
    data: OtpWebhookData


@dataclass(frozen=True)
class OtpFailed(_Event):
    """``otp.failed``: attempts exhausted or code found expired at verification (sent once)."""

    event: ClassVar[WebhookEventType] = WebhookEventType.OTP_FAILED
    data: OtpWebhookData


@dataclass(frozen=True)
class TestPing(_Event):
    """``test.ping``: sent by the *Test* button of the client area."""

    __test__: ClassVar[bool] = False  # pas une classe de test pour pytest
    event: ClassVar[WebhookEventType] = WebhookEventType.TEST_PING
    data: Mapping[str, Any]


@dataclass(frozen=True)
class UnknownEvent(_Event):
    """An event type added to the API after this SDK version.

    Its signature was verified: ``type`` holds its name and ``data`` its
    content (as sent, snake_case). Acknowledge it with a 2xx.
    """

    event: ClassVar[WebhookEventType] = WebhookEventType.UNKNOWN
    data: Mapping[str, Any]


#: A verified webhook event; use ``isinstance`` (or ``match``) to get the typed ``data``.
WebhookEvent = Union[
    MessageStatusChanged, CampaignCompleted, BalanceLow, OtpVerified, OtpFailed, TestPing, UnknownEvent
]


def construct_event(
    raw_body: RawBody,
    signature_header: Optional[str],
    secret: str,
    tolerance: int = DEFAULT_TOLERANCE,
    *,
    now: Optional[float] = None,
) -> WebhookEvent:
    """Verifies a delivery and returns the typed event.

    :param raw_body: the exact request body (``request.body`` in Django, ``await request.body()``
        in FastAPI, ``request.get_data()`` in Flask) — never a parsed object.
    :param signature_header: value of the ``X-Signature`` header (``None`` when missing).
    :param secret: the endpoint secret (client area > Developers > Webhooks).
    :param tolerance: max age of the signed timestamp, in seconds; 0 disables the check (not recommended).
    :param now: current Unix time (tests).
    :raises SignatureVerificationError: wrong or missing signature, or timestamp out of tolerance — answer 400.
    :raises InvalidWebhookPayloadError: signed body that is not a valid envelope — answer 400.
    """
    body = _to_bytes(raw_body)
    if isinstance(tolerance, bool) or not isinstance(tolerance, (int, float)) or tolerance < 0:
        raise InvalidArgumentError("tolerance cannot be negative.")
    if not verify_signature(body, signature_header, secret):
        raise SignatureVerificationError("Invalid webhook signature: the body was not signed with this secret.")

    try:
        envelope: Any = json.loads(body.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        envelope = None
    if not isinstance(envelope, dict):
        raise InvalidWebhookPayloadError("The webhook body is not a JSON object.")

    timestamp = _reader.integer(envelope, "timestamp")
    current = time.time() if now is None else now
    if tolerance > 0 and abs(current - timestamp) > tolerance:
        raise SignatureVerificationError(
            f"Webhook timestamp is outside the {tolerance:g} s tolerance (possible replay)."
        )
    return _build_event(envelope, timestamp)


def verify_signature(raw_body: RawBody, signature_header: Optional[str], secret: str) -> bool:
    """Constant-time check of the ``X-Signature`` header against the raw body."""
    expected = sign(raw_body, secret)
    signature = (signature_header or "").strip().lower()
    if not signature.startswith(_PREFIX) or len(signature) != len(expected) or not set(signature[7:]) <= _HEX:
        return False
    return hmac.compare_digest(signature.encode("ascii"), expected.encode("ascii"))


def sign(raw_body: RawBody, secret: str) -> str:
    """The ``X-Signature`` header value for a body: ``"sha256=<hex>"``. Useful to test your endpoint."""
    if not isinstance(secret, str) or not secret:
        raise InvalidArgumentError("The webhook secret cannot be empty.")
    digest = hmac.new(secret.encode("utf-8"), _to_bytes(raw_body), hashlib.sha256).hexdigest()
    return _PREFIX + digest


def _to_bytes(raw_body: RawBody) -> bytes:
    if isinstance(raw_body, bytes):
        return raw_body
    if isinstance(raw_body, (bytearray, memoryview)):
        return bytes(raw_body)
    if isinstance(raw_body, str):
        return raw_body.encode("utf-8")
    raise InvalidArgumentError("The webhook body must be the RAW request body (bytes or str), not a parsed object.")


def _build_event(envelope: Json, timestamp: int) -> WebhookEvent:
    event_id = _reader.string(envelope, "id")
    name = _reader.string(envelope, "event")
    data = _reader.optional_obj(envelope, "data")
    try:
        sent_at = datetime.fromtimestamp(timestamp, tz=timezone.utc)
    except (OverflowError, OSError, ValueError):
        raise InvalidWebhookPayloadError('Invalid webhook payload: field "timestamp" is out of range.') from None
    base: Dict[str, Any] = {"id": event_id, "type": name, "timestamp": sent_at, "raw": envelope}

    if name == WebhookEventType.MESSAGE_STATUS_CHANGED.value:
        return MessageStatusChanged(
            **base,
            data=MessageStatusChangedData(
                message_id=_reader.string(data, "message_id"),
                recipient=_reader.string(data, "recipient"),
                status=MessageStatus.parse(_reader.string(data, "status")),
                provider_message_id=_reader.nullable_string(data, "provider_message_id"),
                campaign_id=_reader.nullable_string(data, "campaign_id"),
                # Ajoutés par l'API 1.2.0 : absents d'une ancienne livraison → None.
                reference=_reader.nullable_string(data, "reference"),
                error_code=_reader.nullable_string(data, "error_code"),
                error_message=_reader.nullable_string(data, "error_message"),
            ),
        )
    if name == WebhookEventType.CAMPAIGN_COMPLETED.value:
        counts = _reader.optional_obj(data, "messages_by_status")
        return CampaignCompleted(
            **base,
            data=CampaignCompletedData(
                campaign_id=_reader.string(data, "campaign_id"),
                name=_reader.nullable_string(data, "name") or "",
                recipients_count=_reader.nullable_integer(data, "recipients_count") or 0,
                messages_by_status={str(status): _reader.integer(counts, status) for status in counts},
                credits_charged=_reader.nullable_integer(data, "credits_charged") or 0,
                completed_at=_reader.nullable_date_time(data, "completed_at"),
            ),
        )
    if name == WebhookEventType.BALANCE_LOW.value:
        return BalanceLow(
            **base,
            data=BalanceLowData(balance=_reader.integer(data, "balance"), threshold=_reader.integer(data, "threshold")),
        )
    if name == WebhookEventType.OTP_VERIFIED.value:
        return OtpVerified(**base, data=_otp_data(data))
    if name == WebhookEventType.OTP_FAILED.value:
        return OtpFailed(**base, data=_otp_data(data))
    if name == WebhookEventType.TEST_PING.value:
        return TestPing(**base, data=data)
    return UnknownEvent(**base, data=data)


def _otp_data(data: Json) -> OtpWebhookData:
    reason = _reader.nullable_string(data, "reason")
    return OtpWebhookData(
        otp_id=_reader.string(data, "otp_id"),
        phone=_reader.string(data, "phone"),
        status=OtpVerificationStatus.parse(_reader.string(data, "status")),
        reason=None if reason is None else OtpFailureReason.parse(reason),
        verified_at=_reader.nullable_date_time(data, "verified_at"),
    )

"""Public types: snake_case versions of the API objects described in ``openapi/openapi.yaml``.

Models are frozen dataclasses, dates are timezone-aware ``datetime`` objects
and statuses are ``str`` enums. Each enum has an ``UNKNOWN`` member, never sent
by the API: the SDK uses it when the API returns a value this version does
not know yet.
"""

from __future__ import annotations

import enum
from dataclasses import dataclass
from datetime import datetime
from typing import Any, Optional, Tuple, Type, TypeVar

__all__ = [
    "Balance",
    "Encoding",
    "Estimate",
    "Message",
    "MessageBatch",
    "MessageStatus",
    "OtpFailureReason",
    "OtpLocale",
    "OtpRateLimitScope",
    "OtpSendResult",
    "OtpVerifyResult",
    "RejectedRecipient",
    "RejectionReason",
    "SenderId",
    "SenderIdStatus",
    "WebhookEventType",
]

_E = TypeVar("_E", bound="_StrEnum")


class _StrEnum(str, enum.Enum):
    """``str`` enum whose ``str()`` is the value on every Python version."""

    def __str__(self) -> str:
        return str(self.value)

    @classmethod
    def parse(cls: Type[_E], value: Any) -> _E:
        """The member for ``value``, or ``UNKNOWN`` for a value this SDK version does not know."""
        try:
            return cls(value)
        except ValueError:
            return cls("unknown")

    @classmethod
    def known(cls: Type[_E]) -> Tuple[_E, ...]:
        """Every member except ``UNKNOWN``."""
        return tuple(m for m in cls if m.value != "unknown")


class MessageStatus(_StrEnum):
    """Lifecycle of a message."""

    QUEUED = "queued"
    """Queued at Labyrinthe Connect."""
    SUBMITTED = "submitted"
    """Handed over to the SMS provider."""
    SENT = "sent"
    """Accepted by the mobile operator."""
    DELIVERED = "delivered"
    """Delivered to the handset (final)."""
    FAILED = "failed"
    """Failed (final)."""
    REJECTED = "rejected"
    """Rejected by the provider or the operator (final)."""
    EXPIRED = "expired"
    """Expired before delivery (final)."""
    UNDELIVERABLE = "undeliverable"
    """Cannot be delivered to this number (final)."""
    UNKNOWN = "unknown"
    """Status not known by this SDK version."""

    @property
    def is_final(self) -> bool:
        """True when the status will not change any more (delivered or failed)."""
        return self is MessageStatus.DELIVERED or self.is_failed

    @property
    def is_failed(self) -> bool:
        """True for ``failed``, ``rejected``, ``expired`` and ``undeliverable``."""
        return self in _FAILED

    @property
    def is_pending(self) -> bool:
        """True for ``queued``, ``submitted`` and ``sent``."""
        return self in _PENDING


_FAILED = frozenset({MessageStatus.FAILED, MessageStatus.REJECTED, MessageStatus.EXPIRED, MessageStatus.UNDELIVERABLE})
_PENDING = frozenset({MessageStatus.QUEUED, MessageStatus.SUBMITTED, MessageStatus.SENT})


class Encoding(_StrEnum):
    """Character encoding of an SMS: GSM-7 (160 characters) or UCS-2 (70 characters)."""

    GSM7 = "gsm7"
    UCS2 = "ucs2"
    UNKNOWN = "unknown"


class SenderIdStatus(_StrEnum):
    PENDING = "pending"
    PENDING_PROVIDER = "pending_provider"
    APPROVED = "approved"
    REJECTED = "rejected"
    SUSPENDED = "suspended"
    UNKNOWN = "unknown"


class RejectionReason(_StrEnum):
    """Why a recipient was refused (:class:`~labyrinthe_connect.RecipientNotSendableError`)."""

    INVALID_PHONE = "invalid_phone"
    BLACKLISTED = "blacklisted"
    OPTED_OUT = "opted_out"
    UNKNOWN = "unknown"


class OtpFailureReason(_StrEnum):
    """Why an OTP verification failed (``verified`` is false)."""

    INVALID_CODE = "invalid_code"
    """Wrong code: one attempt was consumed, the user may try again."""
    EXPIRED = "expired"
    """The code expired: send a new one."""
    ALREADY_USED = "already_used"
    """The code was already verified (each code is single-use)."""
    MAX_ATTEMPTS_REACHED = "max_attempts_reached"
    """Too many wrong attempts: the code is permanently blocked."""
    UNKNOWN = "unknown"


class OtpRateLimitScope(_StrEnum):
    """Scope of an ``OTP_RATE_LIMITED`` error: the phone number or the whole account."""

    PHONE = "phone"
    ACCOUNT = "account"
    UNKNOWN = "unknown"


class OtpLocale(_StrEnum):
    """Languages of the standard OTP templates."""

    FR = "fr"
    EN = "en"


class WebhookEventType(_StrEnum):
    """Webhook event types emitted by the API."""

    MESSAGE_STATUS_CHANGED = "message.status_changed"
    CAMPAIGN_COMPLETED = "campaign.completed"
    BALANCE_LOW = "balance.low"
    TEST_PING = "test.ping"
    UNKNOWN = "unknown"


@dataclass(frozen=True)
class Message:
    """An SMS, one per recipient. The text is deliberately not returned by the API (it may contain an OTP code)."""

    id: str
    #: Your reference, given at send time.
    reference: Optional[str]
    #: International format without "+", e.g. ``"243812345678"``.
    recipient: str
    #: Sender shown on the handset.
    sender: Optional[str]
    encoding: Optional[Encoding]
    status: MessageStatus
    segments: int
    #: Credits charged (equal to ``segments``; fictitious with a test key).
    credits_charged: int
    #: Campaign of origin (messages sent from the client area).
    campaign_id: Optional[str]
    provider_message_id: Optional[str]
    error_code: Optional[str]
    error_message: Optional[str]
    created_at: Optional[datetime]
    #: Handed over to the provider.
    submitted_at: Optional[datetime]
    #: Delivery receipt.
    delivered_at: Optional[datetime]


@dataclass(frozen=True)
class MessageBatch:
    """Result of ``sms.send_many()``: one message per distinct recipient."""

    messages: Tuple[Message, ...]
    #: The ``Idempotency-Key`` used for this send (yours, or the one generated by the SDK).
    idempotency_key: str
    #: True when the API replayed an earlier identical request: nothing was sent or billed again.
    replayed: bool

    def __len__(self) -> int:
        return len(self.messages)


@dataclass(frozen=True)
class Estimate:
    """Cost of a send, computed by the API without sending anything."""

    encoding: Encoding
    characters: int
    segments_per_message: int
    #: Valid recipients only (invalid numbers are ignored, not rejected).
    recipients_count: int
    total_credits: int
    #: Balance after the send (negative when insufficient); ``None`` if the account never had credits.
    balance_after: Optional[int]

    @property
    def is_affordable(self) -> bool:
        """True when the current balance covers the send (``balance_after >= 0``)."""
        return self.balance_after is not None and self.balance_after >= 0


@dataclass(frozen=True)
class Balance:
    """Credit balance, in SMS credits (1 credit = 1 segment)."""

    #: Available credits.
    balance: int
    #: Credits held by running campaigns.
    held: int


@dataclass(frozen=True)
class SenderId:
    """An approved sender name, usable as ``sender`` when sending."""

    id: str
    #: The name shown on the handset, e.g. ``"MYSHOP"`` (11 characters max).
    value: str
    status: SenderIdStatus
    purpose: Optional[str]
    rejection_reason: Optional[str]
    is_default: bool
    created_at: Optional[datetime]


@dataclass(frozen=True)
class RejectedRecipient:
    """A refused recipient of an all-or-nothing send."""

    #: Number as received, or normalised.
    recipient: str
    reason: RejectionReason


@dataclass(frozen=True)
class OtpVerifyResult:
    """Result of ``otp.verify()``. A wrong code is not an exception."""

    verified: bool
    #: ``None`` when ``verified`` is true.
    reason: Optional[OtpFailureReason]


class OtpSendResult:
    """Result of an OTP send.

    Store ``otp_id`` server-side (session, cache…) and pass it to
    ``otp.verify()`` with the code typed by the user. Immutable.
    """

    __slots__ = ("_expires_at", "_max_attempts", "_otp_id", "_test_code")
    _otp_id: str
    _expires_at: datetime
    _max_attempts: int
    _test_code: Optional[str]

    def __init__(self, otp_id: str, expires_at: datetime, max_attempts: int, test_code: Optional[str] = None) -> None:
        object.__setattr__(self, "_otp_id", otp_id)
        object.__setattr__(self, "_expires_at", expires_at)
        object.__setattr__(self, "_max_attempts", max_attempts)
        object.__setattr__(self, "_test_code", test_code)

    @property
    def otp_id(self) -> str:
        return self._otp_id

    @property
    def expires_at(self) -> datetime:
        return self._expires_at

    @property
    def max_attempts(self) -> int:
        return self._max_attempts

    @property
    def test_code(self) -> Optional[str]:
        """The code, ONLY with a test key (``lsk_test_…``).

        Nothing is sent by SMS in test mode, so the API returns the code to let
        you test ``verify()``. Always ``None`` with a live key. Never log it,
        never show it to end users. Hidden from ``repr()`` and dropped when pickled.
        """
        return self._test_code

    def __setattr__(self, name: str, value: Any) -> None:
        raise AttributeError(f"{type(self).__name__} is immutable")

    def __delattr__(self, name: str) -> None:
        raise AttributeError(f"{type(self).__name__} is immutable")

    def __eq__(self, other: object) -> bool:
        if not isinstance(other, OtpSendResult):
            return NotImplemented
        return (self.otp_id, self.expires_at, self.max_attempts) == (other.otp_id, other.expires_at, other.max_attempts)

    def __hash__(self) -> int:
        return hash((self.otp_id, self.expires_at, self.max_attempts))

    def __repr__(self) -> str:
        code = "None" if self.test_code is None else "'[redacted]'"
        return (
            f"OtpSendResult(otp_id={self.otp_id!r}, expires_at={self.expires_at!r}, "
            f"max_attempts={self.max_attempts!r}, test_code={code})"
        )

    def __reduce__(self) -> Tuple[Any, ...]:
        # Le code de test ne quitte jamais le processus (cache, file de tâches…).
        return (OtpSendResult, (self.otp_id, self.expires_at, self.max_attempts, None))

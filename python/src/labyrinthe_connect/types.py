"""Public types: snake_case versions of the API objects described in ``openapi/openapi.yaml``.

Models are frozen dataclasses, dates are timezone-aware ``datetime`` objects
and statuses are ``str`` enums. Each enum has an ``UNKNOWN`` member, never sent
by the API: the SDK uses it when the API returns a value this version does
not know yet.

Every object returned by an API call carries the ``request_id`` of the response
that delivered it (``X-Request-Id``, to give to support). It is left out of
equality and ``repr()``.
"""

from __future__ import annotations

import enum
from dataclasses import dataclass, field
from datetime import datetime
from typing import Any, Dict, Optional, Tuple, Type, TypeVar

__all__ = [
    "Balance",
    "Encoding",
    "Estimate",
    "Message",
    "MessageBatch",
    "MessageStatus",
    "OtpCodeType",
    "OtpFailureReason",
    "OtpLocale",
    "OtpRateLimitScope",
    "OtpSendResult",
    "OtpVerificationStatus",
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


class OtpCodeType(_StrEnum):
    """Alphabet of an OTP code (account's OTP settings)."""

    NUMERIC = "numeric"
    """Digits 0-9."""
    ALPHANUMERIC = "alphanumeric"
    """Upper-case letters and digits without ambiguous characters; typing is case-insensitive."""
    UNKNOWN = "unknown"


class OtpVerificationStatus(_StrEnum):
    """Outcome of an OTP verification (``otp.verified`` / ``otp.failed`` webhooks)."""

    VERIFIED = "verified"
    FAILED = "failed"
    """Attempts exhausted (``max_attempts_reached``)."""
    EXPIRED = "expired"
    UNKNOWN = "unknown"


class WebhookEventType(_StrEnum):
    """Webhook event types emitted by the API."""

    MESSAGE_STATUS_CHANGED = "message.status_changed"
    CAMPAIGN_COMPLETED = "campaign.completed"
    BALANCE_LOW = "balance.low"
    OTP_VERIFIED = "otp.verified"
    OTP_FAILED = "otp.failed"
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
    #: ``X-Request-Id`` of the response (for support); not compared.
    request_id: Optional[str] = field(default=None, compare=False, repr=False)


@dataclass(frozen=True)
class MessageBatch:
    """Result of ``sms.send_many()``: one message per distinct recipient."""

    messages: Tuple[Message, ...]
    #: The ``Idempotency-Key`` used for this send (yours, or the one generated by the SDK).
    idempotency_key: str
    #: True when the API replayed an earlier identical request: nothing was sent or billed again.
    replayed: bool
    #: ``X-Request-Id`` of the response (for support); not compared.
    request_id: Optional[str] = field(default=None, compare=False, repr=False)

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
    #: ``X-Request-Id`` of the response (for support); not compared.
    request_id: Optional[str] = field(default=None, compare=False, repr=False)

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
    #: ``X-Request-Id`` of the response (for support); not compared.
    request_id: Optional[str] = field(default=None, compare=False, repr=False)


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
    #: ``X-Request-Id`` of the response (for support); not compared.
    request_id: Optional[str] = field(default=None, compare=False, repr=False)


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
    #: Attempts left: an integer after ``invalid_code``, ``0`` with ``max_attempts_reached``, ``None`` when the
    #: code is verified, already used or expired (and with an API older than 1.2.0).
    attempts_remaining: Optional[int] = None
    #: ``X-Request-Id`` of the response (for support); not compared.
    request_id: Optional[str] = field(default=None, compare=False, repr=False)


class OtpSendResult:
    """Result of an OTP send.

    Store ``otp_id`` server-side (session, cache…) and pass it to
    ``otp.verify()`` with the code typed by the user. Immutable.

    Equality and hashing use the API data only (not ``test_code``, ``replayed``,
    ``idempotency_key`` nor ``request_id``): a replay equals the original send.
    """

    __slots__ = (
        "_code_length",
        "_code_type",
        "_expires_at",
        "_idempotency_key",
        "_max_attempts",
        "_otp_id",
        "_replayed",
        "_request_id",
        "_resend_after_seconds",
        "_test_code",
    )
    _otp_id: str
    _expires_at: datetime
    _max_attempts: int
    _test_code: Optional[str]
    _code_length: Optional[int]
    _code_type: OtpCodeType
    _resend_after_seconds: Optional[int]
    _replayed: bool
    _idempotency_key: Optional[str]
    _request_id: Optional[str]

    def __init__(
        self,
        otp_id: str,
        expires_at: datetime,
        max_attempts: int,
        test_code: Optional[str] = None,
        *,
        code_length: Optional[int] = None,
        code_type: OtpCodeType = OtpCodeType.UNKNOWN,
        resend_after_seconds: Optional[int] = None,
        replayed: bool = False,
        idempotency_key: Optional[str] = None,
        request_id: Optional[str] = None,
    ) -> None:
        object.__setattr__(self, "_otp_id", otp_id)
        object.__setattr__(self, "_expires_at", expires_at)
        object.__setattr__(self, "_max_attempts", max_attempts)
        object.__setattr__(self, "_test_code", test_code)
        object.__setattr__(self, "_code_length", code_length)
        object.__setattr__(self, "_code_type", code_type)
        object.__setattr__(self, "_resend_after_seconds", resend_after_seconds)
        object.__setattr__(self, "_replayed", replayed)
        object.__setattr__(self, "_idempotency_key", idempotency_key)
        object.__setattr__(self, "_request_id", request_id)

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
    def code_length(self) -> Optional[int]:
        """Number of characters of the code (4 to 8); ``None`` only with an API older than 1.2.0."""
        return self._code_length

    @property
    def code_type(self) -> OtpCodeType:
        """Alphabet of the code (``numeric`` or ``alphanumeric``): pick the right input field."""
        return self._code_type

    @property
    def resend_after_seconds(self) -> Optional[int]:
        """Seconds before a new send to the same number is accepted (while this code is not verified).

        Use it for a "Resend the code" countdown. ``None`` only with an API older than 1.2.0.
        """
        return self._resend_after_seconds

    @property
    def replayed(self) -> bool:
        """True when the API replayed an earlier identical request: no new SMS, nothing billed again."""
        return self._replayed

    @property
    def idempotency_key(self) -> Optional[str]:
        """The ``Idempotency-Key`` used for this send (yours, or the one generated by the SDK)."""
        return self._idempotency_key

    @property
    def request_id(self) -> Optional[str]:
        """``X-Request-Id`` of the response (for support)."""
        return self._request_id

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

    def _key(self) -> Tuple[Any, ...]:
        return (
            self.otp_id,
            self.expires_at,
            self.max_attempts,
            self.code_length,
            self.code_type,
            self.resend_after_seconds,
        )

    def __eq__(self, other: object) -> bool:
        if not isinstance(other, OtpSendResult):
            return NotImplemented
        return self._key() == other._key()

    def __hash__(self) -> int:
        return hash(self._key())

    def __repr__(self) -> str:
        code = "None" if self.test_code is None else "'[redacted]'"
        return (
            f"OtpSendResult(otp_id={self.otp_id!r}, expires_at={self.expires_at!r}, "
            f"max_attempts={self.max_attempts!r}, code_length={self.code_length!r}, "
            f"code_type={self.code_type.value!r}, resend_after_seconds={self.resend_after_seconds!r}, "
            f"replayed={self.replayed!r}, test_code={code})"
        )

    def __reduce__(self) -> Tuple[Any, ...]:
        # Le code de test ne quitte jamais le processus (cache, file de tâches…).
        return (_restore_otp_send_result, (self.otp_id, self.expires_at, self.max_attempts, self._state()))

    def _state(self) -> Dict[str, Any]:
        return {
            "code_length": self.code_length,
            "code_type": self.code_type,
            "resend_after_seconds": self.resend_after_seconds,
            "replayed": self.replayed,
            "idempotency_key": self.idempotency_key,
            "request_id": self.request_id,
        }


def _restore_otp_send_result(
    otp_id: str, expires_at: datetime, max_attempts: int, state: Dict[str, Any]
) -> OtpSendResult:
    """Reconstruction après ``pickle`` / ``copy`` : toujours sans ``test_code``."""
    return OtpSendResult(otp_id, expires_at, max_attempts, None, **state)

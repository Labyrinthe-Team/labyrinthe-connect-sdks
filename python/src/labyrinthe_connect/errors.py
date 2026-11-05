"""Exceptions raised by the SDK.

Every exception extends :class:`LabyrintheError` and carries a stable ``code``,
a ``message``, the HTTP ``status`` (``None`` without a response) and
``details``. Switch on ``code`` or on the class, never on ``message`` (API
messages are in French, for humans).

Exceptions never contain the API key, OTP codes or the original ``httpx``
exception (whose request holds the ``Authorization`` header).
"""

from __future__ import annotations

import json
import re
from collections.abc import Mapping
from typing import Any, Dict, Optional, Tuple, Type

from .types import OtpRateLimitScope, RejectedRecipient, RejectionReason

__all__ = [
    "APIConnectionError",
    "APITimeoutError",
    "ApiError",
    "AuthenticationError",
    "IdempotencyKeyReusedError",
    "InsufficientCreditsError",
    "InvalidArgumentError",
    "InvalidPhoneError",
    "InvalidWebhookPayloadError",
    "LabyrintheError",
    "NotFoundError",
    "OtpRateLimitedError",
    "OtpResendTooSoonError",
    "PermissionError",
    "RateLimitError",
    "RecipientNotSendableError",
    "SenderIdNotApprovedError",
    "ServiceUnavailableError",
    "SignatureVerificationError",
    "UnexpectedResponseError",
    "ValidationError",
]

ErrorDetails = Dict[str, Any]

_INT_RE = re.compile(r"-?[0-9]+")


def _unpickle(cls: Type[LabyrintheError], message: str, state: Dict[str, Any]) -> LabyrintheError:
    # Reconstruit l'exception sans repasser par __init__ (signatures différentes selon la classe).
    error = cls.__new__(cls)
    Exception.__init__(error, message)
    error.__dict__.update(state)
    return error


class LabyrintheError(Exception):
    """Base class of every error raised by the SDK: catch it to handle any failure in one place."""

    #: Code used when none is given (SDK-side errors).
    default_code = "LABYRINTHE_ERROR"

    def __init__(
        self,
        message: str,
        *,
        code: Optional[str] = None,
        status: Optional[int] = None,
        details: Optional[Mapping[str, Any]] = None,
    ) -> None:
        super().__init__(message)
        self.message: str = message
        #: Stable machine-readable code, e.g. ``"INSUFFICIENT_CREDITS"`` or ``"TIMEOUT"``.
        self.code: str = code if code is not None else self.default_code
        #: HTTP status of the response; ``None`` without a response (network, timeout, local validation…).
        self.status: Optional[int] = status
        #: Error details, depending on the code (``{}`` when there are none).
        self.details: ErrorDetails = dict(details) if details is not None else {}

    def __str__(self) -> str:
        return self.message

    def __repr__(self) -> str:
        return f"{type(self).__name__}(code={self.code!r}, status={self.status!r}, message={self.message!r})"

    def __reduce__(self) -> Tuple[Any, ...]:
        state = {k: v for k, v in self.__dict__.items() if not k.startswith("__")}
        return (_unpickle, (type(self), self.message, state))


class ApiError(LabyrintheError):
    """The API answered with an error (``{"error": {"code", "message", "details"}}``).

    Subclasses exist for the codes an integration usually handles; any other
    code (including codes added to the API later) is raised as a plain ``ApiError``.
    """

    default_code = "HTTP_ERROR"
    status: int

    def __init__(
        self,
        message: str,
        *,
        code: Optional[str] = None,
        status: int,
        details: Optional[Mapping[str, Any]] = None,
        headers: Optional[Mapping[str, str]] = None,
    ) -> None:
        super().__init__(message, code=code, status=status, details=details)
        #: Response headers (lower-case names).
        self.headers: Dict[str, str] = {k.lower(): v for k, v in (headers or {}).items()}

    @property
    def request_id(self) -> Optional[str]:
        """Request identifier for support (``X-Request-Id``), when the API provides one."""
        return self.headers.get("x-request-id")

    def _detail_int(self, key: str) -> Optional[int]:
        return _to_int(self.details.get(key))


class AuthenticationError(ApiError):
    """401: missing, invalid or revoked API key."""


class PermissionError(ApiError):  # noqa: A001 - same name as in the other SDKs
    """403: IP not allowed for this key (``FORBIDDEN``) or OTP disabled for the account (``OTP_DISABLED_FOR_ACCOUNT``).

    Shadows the built-in ``PermissionError`` when imported by name: prefer
    ``from labyrinthe_connect import errors`` then ``errors.PermissionError``.
    """


class NotFoundError(ApiError):
    """404: unknown message or ``otp_id`` (or one from another account)."""


class ValidationError(ApiError):
    """422 ``VALIDATION_ERROR``: one or more fields are missing or invalid."""

    @property
    def field_errors(self) -> Dict[str, Tuple[str, ...]]:
        """Error messages per field, e.g. ``{"body": ("Le champ body est obligatoire.",)}``."""
        errors: Dict[str, Tuple[str, ...]] = {}
        for field, messages in self.details.items():
            items = messages if isinstance(messages, list) else [messages]
            errors[str(field)] = tuple(str(m) for m in items if isinstance(m, (str, int, float, bool)))
        return errors


class RecipientNotSendableError(ApiError):
    """422 ``RECIPIENT_NOT_SENDABLE``: at least one recipient is invalid, blacklisted or opted out.

    Sending is all-or-nothing: NOTHING was sent and no credit was used.
    Remove the ``rejected`` numbers and send again.
    """

    @property
    def reason(self) -> RejectionReason:
        """Reason of the first rejection."""
        return RejectionReason.parse(self.details.get("reason"))

    @property
    def rejected(self) -> Tuple[RejectedRecipient, ...]:
        """Every rejected recipient (``sms.send`` / ``sms.send_many``). Empty for OTP sends."""
        items = self.details.get("rejected")
        if not isinstance(items, list):
            return ()
        return tuple(
            RejectedRecipient(recipient=item["recipient"], reason=RejectionReason.parse(item.get("reason")))
            for item in items
            if isinstance(item, dict) and isinstance(item.get("recipient"), str)
        )


class SenderIdNotApprovedError(ApiError):
    """422 ``SENDER_ID_NOT_APPROVED``: ``sender`` is not an approved Sender ID of the account."""


class InvalidPhoneError(ApiError):
    """422 ``INVALID_PHONE``: the phone number cannot be used (OTP)."""


class InsufficientCreditsError(ApiError):
    """409 ``INSUFFICIENT_CREDITS``: the balance does not cover the whole send. Nothing was sent."""

    @property
    def available(self) -> Optional[int]:
        """Credits available on the account."""
        return self._detail_int("available")

    @property
    def required(self) -> Optional[int]:
        """Credits needed for this send."""
        return self._detail_int("required")


class IdempotencyKeyReusedError(ApiError):
    """409 ``IDEMPOTENCY_KEY_REUSED``: the same ``Idempotency-Key`` was used for a different request."""


class RateLimitError(ApiError):
    """429: too many requests.

    The SDK already retried ``TOO_MANY_REQUESTS`` when it was safe to do so;
    when you get this error, wait ``retry_after`` seconds.
    """

    @property
    def retry_after(self) -> Optional[float]:
        """Seconds to wait, from ``Retry-After`` or ``details.retry_after_seconds``; ``None`` when unknown."""
        header = self.headers.get("retry-after", "").strip()
        if header:
            try:
                value = float(header)
            except ValueError:
                value = -1.0
            if value >= 0:
                return value
        seconds = self._detail_int("retry_after_seconds")
        return float(seconds) if seconds is not None else None


class OtpResendTooSoonError(RateLimitError):
    """429 ``OTP_RESEND_TOO_SOON``: a code was sent to this number too recently.

    Never retried; show a countdown of ``retry_after`` seconds.
    """


class OtpRateLimitedError(RateLimitError):
    """429 ``OTP_RATE_LIMITED``: hourly cap reached for this phone number or the whole account. Never retried."""

    @property
    def scope(self) -> OtpRateLimitScope:
        return OtpRateLimitScope.parse(self.details.get("scope"))

    @property
    def limit(self) -> Optional[int]:
        """The hourly cap that was reached."""
        return self._detail_int("limit")


class ServiceUnavailableError(ApiError):
    """5xx, including 503 ``OTP_SERVICE_DISABLED``."""


class APIConnectionError(LabyrintheError):
    """The API could not be reached (DNS, TLS, connection refused…).

    For ``otp.send()`` and ``otp.verify()`` the request may or may not have
    been processed: the SDK never retries them after a network error.
    """

    default_code = "CONNECTION_ERROR"


class APITimeoutError(APIConnectionError):
    """An attempt took longer than ``timeout``."""

    default_code = "TIMEOUT"


class InvalidArgumentError(LabyrintheError, ValueError):
    """Invalid input detected by the SDK, before any network call."""

    default_code = "INVALID_ARGUMENT"


class UnexpectedResponseError(LabyrintheError):
    """A successful response that the SDK cannot read (not JSON, missing field…)."""

    default_code = "UNEXPECTED_RESPONSE"


class SignatureVerificationError(LabyrintheError):
    """A webhook failed verification (missing or wrong signature, timestamp out of tolerance). Answer 400."""

    default_code = "SIGNATURE_VERIFICATION_FAILED"


class InvalidWebhookPayloadError(LabyrintheError):
    """A correctly signed webhook body is not a valid event envelope. Answer 400."""

    default_code = "INVALID_WEBHOOK_PAYLOAD"


#: Classes by API error code (checked against the spec by the contract tests).
ERROR_CLASSES_BY_CODE: Mapping[str, Type[ApiError]] = {
    "UNAUTHENTICATED": AuthenticationError,
    "FORBIDDEN": PermissionError,
    "OTP_DISABLED_FOR_ACCOUNT": PermissionError,
    "NOT_FOUND": NotFoundError,
    "VALIDATION_ERROR": ValidationError,
    "RECIPIENT_NOT_SENDABLE": RecipientNotSendableError,
    "SENDER_ID_NOT_APPROVED": SenderIdNotApprovedError,
    "INVALID_PHONE": InvalidPhoneError,
    "INSUFFICIENT_CREDITS": InsufficientCreditsError,
    "IDEMPOTENCY_KEY_REUSED": IdempotencyKeyReusedError,
    "TOO_MANY_REQUESTS": RateLimitError,
    "OTP_RESEND_TOO_SOON": OtpResendTooSoonError,
    "OTP_RATE_LIMITED": OtpRateLimitedError,
    "OTP_SERVICE_DISABLED": ServiceUnavailableError,
}


def error_from_response(status: int, raw_body: bytes, headers: Mapping[str, str]) -> ApiError:
    """Typed error of an error response: by code first, then by HTTP status, then ``ApiError``."""
    code: Optional[str] = None
    message: Optional[str] = None
    details: ErrorDetails = {}
    try:
        decoded: Any = json.loads(raw_body.decode("utf-8")) if raw_body else None
    except (ValueError, UnicodeDecodeError):
        decoded = None
    if isinstance(decoded, dict) and isinstance(decoded.get("error"), dict):
        error = decoded["error"]
        code = error["code"] if isinstance(error.get("code"), str) else None
        message = error["message"] if isinstance(error.get("message"), str) else None
        # L'API renvoie parfois [] au lieu de {} (BACKEND-TODO n° 4) : les deux donnent {}.
        details = error["details"] if isinstance(error.get("details"), dict) else {}

    if code is None:
        code = "TOO_MANY_REQUESTS" if status == 429 else "HTTP_ERROR"
    if message is None:
        message = f"Labyrinthe Connect API returned HTTP {status}."

    cls = ERROR_CLASSES_BY_CODE.get(code) or _class_for_status(status)
    return cls(message, code=code, status=status, details=details, headers=headers)


def _class_for_status(status: int) -> Type[ApiError]:
    if status == 401:
        return AuthenticationError
    if status == 403:
        return PermissionError
    if status == 404:
        return NotFoundError
    if status == 429:
        return RateLimitError
    if status >= 500:
        return ServiceUnavailableError
    return ApiError


def _to_int(value: Any) -> Optional[int]:
    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value
    if isinstance(value, float) and value.is_integer():
        return int(value)
    if isinstance(value, str) and _INT_RE.fullmatch(value.strip()):
        return int(value.strip())
    return None

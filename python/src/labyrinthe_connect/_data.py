"""Lecture typée du JSON de l'API (snake_case conservé).

Tolérant sur la représentation (entiers en chaîne acceptés), strict sur la
présence des champs obligatoires. Usage interne.
"""

from __future__ import annotations

import re
from datetime import datetime, timezone
from typing import Any, Callable, Dict, List, Mapping, Optional

from .errors import LabyrintheError, UnexpectedResponseError, _to_int
from .types import (
    Balance,
    Encoding,
    Estimate,
    Message,
    MessageStatus,
    OtpFailureReason,
    OtpVerifyResult,
    SenderId,
    SenderIdStatus,
)

Json = Dict[str, Any]

_FRACTION = re.compile(r"\.(\d+)")


def parse_datetime(value: str) -> Optional[datetime]:
    """ISO 8601 → ``datetime`` toujours *aware* (UTC si aucun fuseau). ``None`` si illisible."""
    text = value.strip()
    if text.endswith(("Z", "z")):
        text = text[:-1] + "+00:00"
    # Python 3.9/3.10 n'acceptent que 3 ou 6 décimales.
    text = _FRACTION.sub(lambda m: "." + (m.group(1) + "000000")[:6], text, count=1)
    try:
        parsed = datetime.fromisoformat(text)
    except ValueError:
        return None
    return parsed if parsed.tzinfo is not None else parsed.replace(tzinfo=timezone.utc)


class Reader:
    """Accès typé aux champs ; ``error`` construit l'exception (réponse API ou webhook)."""

    def __init__(
        self,
        context: str = "Unexpected API payload",
        error: Callable[[str], LabyrintheError] = UnexpectedResponseError,
    ) -> None:
        self._context = context
        self._error = error

    def invalid(self, key: str, expected: str) -> LabyrintheError:
        return self._error(f'{self._context}: field "{key}" should be {expected}.')

    def string(self, data: Mapping[str, Any], key: str) -> str:
        value = data.get(key)
        if isinstance(value, str):
            return value
        if isinstance(value, int) and not isinstance(value, bool):
            return str(value)
        raise self.invalid(key, "a string")

    def nullable_string(self, data: Mapping[str, Any], key: str) -> Optional[str]:
        return None if data.get(key) is None else self.string(data, key)

    def integer(self, data: Mapping[str, Any], key: str) -> int:
        value = _to_int(data.get(key))
        if value is None:
            raise self.invalid(key, "an integer")
        return value

    def nullable_integer(self, data: Mapping[str, Any], key: str) -> Optional[int]:
        return None if data.get(key) is None else self.integer(data, key)

    def boolean(self, data: Mapping[str, Any], key: str) -> bool:
        value = data.get(key)
        if isinstance(value, bool):
            return value
        if value in (0, 1):
            return bool(value == 1)
        raise self.invalid(key, "a boolean")

    def obj(self, data: Mapping[str, Any], key: str) -> Json:
        value = data.get(key)
        if isinstance(value, dict):
            return value
        raise self.invalid(key, "an object")

    def optional_obj(self, data: Mapping[str, Any], key: str) -> Json:
        """Objet facultatif : absent, nul ou ``[]`` (PHP) donnent ``{}``."""
        value = data.get(key)
        if value is None or value == []:
            return {}
        return self.obj(data, key)

    def objects(self, data: Mapping[str, Any], key: str) -> List[Json]:
        value = data.get(key)
        if not isinstance(value, list):
            raise self.invalid(key, "an array")
        items: List[Json] = []
        for item in value:
            if not isinstance(item, dict):
                raise self.invalid(f"{key}[]", "an object")
            items.append(item)
        return items

    def date_time(self, data: Mapping[str, Any], key: str) -> datetime:
        value = data.get(key)
        parsed = parse_datetime(value) if isinstance(value, str) else None
        if parsed is None:
            raise self.invalid(key, "an ISO 8601 date-time")
        return parsed

    def nullable_date_time(self, data: Mapping[str, Any], key: str) -> Optional[datetime]:
        return None if data.get(key) is None else self.date_time(data, key)


api = Reader()


def to_message(data: Json, request_id: Optional[str] = None) -> Message:
    encoding = api.nullable_string(data, "encoding")
    return Message(
        id=api.string(data, "id"),
        reference=api.nullable_string(data, "reference"),
        recipient=api.string(data, "recipient"),
        sender=api.nullable_string(data, "sender"),
        # Jamais null depuis l'API 1.2.0 ; tolérance gardée.
        encoding=None if encoding is None else Encoding.parse(encoding),
        status=MessageStatus.parse(api.string(data, "status")),
        segments=api.nullable_integer(data, "segments") or 0,
        credits_charged=api.nullable_integer(data, "credits_charged") or 0,
        campaign_id=api.nullable_string(data, "campaign_id"),
        provider_message_id=api.nullable_string(data, "provider_message_id"),
        error_code=api.nullable_string(data, "error_code"),
        error_message=api.nullable_string(data, "error_message"),
        created_at=api.nullable_date_time(data, "created_at"),
        submitted_at=api.nullable_date_time(data, "submitted_at"),
        delivered_at=api.nullable_date_time(data, "delivered_at"),
        request_id=request_id,
    )


def to_estimate(data: Json, request_id: Optional[str] = None) -> Estimate:
    return Estimate(
        encoding=Encoding.parse(api.string(data, "encoding")),
        characters=api.integer(data, "characters"),
        segments_per_message=api.integer(data, "segments_per_message"),
        recipients_count=api.integer(data, "recipients_count"),
        total_credits=api.integer(data, "total_credits"),
        balance_after=api.nullable_integer(data, "balance_after"),
        request_id=request_id,
    )


def to_balance(data: Json, request_id: Optional[str] = None) -> Balance:
    return Balance(balance=api.integer(data, "balance"), held=api.integer(data, "held"), request_id=request_id)


def to_sender_id(data: Json, request_id: Optional[str] = None) -> SenderId:
    return SenderId(
        id=api.string(data, "id"),
        value=api.string(data, "value"),
        status=SenderIdStatus.parse(api.string(data, "status")),
        purpose=api.nullable_string(data, "purpose"),
        rejection_reason=api.nullable_string(data, "rejection_reason"),
        is_default=data.get("is_default") is not None and api.boolean(data, "is_default"),
        created_at=api.nullable_date_time(data, "created_at"),
        request_id=request_id,
    )


def to_otp_verify_result(data: Json, request_id: Optional[str] = None) -> OtpVerifyResult:
    # attempts_remaining : absent avant l'API 1.2.0 → None.
    remaining = api.nullable_integer(data, "attempts_remaining")
    if api.boolean(data, "verified"):
        return OtpVerifyResult(verified=True, reason=None, attempts_remaining=remaining, request_id=request_id)
    return OtpVerifyResult(
        verified=False,
        reason=OtpFailureReason.parse(api.nullable_string(data, "reason")),
        attempts_remaining=remaining,
        request_id=request_id,
    )

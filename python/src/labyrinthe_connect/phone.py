"""Phone number helpers, consistent with the API's own normalisation.

The API expects international numbers without "+" (e.g. ``"243812345678"``).
Accepted input formats (with the default country code 243, DR Congo):

- ``"+243 81 234 5678"``, ``"00243812345678"`` → ``"243812345678"`` (explicit international prefix kept)
- ``"243812345678"`` → ``"243812345678"``
- ``"0812345678"``, ``"812345678"`` → ``"243812345678"`` (local format, default country code added)
- ``"+33 6 12 34 56 78"`` → ``"33612345678"`` (other countries are never blocked)

Validation is deliberately light (8 to 15 digits, E.164): the API remains the
authority on whether a number can receive SMS.
"""

from __future__ import annotations

import re
from typing import Optional

from .errors import InvalidArgumentError

__all__ = ["DEFAULT_COUNTRY_CODE", "is_valid", "mask", "normalize", "normalize_or_raise"]

#: Default country code added to local numbers (DR Congo).
DEFAULT_COUNTRY_CODE = "243"

_NON_DIGITS = re.compile(r"[^0-9]")
_COUNTRY_CODE = re.compile(r"[1-9][0-9]{0,2}")


def normalize(raw: str, default_country_code: str = DEFAULT_COUNTRY_CODE) -> Optional[str]:
    """Normalises a number, or returns ``None`` when it cannot be a valid phone number."""
    assert_country_code(default_country_code)
    value = raw.strip() if isinstance(raw, str) else ""
    if not value:
        return None

    explicit_international = value.startswith(("+", "00"))
    digits = _NON_DIGITS.sub("", value)

    if explicit_international:
        if value.startswith("00"):
            digits = digits[2:]
    elif not (digits.startswith(default_country_code) and len(digits) > 10):
        # Format local : on retire le « 0 » de tête et on ajoute l'indicatif par défaut.
        digits = default_country_code + digits.lstrip("0")

    return digits if 8 <= len(digits) <= 15 else None


def normalize_or_raise(raw: str, default_country_code: str = DEFAULT_COUNTRY_CODE, field: str = "phone") -> str:
    """Normalises a number or raises :class:`InvalidArgumentError` (the number is never part of the message)."""
    normalized = normalize(raw, default_country_code)
    if normalized is None:
        raise InvalidArgumentError(
            f'Invalid phone number in "{field}": expected an international number '
            "(e.g. 243812345678) or a local number."
        )
    return normalized


def is_valid(raw: str, default_country_code: str = DEFAULT_COUNTRY_CODE) -> bool:
    return normalize(raw, default_country_code) is not None


def mask(phone: str) -> str:
    """Masks a number for logs: ``"243812345678"`` → ``"24381*****78"``."""
    digits = _NON_DIGITS.sub("", phone)
    length = len(digits)
    if length <= 4:
        return "*" * length
    visible_start = min(5, max(1, length - 7))
    return digits[:visible_start] + "*" * (length - visible_start - 2) + digits[-2:]


def assert_country_code(country_code: str) -> None:
    if not isinstance(country_code, str) or not _COUNTRY_CODE.fullmatch(country_code):
        raise InvalidArgumentError('The default country code must be 1 to 3 digits without "+" (e.g. "243").')

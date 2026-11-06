from __future__ import annotations

from typing import Optional

import pytest

from labyrinthe_connect import InvalidArgumentError, phone


@pytest.mark.parametrize(
    ("raw", "expected"),
    [
        ("243812345678", "243812345678"),
        ("+243812345678", "243812345678"),
        ("+243 81 234 5678", "243812345678"),
        ("00243812345678", "243812345678"),
        ("0812345678", "243812345678"),
        ("812345678", "243812345678"),
        ("081-234-5678", "243812345678"),
        ("(081) 234 56 78", "243812345678"),
        ("+33 6 12 34 56 78", "33612345678"),
        ("0033612345678", "33612345678"),
        ("+1 415 555 2671", "14155552671"),
        ("  0899999999  ", "243899999999"),
        # Sans « + » : 11 chiffres ou plus ne commençant pas par 0 = déjà international (API 1.2.0).
        ("33612345678", "33612345678"),
        ("14155552671", "14155552671"),
        ("4915123456789", "4915123456789"),
        ("44 7911 123456", "447911123456"),
        # Formats RDC inchangés.
        ("081234567890", "24381234567890"),  # commence par 0 : local, même long
        ("8123456789", "2438123456789"),  # 10 chiffres : local
        ("243 81 234 5678", "243812345678"),
        ("", None),
        ("   ", None),
        ("abc", None),
        ("+12", None),
        ("+1234567890123456", None),
        ("٠٨١٢٣٤٥٦٧٨", None),  # chiffres non ASCII : refusés, comme côté API
    ],
)
def test_normalize(raw: str, expected: Optional[str]) -> None:
    assert phone.normalize(raw) == expected
    assert phone.is_valid(raw) is (expected is not None)


@pytest.mark.parametrize(
    "raw", ["243812345678", "33612345678", "+33612345678", "0812345678", "812345678", "00447911123456", "14155552671"]
)
def test_normalize_is_idempotent(raw: str) -> None:
    once = phone.normalize(raw)
    assert once is not None
    assert phone.normalize(once) == once


def test_normalize_with_another_default_country_code() -> None:
    assert phone.normalize("0612345678", "33") == "33612345678"
    assert phone.normalize("33612345678", "33") == "33612345678"
    assert phone.normalize("+243812345678", "33") == "243812345678"


def test_normalize_rejects_non_strings() -> None:
    assert phone.normalize(None) is None  # type: ignore[arg-type]
    assert phone.normalize(812345678) is None  # type: ignore[arg-type]


@pytest.mark.parametrize("code", ["", "+243", "0243", "2433", "abc"])
def test_invalid_country_codes(code: str) -> None:
    with pytest.raises(InvalidArgumentError):
        phone.normalize("0812345678", code)


def test_normalize_or_raise_never_echoes_the_number() -> None:
    assert phone.normalize_or_raise("0812345678") == "243812345678"
    with pytest.raises(InvalidArgumentError) as info:
        phone.normalize_or_raise("12-secret-34", field="to")
    assert "secret" not in str(info.value)
    assert '"to"' in str(info.value)


@pytest.mark.parametrize(
    ("raw", "masked"),
    [
        ("243812345678", "24381*****78"),
        ("+243 81 234 5678", "24381*****78"),
        ("33612345678", "3361*****78"),
        ("12345678", "1*****78"),
        ("1234", "****"),
        ("", ""),
    ],
)
def test_mask(raw: str, masked: str) -> None:
    assert phone.mask(raw) == masked

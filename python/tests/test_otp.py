from __future__ import annotations

import copy
import pickle
from datetime import datetime, timezone
from typing import Any

import pytest

from labyrinthe_connect import (
    InvalidArgumentError,
    OtpFailureReason,
    OtpLocale,
    OtpSendResult,
    OtpVerifyResult,
)

from .helpers import HarnessFactory, json_response

SENT = {"data": {"otp_id": "otp-1", "expires_at": "2026-11-05T10:05:00+00:00", "max_attempts": 3}}
SENT_TEST = {"data": {**SENT["data"], "test_code": "482913"}}


def test_send(harness: HarnessFactory) -> None:
    h = harness([json_response(201, SENT)])
    result: OtpSendResult = h.call(lambda c: c.otp.send(phone="0812345678"))
    assert h.json() == {"phone": "+243812345678"}
    assert h.requests[0].url.path.endswith("/otp/send")
    assert len(h.requests[0].headers["idempotency-key"]) == 36  # UUID4 généré par le SDK
    assert result.idempotency_key == h.requests[0].headers["idempotency-key"]
    assert result.replayed is False
    assert result.otp_id == "otp-1"
    assert result.expires_at == datetime(2026, 11, 5, 10, 5, tzinfo=timezone.utc)
    assert result.max_attempts == 3
    assert result.test_code is None


def test_send_keeps_a_short_foreign_number_in_e164(harness: HarnessFactory) -> None:
    """« +2481234567 » (Seychelles) ne doit pas partir sans « + »."""
    h = harness([json_response(201, SENT)])
    h.call(lambda c: c.otp.send(phone="+248 123 4567"))
    assert h.json() == {"phone": "+2481234567"}


def test_send_with_template_and_locale(harness: HarnessFactory) -> None:
    h = harness([json_response(201, SENT), json_response(201, SENT)])
    h.call(lambda c: c.otp.send(phone="+33612345678", template="Your MyShop code: {code}", locale="en"))
    assert h.json() == {"phone": "+33612345678", "template": "Your MyShop code: {code}", "locale": "en"}
    h.call(lambda c: c.otp.send(phone="0812345678", locale=OtpLocale.FR))
    assert h.json()["locale"] == "fr"


def test_send_returns_the_test_code_and_hides_it(harness: HarnessFactory) -> None:
    h = harness([json_response(201, SENT_TEST)])
    result: OtpSendResult = h.call(lambda c: c.otp.send(phone="0812345678"))
    assert result.test_code == "482913"
    assert "482913" not in repr(result)
    assert "[redacted]" in repr(result)
    assert "482913" not in str(result)
    assert b"482913" not in pickle.dumps(result)
    restored = pickle.loads(pickle.dumps(result))  # noqa: S301 - our own data
    assert restored == result
    assert restored.test_code is None
    assert copy.copy(result).test_code is None


def test_send_result_is_immutable() -> None:
    result = OtpSendResult("otp-1", datetime.now(timezone.utc), 3, "123456")
    with pytest.raises(AttributeError):
        result.otp_id = "other"  # type: ignore[misc]
    with pytest.raises(AttributeError):
        del result._test_code
    assert hash(result) == hash(OtpSendResult("otp-1", result.expires_at, 3))


@pytest.mark.parametrize(
    ("kwargs", "match"),
    [
        ({"phone": "abc"}, 'Invalid phone number in "phone"'),
        ({"phone": "0812345678", "template": "No token"}, "{code}"),
        ({"phone": "0812345678", "template": "{code}" + "x" * 1600}, "at most 1600"),
        ({"phone": "0812345678", "locale": "de"}, "locale"),
    ],
)
def test_send_validates_locally(harness: HarnessFactory, kwargs: Any, match: str) -> None:
    h = harness([])
    with pytest.raises(InvalidArgumentError, match=match):
        h.call(lambda c: c.otp.send(**kwargs))
    assert h.requests == []


def test_verify(harness: HarnessFactory) -> None:
    h = harness(
        [
            json_response(200, {"data": {"verified": True, "reason": None}}),
            json_response(200, {"data": {"verified": False, "reason": "invalid_code"}}),
            json_response(200, {"data": {"verified": False, "reason": "something_new"}}),
        ]
    )
    assert h.call(lambda c: c.otp.verify(otp_id="otp-1", code="48 29 13")) == OtpVerifyResult(True, None)
    assert h.json() == {"otp_id": "otp-1", "code": "48 29 13"}
    wrong = h.call(lambda c: c.otp.verify(otp_id="otp-1", code="000000"))
    assert wrong == OtpVerifyResult(False, OtpFailureReason.INVALID_CODE)
    assert h.call(lambda c: c.otp.verify(otp_id="otp-1", code="1")).reason is OtpFailureReason.UNKNOWN


@pytest.mark.parametrize(
    ("otp_id", "code"),
    [("", "123456"), ("otp-1", ""), ("otp-1", "   "), ("otp-1", "1" * 21)],
)
def test_verify_validates_locally_without_echoing_the_code(harness: HarnessFactory, otp_id: str, code: str) -> None:
    h = harness([])
    with pytest.raises(InvalidArgumentError) as info:
        h.call(lambda c: c.otp.verify(otp_id=otp_id, code=code))
    if code.strip():
        assert code not in str(info.value)
    assert h.requests == []

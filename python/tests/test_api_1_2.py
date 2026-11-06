"""Nouveautés de l'API 1.2.0 : X-Request-Id, X-Api-Version, strip_accents, OTP idempotent, webhooks OTP…

Chaque test qui utilise ``harness`` tourne avec le client synchrone ET asynchrone.
"""

from __future__ import annotations

import copy
import json
import pickle
from datetime import datetime, timedelta, timezone
from typing import Any, Dict

import pytest

from labyrinthe_connect import (
    AccountSuspendedError,
    APIConnectionError,
    ApiError,
    IdempotencyRequestInProgressError,
    InvalidArgumentError,
    LabyrintheError,
    MessageBatch,
    OtpCodeType,
    OtpFailed,
    OtpFailureReason,
    OtpRateLimitedError,
    OtpResendTooSoonError,
    OtpSendResult,
    OtpVerificationStatus,
    OtpVerified,
    OtpVerifyResult,
    WebhookEventType,
    construct_event,
    errors,
)
from labyrinthe_connect._data import parse_datetime
from labyrinthe_connect.webhooks import MessageStatusChanged

from .helpers import HarnessFactory, api_error, json_response, message, network_error, page
from .test_webhooks import NOW, SECRET, envelope, signature

RID = {"X-Request-Id": "req-1.2:abc_def-9", "X-Api-Version": "1"}
OTP_SENT: Dict[str, Any] = {
    "data": {
        "otp_id": "9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10",
        "expires_at": "2026-11-06T09:49:11+00:00",
        "max_attempts": 5,
        "code_length": 6,
        "code_type": "numeric",
        "resend_after_seconds": 60,
    }
}
ESTIMATE = {
    "data": {
        "encoding": "gsm7",
        "characters": 40,
        "segments_per_message": 1,
        "recipients_count": 1,
        "total_credits": 1,
        "balance_after": 9,
    }
}


# --- X-Request-Id et X-Api-Version ---------------------------------------------------------------


def test_every_response_object_carries_the_request_id(harness: HarnessFactory) -> None:
    h = harness(
        [
            json_response(202, {"data": [message()]}, RID),
            json_response(202, {"data": [message(), message(id="m2")]}, RID),
            json_response(200, ESTIMATE, RID),
            json_response(200, {"data": message()}, RID),
            json_response(200, {"data": {"balance": 3, "held": 0}}, RID),
            json_response(
                200,
                {
                    "data": [
                        {"id": "s1", "value": "MYSHOP", "status": "approved", "is_default": True, "created_at": None}
                    ]
                },
                RID,
            ),
            json_response(201, OTP_SENT, RID),
            json_response(200, {"data": {"verified": True, "reason": None, "attempts_remaining": None}}, RID),
        ]
    )
    rid = RID["X-Request-Id"]
    assert h.call(lambda c: c.sms.send(to="0812345678", body="Hi")).request_id == rid
    batch: MessageBatch = h.call(lambda c: c.sms.send_many(recipients=["0812345678", "0899999999"], body="Hi"))
    assert batch.request_id == rid
    assert {m.request_id for m in batch.messages} == {rid}
    assert h.call(lambda c: c.sms.estimate(body="Hi", recipients="0812345678")).request_id == rid
    assert h.call(lambda c: c.sms.get("m1")).request_id == rid
    assert h.call(lambda c: c.balance.get()).request_id == rid
    assert h.call(lambda c: c.sender_ids.list())[0].request_id == rid
    assert h.call(lambda c: c.otp.send(phone="0812345678")).request_id == rid
    assert h.call(lambda c: c.otp.verify(otp_id="otp-1", code="123456")).request_id == rid


def test_request_id_is_not_part_of_equality_nor_repr(harness: HarnessFactory) -> None:
    h = harness([json_response(200, {"data": message()}, RID), json_response(200, {"data": message()})])
    with_id = h.call(lambda c: c.sms.get("m1"))
    without = h.call(lambda c: c.sms.get("m1"))
    assert with_id == without
    assert without.request_id is None
    assert "request_id" not in repr(with_id)


def test_pages_carry_the_request_id(harness: HarnessFactory) -> None:
    def responder(request: Any) -> Any:
        return page([message()])(request)

    h = harness([lambda r: _with_headers(responder(r), RID)])
    result = h.call(lambda c: c.sms.list())
    assert result.request_id == RID["X-Request-Id"]
    assert result.data[0].request_id == RID["X-Request-Id"]


def _with_headers(response: Any, headers: Dict[str, str]) -> Any:
    response.headers.update(headers)
    return response


def test_sends_the_given_request_id_on_every_attempt(harness: HarnessFactory) -> None:
    h = harness(
        [network_error(), api_error(503, "HTTP_ERROR"), json_response(200, {"data": {"balance": 1, "held": 0}})]
    )
    h.call(lambda c: c.balance.get(request_id="order-1042.retry:1"))
    assert [r.headers["x-request-id"] for r in h.requests] == ["order-1042.retry:1"] * 3


def test_no_request_id_header_by_default(harness: HarnessFactory) -> None:
    h = harness([json_response(200, {"data": {"balance": 1, "held": 0}})])
    h.call(lambda c: c.balance.get())
    assert "x-request-id" not in h.requests[0].headers


@pytest.mark.parametrize(
    "call",
    [
        lambda c, rid: c.sms.send(to="0812345678", body="Hi", request_id=rid),
        lambda c, rid: c.sms.send_many(recipients=["0812345678"], body="Hi", request_id=rid),
        lambda c, rid: c.sms.estimate(body="Hi", recipients="0812345678", request_id=rid),
        lambda c, rid: c.sms.get("m1", request_id=rid),
        lambda c, rid: c.sms.list(request_id=rid),
        lambda c, rid: c.balance.get(request_id=rid),
        lambda c, rid: c.sender_ids.list(request_id=rid),
        lambda c, rid: c.otp.send(phone="0812345678", request_id=rid),
        lambda c, rid: c.otp.verify(otp_id="o", code="1", request_id=rid),
    ],
)
@pytest.mark.parametrize("bad", ["", "with space", "x" * 129, "é", "a/b", 42])
def test_every_method_rejects_an_invalid_request_id_locally(harness: HarnessFactory, call: Any, bad: Any) -> None:
    h = harness([])
    with pytest.raises(InvalidArgumentError, match="request_id"):
        h.call(lambda c: call(c, bad))
    assert h.requests == []


def test_api_errors_expose_request_id_and_api_version(harness: HarnessFactory) -> None:
    h = harness([api_error(422, "VALIDATION_ERROR", {"body": ["Le champ body est obligatoire."]}, RID)])
    with pytest.raises(ApiError) as info:
        h.call(lambda c: c.sms.send(to="0812345678", body="Hi"))
    error = info.value
    assert error.request_id == RID["X-Request-Id"]
    assert error.api_version == "1"
    assert f"request_id={RID['X-Request-Id']!r}" in repr(error)
    restored = pickle.loads(pickle.dumps(error))  # noqa: S301 - our own data
    assert restored.request_id == error.request_id


def test_network_errors_carry_the_request_id_you_sent(harness: HarnessFactory) -> None:
    h = harness([network_error(), network_error()], max_retries=0)
    with pytest.raises(APIConnectionError) as info:
        h.call(lambda c: c.balance.get(request_id="my-req-1"))
    assert info.value.request_id == "my-req-1"
    with pytest.raises(APIConnectionError) as anonymous:
        h.call(lambda c: c.balance.get())
    assert anonymous.value.request_id is None


def test_sdk_errors_have_no_request_id() -> None:
    assert LabyrintheError("x").request_id is None
    assert InvalidArgumentError("x").request_id is None


# --- Codes d'erreur ------------------------------------------------------------------------------


def test_account_suspended(harness: HarnessFactory) -> None:
    h = harness([api_error(403, "ACCOUNT_SUSPENDED", {}, RID)])
    with pytest.raises(AccountSuspendedError) as info:
        h.call(lambda c: c.sms.send(to="0812345678", body="Hi"))
    assert isinstance(info.value, errors.PermissionError)
    assert (info.value.status, info.value.code) == (403, "ACCOUNT_SUSPENDED")
    assert len(h.requests) == 1  # jamais relancée


def test_idempotency_request_in_progress_mapping(harness: HarnessFactory) -> None:
    h = harness([api_error(409, "IDEMPOTENCY_REQUEST_IN_PROGRESS", {"retry_after_seconds": 5})], max_retries=0)
    with pytest.raises(IdempotencyRequestInProgressError) as info:
        h.call(lambda c: c.sms.send(to="0812345678", body="Hi"))
    assert info.value.retry_after == 5  # details.retry_after_seconds sans en-tête


def test_details_are_always_an_object(harness: HarnessFactory) -> None:
    h = harness([api_error(409, "IDEMPOTENCY_KEY_REUSED", [])])
    with pytest.raises(errors.IdempotencyKeyReusedError) as info:
        h.call(lambda c: c.sms.send(to="0812345678", body="Hi"))
    assert info.value.details == {}


def test_otp_business_errors_expose_retry_after_from_the_header(harness: HarnessFactory) -> None:
    h = harness(
        [
            api_error(429, "OTP_RESEND_TOO_SOON", {"retry_after_seconds": 42}, {"Retry-After": "42"}),
            api_error(
                429,
                "OTP_RATE_LIMITED",
                {"retry_after_seconds": 1800, "scope": "phone", "limit": 5},
                {"Retry-After": "1800"},
            ),
        ]
    )
    with pytest.raises(OtpResendTooSoonError) as soon:
        h.call(lambda c: c.otp.send(phone="0812345678"))
    assert soon.value.retry_after == 42
    with pytest.raises(OtpRateLimitedError) as limited:
        h.call(lambda c: c.otp.send(phone="0812345678"))
    assert limited.value.retry_after == 1800
    assert limited.value.limit == 5
    assert h.sleeps == []
    assert len(h.requests) == 2


# --- strip_accents -------------------------------------------------------------------------------


def test_strip_accents_is_sent_only_when_true(harness: HarnessFactory) -> None:
    h = harness(
        [
            json_response(202, {"data": [message()]}),
            json_response(202, {"data": [message()]}),
            json_response(202, {"data": [message()]}),
            json_response(200, ESTIMATE),
            json_response(200, ESTIMATE),
            json_response(201, OTP_SENT),
        ]
    )
    h.call(lambda c: c.sms.send(to="0812345678", body="Réservation", strip_accents=True))
    assert h.json()["strip_accents"] is True
    h.call(lambda c: c.sms.send_many(recipients=["0812345678"], body="Réservation", strip_accents=True))
    assert h.json()["strip_accents"] is True
    h.call(lambda c: c.sms.send(to="0812345678", body="Réservation"))
    assert "strip_accents" not in h.json()
    h.call(lambda c: c.sms.estimate(body="Réservation", recipients=["0812345678"], strip_accents=True))
    assert h.json() == {"body": "Réservation", "recipients": ["+243812345678"], "strip_accents": True}
    h.call(lambda c: c.sms.estimate(body="Réservation", recipients=["0812345678"]))
    assert "strip_accents" not in h.json()
    h.call(lambda c: c.otp.send(phone="0812345678", strip_accents=True))
    assert h.json()["strip_accents"] is True


@pytest.mark.parametrize(
    "call",
    [
        lambda c: c.sms.send(to="0812345678", body="Hi", strip_accents="yes"),
        lambda c: c.sms.estimate(body="Hi", recipients="0812345678", strip_accents=1),
        lambda c: c.otp.send(phone="0812345678", strip_accents=None),
    ],
)
def test_strip_accents_must_be_a_boolean(harness: HarnessFactory, call: Any) -> None:
    h = harness([])
    with pytest.raises(InvalidArgumentError, match="strip_accents"):
        h.call(call)
    assert h.requests == []


# --- POST /otp/send ------------------------------------------------------------------------------


def test_otp_send_reads_the_code_shape(harness: HarnessFactory) -> None:
    alnum = {"data": {**OTP_SENT["data"], "code_length": 8, "code_type": "alphanumeric", "test_code": "AB23CD45"}}
    future = {"data": {**OTP_SENT["data"], "code_type": "emoji"}}
    h = harness([json_response(201, OTP_SENT), json_response(201, alnum), json_response(201, future)])
    result: OtpSendResult = h.call(lambda c: c.otp.send(phone="0812345678"))
    assert (result.code_length, result.code_type, result.resend_after_seconds) == (6, OtpCodeType.NUMERIC, 60)
    assert result.max_attempts == 5
    assert result.expires_at == datetime(2026, 11, 6, 9, 49, 11, tzinfo=timezone.utc)
    other: OtpSendResult = h.call(lambda c: c.otp.send(phone="0812345678"))
    assert (other.code_length, other.code_type) == (8, OtpCodeType.ALPHANUMERIC)
    assert "AB23CD45" not in repr(other)
    assert h.call(lambda c: c.otp.send(phone="0812345678")).code_type is OtpCodeType.UNKNOWN


def test_otp_send_tolerates_a_response_without_the_1_2_fields(harness: HarnessFactory) -> None:
    old = {"data": {"otp_id": "otp-1", "expires_at": "2026-11-05T10:05:00Z", "max_attempts": 3}}
    h = harness([json_response(201, old)])
    result: OtpSendResult = h.call(lambda c: c.otp.send(phone="0812345678"))
    assert (result.code_length, result.code_type, result.resend_after_seconds) == (None, OtpCodeType.UNKNOWN, None)


def test_otp_send_idempotency_key_and_replay(harness: HarnessFactory) -> None:
    replay = {"data": {**OTP_SENT["data"], "test_code": "482913"}}
    h = harness(
        [
            json_response(201, replay),
            json_response(201, replay, {"Idempotent-Replayed": "true"}),
        ]
    )
    first: OtpSendResult = h.call(lambda c: c.otp.send(phone="0812345678", idempotency_key="login-7"))
    again: OtpSendResult = h.call(lambda c: c.otp.send(phone="243812345678", idempotency_key="login-7"))
    assert [r.headers["idempotency-key"] for r in h.requests] == ["login-7", "login-7"]
    assert h.json(0) == h.json(1) == {"phone": "+243812345678"}
    assert (first.replayed, again.replayed) == (False, True)
    assert first == again
    assert again.idempotency_key == "login-7"


def test_each_otp_send_gets_its_own_generated_key(harness: HarnessFactory) -> None:
    h = harness([json_response(201, OTP_SENT), json_response(201, OTP_SENT)])
    h.call(lambda c: c.otp.send(phone="0812345678"))
    h.call(lambda c: c.otp.send(phone="0812345678"))
    keys = [r.headers["idempotency-key"] for r in h.requests]
    assert len(set(keys)) == 2


@pytest.mark.parametrize("key", ["", "x" * 256, 12])
def test_otp_send_rejects_an_invalid_idempotency_key(harness: HarnessFactory, key: Any) -> None:
    h = harness([])
    with pytest.raises(InvalidArgumentError, match="idempotency_key"):
        h.call(lambda c: c.otp.send(phone="0812345678", idempotency_key=key))
    assert h.requests == []


def test_otp_send_web_otp_domain(harness: HarnessFactory) -> None:
    h = harness([json_response(201, OTP_SENT)])
    h.call(lambda c: c.otp.send(phone="+33612345678", web_otp_domain="app.example.com"))
    assert h.json() == {"phone": "+33612345678", "web_otp_domain": "app.example.com"}


@pytest.mark.parametrize(
    "domain",
    ["localhost", "https://app.example.com", "app.example.com:443", "app.example.com/login", "-bad.example.com", ""],
)
def test_otp_send_rejects_an_invalid_web_otp_domain(harness: HarnessFactory, domain: str) -> None:
    h = harness([])
    with pytest.raises(InvalidArgumentError, match="web_otp_domain"):
        h.call(lambda c: c.otp.send(phone="0812345678", web_otp_domain=domain))
    assert h.requests == []


def test_otp_send_result_pickle_and_copy_keep_the_metadata_but_never_the_code() -> None:
    result = OtpSendResult(
        "otp-1",
        datetime(2026, 11, 6, tzinfo=timezone.utc),
        5,
        "482913",
        code_length=6,
        code_type=OtpCodeType.NUMERIC,
        resend_after_seconds=60,
        replayed=True,
        idempotency_key="k-1",
        request_id="r-1",
    )
    assert b"482913" not in pickle.dumps(result)
    for restored in (pickle.loads(pickle.dumps(result)), copy.copy(result), copy.deepcopy(result)):  # noqa: S301
        assert restored == result
        assert restored.test_code is None
        assert (restored.replayed, restored.idempotency_key, restored.request_id) == (True, "k-1", "r-1")
        assert restored.code_type is OtpCodeType.NUMERIC
    assert result != OtpSendResult("otp-1", result.expires_at, 5, code_length=8)
    with pytest.raises(AttributeError):
        result.code_length = 4  # type: ignore[misc]


# --- POST /otp/verify ----------------------------------------------------------------------------


def test_otp_verify_reads_attempts_remaining(harness: HarnessFactory) -> None:
    h = harness(
        [
            json_response(200, {"data": {"verified": False, "reason": "invalid_code", "attempts_remaining": 4}}),
            json_response(
                200, {"data": {"verified": False, "reason": "max_attempts_reached", "attempts_remaining": 0}}
            ),
            json_response(200, {"data": {"verified": True, "reason": None, "attempts_remaining": None}}),
            json_response(200, {"data": {"verified": False, "reason": "expired"}}),  # API < 1.2.0
        ]
    )
    wrong: OtpVerifyResult = h.call(lambda c: c.otp.verify(otp_id="o", code="000000"))
    assert (wrong.verified, wrong.reason, wrong.attempts_remaining) == (False, OtpFailureReason.INVALID_CODE, 4)
    assert h.call(lambda c: c.otp.verify(otp_id="o", code="000000")).attempts_remaining == 0
    assert h.call(lambda c: c.otp.verify(otp_id="o", code="1")) == OtpVerifyResult(True, None, None)
    assert h.call(lambda c: c.otp.verify(otp_id="o", code="1")).attempts_remaining is None


def test_otp_verify_is_retried_only_on_too_many_requests(harness: HarnessFactory) -> None:
    ok = json_response(200, {"data": {"verified": True, "reason": None, "attempts_remaining": None}})
    h = harness(
        [
            api_error(429, "TOO_MANY_REQUESTS", {"retry_after_seconds": 1}, {"Retry-After": "1"}),
            ok,
            api_error(409, "IDEMPOTENCY_REQUEST_IN_PROGRESS", {"retry_after_seconds": 1}, {"Retry-After": "1"}),
        ]
    )
    assert h.call(lambda c: c.otp.verify(otp_id="o", code="1")).verified
    with pytest.raises(IdempotencyRequestInProgressError):
        h.call(lambda c: c.otp.verify(otp_id="o", code="1"))
    assert "idempotency-key" not in h.requests[0].headers
    assert h.sleeps == [1.0]


# --- Dates ---------------------------------------------------------------------------------------


@pytest.mark.parametrize(
    ("text", "expected"),
    [
        ("2026-11-06T09:39:11+00:00", datetime(2026, 11, 6, 9, 39, 11, tzinfo=timezone.utc)),
        ("2026-11-06T09:39:11Z", datetime(2026, 11, 6, 9, 39, 11, tzinfo=timezone.utc)),
        ("2026-11-06T09:39:11.123456Z", datetime(2026, 11, 6, 9, 39, 11, 123456, tzinfo=timezone.utc)),
        ("2026-11-06T09:39:11.1+00:00", datetime(2026, 11, 6, 9, 39, 11, 100000, tzinfo=timezone.utc)),
        ("2026-11-06T09:39:11.123456789+00:00", datetime(2026, 11, 6, 9, 39, 11, 123456, tzinfo=timezone.utc)),
        ("2026-11-06T10:39:11+01:00", datetime(2026, 11, 6, 9, 39, 11, tzinfo=timezone.utc)),
        ("2026-11-06 09:39:11", datetime(2026, 11, 6, 9, 39, 11, tzinfo=timezone.utc)),
        ("not a date", None),
    ],
)
def test_parse_datetime(text: str, expected: Any) -> None:
    parsed = parse_datetime(text)
    assert parsed == expected
    if parsed is not None:
        assert parsed.utcoffset() is not None


# --- Webhooks ------------------------------------------------------------------------------------


def test_message_status_changed_has_reference_and_error() -> None:
    data = {
        "message_id": "9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10",
        "recipient": "243812345678",
        "status": "failed",
        "provider_message_id": None,
        "campaign_id": None,
        "reference": "CMD-1042",
        "error_code": "UNDELIVERED",
        "error_message": "Numéro injoignable",
    }
    body = envelope("message.status_changed", data)
    event = construct_event(body, signature(body), SECRET, now=NOW)
    assert isinstance(event, MessageStatusChanged)
    assert (event.data.reference, event.data.error_code, event.data.error_message) == (
        "CMD-1042",
        "UNDELIVERED",
        "Numéro injoignable",
    )
    # Livraison d'un backend antérieur à 1.2.0 : champs absents → None.
    old = envelope()
    previous = construct_event(old, signature(old), SECRET, now=NOW)
    assert isinstance(previous, MessageStatusChanged)
    assert (previous.data.reference, previous.data.error_code, previous.data.error_message) == (None, None, None)


def test_otp_verified_and_failed_events() -> None:
    verified_body = envelope(
        "otp.verified",
        {
            "otp_id": "9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10",
            "phone": "243812345678",
            "status": "verified",
            "reason": None,
            "verified_at": "2026-11-06T09:41:02+00:00",
        },
    )
    verified = construct_event(verified_body, signature(verified_body), SECRET, now=NOW)
    assert isinstance(verified, OtpVerified)
    assert verified.event is WebhookEventType.OTP_VERIFIED
    assert verified.data.status is OtpVerificationStatus.VERIFIED
    assert verified.data.reason is None
    assert verified.data.verified_at == datetime(2026, 11, 6, 9, 41, 2, tzinfo=timezone.utc)
    assert verified.data.phone == "243812345678"

    for status, reason in (("failed", "max_attempts_reached"), ("expired", "expired"), ("new", "new_reason")):
        body = envelope(
            "otp.failed",
            {"otp_id": "o-1", "phone": "243812345678", "status": status, "reason": reason, "verified_at": None},
        )
        failed = construct_event(body, signature(body), SECRET, now=NOW)
        assert isinstance(failed, OtpFailed)
        assert failed.event is WebhookEventType.OTP_FAILED
        assert failed.data.verified_at is None
        assert failed.data.status is OtpVerificationStatus.parse(status)
        assert failed.data.reason is OtpFailureReason.parse(reason)


def test_otp_event_with_a_missing_field_is_invalid() -> None:
    body = envelope("otp.failed", {"otp_id": "o-1", "status": "failed"})
    with pytest.raises(errors.InvalidWebhookPayloadError, match="phone"):
        construct_event(body, signature(body), SECRET, now=NOW)


def test_otp_event_json_roundtrip_never_contains_a_code() -> None:
    body = envelope("otp.verified", {"otp_id": "o", "phone": "243812345678", "status": "verified", "reason": None})
    event = construct_event(body, signature(body), SECRET, now=NOW)
    assert isinstance(event, OtpVerified)
    assert "code" not in json.dumps(dict(event.raw["data"]))
    assert event.timestamp - datetime.fromtimestamp(NOW, tz=timezone.utc) == timedelta(0)

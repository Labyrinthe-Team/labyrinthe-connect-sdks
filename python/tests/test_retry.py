from __future__ import annotations

from datetime import datetime, timedelta, timezone
from email.utils import format_datetime
from typing import Any

import pytest

from labyrinthe_connect import (
    APIConnectionError,
    APITimeoutError,
    FeatureDisabledError,
    IdempotencyKeyReusedError,
    IdempotencyKeyUnresolvedError,
    IdempotencyRequestInProgressError,
    OtpRateLimitedError,
    OtpResendTooSoonError,
    RateLimitError,
    ServiceUnavailableError,
    ValidationError,
)

from .helpers import (
    HarnessFactory,
    api_error,
    balance,
    json_response,
    message,
    network_error,
    timeout_error,
)

ESTIMATE = {
    "data": {
        "encoding": "gsm7",
        "characters": 5,
        "segments_per_message": 1,
        "recipients_count": 1,
        "total_credits": 1,
        "balance_after": 9,
    }
}
OTP_SENT = {"data": {"otp_id": "otp-1", "expires_at": "2026-11-05T10:05:00+00:00", "max_attempts": 3}}
VERIFIED = {"data": {"verified": True, "reason": None}}


def test_retries_a_get_on_5xx_then_succeeds(harness: HarnessFactory) -> None:
    h = harness([api_error(503, "HTTP_ERROR"), api_error(500, "HTTP_ERROR"), balance()])
    assert h.call(lambda c: c.balance.get()).balance == 5
    assert len(h.requests) == 3
    assert len(h.sleeps) == 2


def test_stops_after_max_retries(harness: HarnessFactory) -> None:
    h = harness([api_error(503, "HTTP_ERROR")] * 5, max_retries=2)
    with pytest.raises(ServiceUnavailableError):
        h.call(lambda c: c.balance.get())
    assert len(h.requests) == 3


def test_zero_max_retries_disables_retries(harness: HarnessFactory) -> None:
    h = harness([api_error(503, "HTTP_ERROR")], max_retries=0)
    with pytest.raises(ServiceUnavailableError):
        h.call(lambda c: c.balance.get())
    assert len(h.requests) == 1
    assert h.sleeps == []


def test_retries_a_get_on_network_errors(harness: HarnessFactory) -> None:
    h = harness([network_error(), balance()])
    assert h.call(lambda c: c.balance.get()).balance == 5
    assert len(h.requests) == 2


def test_retries_list_and_get_and_sender_ids(harness: HarnessFactory) -> None:
    h = harness(
        [
            network_error(),
            json_response(200, {"data": message()}),
            api_error(502, "HTTP_ERROR"),
            json_response(200, {"data": []}),
        ]
    )
    h.call(lambda c: c.sms.get("m1"))
    assert h.call(lambda c: c.sender_ids.list()) == []
    assert len(h.requests) == 4


def test_retries_estimate(harness: HarnessFactory) -> None:
    h = harness([network_error(), api_error(502, "HTTP_ERROR"), json_response(200, ESTIMATE)])
    assert h.call(lambda c: c.sms.estimate(body="Hello", recipients="0812345678")).total_credits == 1
    assert len(h.requests) == 3


def test_retries_a_send_with_the_same_idempotency_key_and_body(harness: HarnessFactory) -> None:
    h = harness(
        [
            timeout_error(),
            api_error(500, "HTTP_ERROR"),
            json_response(202, {"data": [message()]}, {"Idempotent-Replayed": "true"}),
        ]
    )
    h.call(lambda c: c.sms.send(to="0812345678", body="Hello"))
    assert len(h.requests) == 3
    keys = {r.headers["idempotency-key"] for r in h.requests}
    assert len(keys) == 1
    assert len({r.content for r in h.requests}) == 1


def test_waits_for_retry_after_on_429(harness: HarnessFactory) -> None:
    h = harness([api_error(429, "TOO_MANY_REQUESTS", {"retry_after_seconds": 3}, {"Retry-After": "3"}), balance()])
    h.call(lambda c: c.balance.get())
    assert h.sleeps == [3.0]


def test_uses_details_retry_after_seconds_without_header(harness: HarnessFactory) -> None:
    h = harness([api_error(429, "TOO_MANY_REQUESTS", {"retry_after_seconds": 4}), balance()])
    h.call(lambda c: c.balance.get())
    assert h.sleeps == [4.0]


def test_accepts_an_http_date_retry_after(harness: HarnessFactory) -> None:
    at = format_datetime(datetime.now(timezone.utc) + timedelta(seconds=2), usegmt=True)
    h = harness([api_error(429, "TOO_MANY_REQUESTS", {}, {"Retry-After": at}), balance()])
    h.call(lambda c: c.balance.get())
    assert 0 <= h.sleeps[0] <= 2


def test_does_not_wait_when_retry_after_exceeds_max_retry_wait(harness: HarnessFactory) -> None:
    h = harness([api_error(429, "TOO_MANY_REQUESTS", {}, {"Retry-After": "45"})], max_retry_wait=10)
    with pytest.raises(RateLimitError) as info:
        h.call(lambda c: c.balance.get())
    assert info.value.retry_after == 45
    assert len(h.requests) == 1
    assert h.sleeps == []


def test_429_without_retry_after_uses_backoff(harness: HarnessFactory) -> None:
    h = harness([api_error(429, "TOO_MANY_REQUESTS"), balance()])
    h.call(lambda c: c.balance.get())
    assert 0.25 <= h.sleeps[0] <= 0.5


def test_backs_off_exponentially_with_jitter_capped(harness: HarnessFactory) -> None:
    h = harness([api_error(503, "HTTP_ERROR")] * 4, max_retries=3)
    with pytest.raises(ServiceUnavailableError):
        h.call(lambda c: c.balance.get())
    assert len(h.sleeps) == 3
    for base, delay in zip([0.5, 1.0, 2.0], h.sleeps):
        assert base * 0.5 <= delay <= base

    capped = harness([api_error(503, "HTTP_ERROR")] * 3, max_retry_wait=0.1)
    with pytest.raises(ServiceUnavailableError):
        capped.call(lambda c: c.balance.get())
    assert all(delay <= 0.1 for delay in capped.sleeps)


def test_never_retries_client_errors(harness: HarnessFactory) -> None:
    h = harness([api_error(422, "VALIDATION_ERROR", {"body": ["x"]}), api_error(409, "IDEMPOTENCY_KEY_REUSED")])
    with pytest.raises(ValidationError):
        h.call(lambda c: c.sms.send(to="0812345678", body="Hello"))
    with pytest.raises(IdempotencyKeyReusedError):
        h.call(lambda c: c.sms.send(to="0812345678", body="Hello"))
    assert len(h.requests) == 2
    assert h.sleeps == []


def test_otp_send_is_retried_with_the_same_idempotency_key_and_body(harness: HarnessFactory) -> None:
    h = harness(
        [
            network_error(),
            timeout_error(),
            json_response(201, OTP_SENT, {"Idempotent-Replayed": "true"}),
        ],
        max_retries=2,
    )
    result = h.call(lambda c: c.otp.send(phone="0812345678"))
    assert result.otp_id == "otp-1"
    assert result.replayed is True
    assert len(h.requests) == 3
    keys = {r.headers["idempotency-key"] for r in h.requests}
    assert keys == {result.idempotency_key}
    assert len({r.content for r in h.requests}) == 1


def test_otp_send_is_retried_after_a_5xx_with_the_given_key(harness: HarnessFactory) -> None:
    h = harness([api_error(500, "HTTP_ERROR"), api_error(503, "HTTP_ERROR"), json_response(201, OTP_SENT)])
    result = h.call(lambda c: c.otp.send(phone="0812345678", idempotency_key="login-42"))
    assert result.idempotency_key == "login-42"
    assert [r.headers["idempotency-key"] for r in h.requests] == ["login-42"] * 3


def test_otp_send_network_error_after_the_last_retry_says_a_retry_is_safe(harness: HarnessFactory) -> None:
    h = harness([timeout_error()], timeout=2, max_retries=0)
    with pytest.raises(APITimeoutError, match="within 2 s") as info:
        h.call(lambda c: c.otp.send(phone="0812345678"))
    assert info.value.code == "TIMEOUT"
    assert "same idempotency_key is safe" in info.value.message
    assert len(h.requests) == 1


def test_otp_verify_is_never_retried_after_a_5xx_or_a_timeout(harness: HarnessFactory) -> None:
    h = harness([api_error(500, "HTTP_ERROR"), timeout_error()], timeout=2)
    with pytest.raises(ServiceUnavailableError):
        h.call(lambda c: c.otp.verify(otp_id="otp-1", code="123456"))
    with pytest.raises(APITimeoutError, match="may have been processed"):
        h.call(lambda c: c.otp.verify(otp_id="otp-1", code="123456"))
    assert len(h.requests) == 2


@pytest.mark.parametrize("operation", ["sms", "otp"])
def test_retries_idempotency_request_in_progress_after_retry_after(harness: HarnessFactory, operation: str) -> None:
    in_progress = api_error(409, "IDEMPOTENCY_REQUEST_IN_PROGRESS", {"retry_after_seconds": 5}, {"Retry-After": "5"})
    success = json_response(201, OTP_SENT) if operation == "otp" else json_response(202, {"data": [message()]})
    h = harness([in_progress, success])
    if operation == "otp":
        h.call(lambda c: c.otp.send(phone="0812345678"))
    else:
        h.call(lambda c: c.sms.send(to="0812345678", body="Hello"))
    assert h.sleeps == [5.0]
    assert len({r.headers["idempotency-key"] for r in h.requests}) == 1


@pytest.mark.parametrize("operation", ["sms", "otp"])
def test_idempotency_key_unresolved_is_never_retried(harness: HarnessFactory, operation: str) -> None:
    unresolved = api_error(409, "IDEMPOTENCY_KEY_UNRESOLVED", {}, {"X-Request-Id": "r-2"})
    h = harness([unresolved])
    send = (
        (lambda c: c.otp.send(phone="0812345678", idempotency_key="key-1"))
        if operation == "otp"
        else (lambda c: c.sms.send(to="0812345678", body="Hello", idempotency_key="key-1"))
    )
    with pytest.raises(IdempotencyKeyUnresolvedError) as info:
        h.call(send)
    assert info.value.status == 409
    assert info.value.code == "IDEMPOTENCY_KEY_UNRESOLVED"
    assert info.value.request_id == "r-2"
    assert len(h.requests) == 1
    assert h.sleeps == []


def test_idempotency_request_in_progress_is_raised_when_retries_run_out(harness: HarnessFactory) -> None:
    in_progress = api_error(
        409, "IDEMPOTENCY_REQUEST_IN_PROGRESS", {"retry_after_seconds": 5}, {"Retry-After": "5", "X-Request-Id": "r-1"}
    )
    h = harness([in_progress, in_progress], max_retries=1)
    with pytest.raises(IdempotencyRequestInProgressError) as info:
        h.call(lambda c: c.sms.send(to="0812345678", body="Hello"))
    assert info.value.retry_after == 5
    assert info.value.status == 409
    assert info.value.request_id == "r-1"
    assert len(h.requests) == 2

    too_long = harness([in_progress], max_retry_wait=2)
    with pytest.raises(IdempotencyRequestInProgressError):
        too_long.call(lambda c: c.otp.send(phone="0812345678"))
    assert too_long.sleeps == []

    disabled = harness([in_progress], max_retries=0)
    with pytest.raises(IdempotencyRequestInProgressError):
        disabled.call(lambda c: c.otp.send(phone="0812345678"))
    assert len(disabled.requests) == 1


def test_otp_is_retried_on_too_many_requests(harness: HarnessFactory) -> None:
    h = harness(
        [
            api_error(429, "TOO_MANY_REQUESTS", {}, {"Retry-After": "1"}),
            json_response(201, OTP_SENT),
            api_error(429, "TOO_MANY_REQUESTS", {"retry_after_seconds": 2}),
            json_response(200, VERIFIED),
        ]
    )
    assert h.call(lambda c: c.otp.send(phone="0812345678")).otp_id == "otp-1"
    assert h.call(lambda c: c.otp.verify(otp_id="otp-1", code="123456")).verified
    assert h.sleeps == [1.0, 2.0]


def test_otp_business_limits_are_never_retried(harness: HarnessFactory) -> None:
    h = harness(
        [
            api_error(429, "OTP_RESEND_TOO_SOON", {"retry_after_seconds": 1}, {"Retry-After": "1"}),
            api_error(429, "OTP_RATE_LIMITED", {"scope": "account", "limit": 100}),
        ]
    )
    with pytest.raises(OtpResendTooSoonError):
        h.call(lambda c: c.otp.send(phone="0812345678"))
    with pytest.raises(OtpRateLimitedError):
        h.call(lambda c: c.otp.send(phone="0812345678"))
    assert len(h.requests) == 2
    assert h.sleeps == []


def test_otp_verify_is_never_retried_after_a_network_error(harness: HarnessFactory) -> None:
    h = harness([network_error()])
    with pytest.raises(APIConnectionError):
        h.call(lambda c: c.otp.verify(otp_id="otp-1", code="123456"))
    assert len(h.requests) == 1


def test_timeout_is_retried_for_safe_requests_then_raised(harness: HarnessFactory) -> None:
    h = harness([timeout_error(), timeout_error(), timeout_error()], timeout=1.5)
    with pytest.raises(APITimeoutError, match=r"within 1\.5 s \(GET /balance\)\.$"):
        h.call(lambda c: c.balance.get())
    assert len(h.requests) == 3


def test_network_error_message(harness: HarnessFactory) -> None:
    h = harness([network_error("[Errno 111] Connection refused")], max_retries=0)
    with pytest.raises(APIConnectionError) as info:
        h.call(lambda c: c.balance.get())
    assert info.value.code == "CONNECTION_ERROR"
    assert "ConnectError: [Errno 111] Connection refused" in info.value.message
    assert info.value.status is None


DISABLED = {"feature": "api", "blocked_by": "api", "message": None, "until": None}
SCHEDULED = {
    "feature": "api.sms_send",
    "blocked_by": "api.sms_send",
    "message": None,
    "until": "2026-11-06T10:00:00+00:00",
}


def _call(operation: str) -> Any:
    calls = {
        "balance": lambda c: c.balance.get(),
        "sms": lambda c: c.sms.send(to="0812345678", body="Hello"),
        "otp.send": lambda c: c.otp.send(phone="0812345678"),
        "otp.verify": lambda c: c.otp.verify(otp_id="otp-1", code="123456"),
    }
    return calls[operation]


def _success(operation: str) -> Any:
    return {
        "balance": balance(),
        "sms": json_response(202, {"data": [message()]}),
        "otp.send": json_response(201, OTP_SENT),
        "otp.verify": json_response(200, VERIFIED),
    }[operation]


@pytest.mark.parametrize("operation", ["balance", "sms", "otp.send", "otp.verify"])
@pytest.mark.parametrize("code", ["FEATURE_DISABLED", "OTP_SERVICE_DISABLED"])
def test_feature_disabled_without_retry_after_is_never_retried(
    harness: HarnessFactory, operation: str, code: str
) -> None:
    h = harness([api_error(503, code, DISABLED)], max_retries=3)
    with pytest.raises(FeatureDisabledError) as info:
        h.call(_call(operation))
    assert info.value.retry_after is None
    assert len(h.requests) == 1
    assert h.sleeps == []


@pytest.mark.parametrize("operation", ["balance", "sms", "otp.send", "otp.verify"])
@pytest.mark.parametrize("code", ["FEATURE_DISABLED", "OTP_SERVICE_DISABLED"])
def test_feature_disabled_is_retried_after_retry_after_within_max_retry_wait(
    harness: HarnessFactory, operation: str, code: str
) -> None:
    h = harness([api_error(503, code, SCHEDULED, {"Retry-After": "7"}), _success(operation)], max_retry_wait=10)
    h.call(_call(operation))
    assert len(h.requests) == 2
    assert h.sleeps == [7.0]  # exactement Retry-After, sans backoff ni jitter
    if operation in ("sms", "otp.send"):
        assert len({r.headers["idempotency-key"] for r in h.requests}) == 1


@pytest.mark.parametrize("operation", ["balance", "sms", "otp.send", "otp.verify"])
def test_feature_disabled_is_not_retried_when_retry_after_exceeds_max_retry_wait(
    harness: HarnessFactory, operation: str
) -> None:
    h = harness([api_error(503, "FEATURE_DISABLED", SCHEDULED, {"Retry-After": "3600"})], max_retry_wait=10)
    with pytest.raises(FeatureDisabledError) as info:
        h.call(_call(operation))
    assert info.value.retry_after == 3600
    assert len(h.requests) == 1
    assert h.sleeps == []


def test_feature_disabled_respects_max_retries(harness: HarnessFactory) -> None:
    disabled = api_error(503, "FEATURE_DISABLED", SCHEDULED, {"Retry-After": "2"})
    h = harness([disabled, disabled, disabled], max_retries=2)
    with pytest.raises(FeatureDisabledError):
        h.call(lambda c: c.balance.get())
    assert len(h.requests) == 3
    assert h.sleeps == [2.0, 2.0]

    none = harness([disabled], max_retries=0)
    with pytest.raises(FeatureDisabledError):
        none.call(lambda c: c.balance.get())
    assert none.sleeps == []


def test_feature_disabled_ignores_a_non_integer_retry_after(harness: HarnessFactory) -> None:
    h = harness([api_error(503, "FEATURE_DISABLED", {"retry_after_seconds": 1}, {"Retry-After": "soon"})])
    with pytest.raises(FeatureDisabledError):
        h.call(lambda c: c.balance.get())
    assert len(h.requests) == 1
    assert h.sleeps == []


def test_other_5xx_are_still_retried_with_backoff(harness: HarnessFactory) -> None:
    h = harness([api_error(503, "HTTP_ERROR", {}, {"Retry-After": "7"}), balance()])
    h.call(lambda c: c.balance.get())
    assert 0.25 <= h.sleeps[0] <= 0.5

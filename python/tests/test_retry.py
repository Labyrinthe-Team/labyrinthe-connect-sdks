from __future__ import annotations

from datetime import datetime, timedelta, timezone
from email.utils import format_datetime

import pytest

from labyrinthe_connect import (
    APIConnectionError,
    APITimeoutError,
    IdempotencyKeyReusedError,
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


def test_otp_send_is_never_retried_after_a_network_error(harness: HarnessFactory) -> None:
    h = harness([network_error(), json_response(201, OTP_SENT)])
    with pytest.raises(APIConnectionError, match="may have been processed"):
        h.call(lambda c: c.otp.send(phone="0812345678"))
    assert len(h.requests) == 1


def test_otp_send_is_never_retried_after_a_timeout(harness: HarnessFactory) -> None:
    h = harness([timeout_error(), json_response(201, OTP_SENT)], timeout=2)
    with pytest.raises(APITimeoutError, match="within 2 s") as info:
        h.call(lambda c: c.otp.send(phone="0812345678"))
    assert info.value.code == "TIMEOUT"
    assert len(h.requests) == 1


def test_otp_is_never_retried_after_a_5xx(harness: HarnessFactory) -> None:
    h = harness([api_error(500, "HTTP_ERROR"), api_error(503, "HTTP_ERROR")])
    with pytest.raises(ServiceUnavailableError):
        h.call(lambda c: c.otp.send(phone="0812345678"))
    with pytest.raises(ServiceUnavailableError):
        h.call(lambda c: c.otp.verify(otp_id="otp-1", code="123456"))
    assert len(h.requests) == 2


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

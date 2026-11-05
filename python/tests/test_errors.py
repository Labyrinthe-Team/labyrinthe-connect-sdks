from __future__ import annotations

import pickle
from typing import Any, Type

import pytest

from labyrinthe_connect import errors
from labyrinthe_connect.errors import (
    ApiError,
    AuthenticationError,
    IdempotencyKeyReusedError,
    InsufficientCreditsError,
    InvalidArgumentError,
    InvalidPhoneError,
    LabyrintheError,
    NotFoundError,
    OtpRateLimitedError,
    OtpResendTooSoonError,
    RateLimitError,
    RecipientNotSendableError,
    SenderIdNotApprovedError,
    ServiceUnavailableError,
    UnexpectedResponseError,
    ValidationError,
)
from labyrinthe_connect.types import OtpRateLimitScope, RejectedRecipient, RejectionReason

from .helpers import HarnessFactory, api_error, json_response, raw_response


@pytest.mark.parametrize(
    ("status", "code", "cls"),
    [
        (401, "UNAUTHENTICATED", AuthenticationError),
        (403, "FORBIDDEN", errors.PermissionError),
        (403, "OTP_DISABLED_FOR_ACCOUNT", errors.PermissionError),
        (404, "NOT_FOUND", NotFoundError),
        (422, "VALIDATION_ERROR", ValidationError),
        (422, "RECIPIENT_NOT_SENDABLE", RecipientNotSendableError),
        (422, "SENDER_ID_NOT_APPROVED", SenderIdNotApprovedError),
        (422, "INVALID_PHONE", InvalidPhoneError),
        (422, "INVALID_OTP_TEMPLATE", ApiError),
        (409, "INSUFFICIENT_CREDITS", InsufficientCreditsError),
        (409, "IDEMPOTENCY_KEY_REUSED", IdempotencyKeyReusedError),
        (429, "OTP_RESEND_TOO_SOON", OtpResendTooSoonError),
        (429, "OTP_RATE_LIMITED", OtpRateLimitedError),
        (503, "OTP_SERVICE_DISABLED", ServiceUnavailableError),
        (418, "SOMETHING_NEW", ApiError),
        (401, "SOMETHING_NEW", AuthenticationError),
        (404, "SOMETHING_NEW", NotFoundError),
        (500, "SOMETHING_NEW", ServiceUnavailableError),
    ],
)
def test_maps_each_error(harness: HarnessFactory, status: int, code: str, cls: Type[ApiError]) -> None:
    h = harness([api_error(status, code, {"x": 1})], max_retries=0)
    with pytest.raises(cls) as info:
        h.call(lambda c: c.balance.get())
    error = info.value
    assert type(error) is cls
    assert isinstance(error, LabyrintheError)
    assert error.code == code
    assert error.status == status
    assert error.message == "Erreur de test."
    assert str(error) == "Erreur de test."
    assert error.details == {"x": 1}


def test_validation_error_field_errors(harness: HarnessFactory) -> None:
    h = harness([api_error(422, "VALIDATION_ERROR", {"body": ["Le champ body est obligatoire."], "to": "x"})])
    with pytest.raises(ValidationError) as info:
        h.call(lambda c: c.balance.get())
    assert info.value.field_errors == {"body": ("Le champ body est obligatoire.",), "to": ("x",)}


def test_recipient_not_sendable_lists_the_rejected_numbers(harness: HarnessFactory) -> None:
    details = {
        "reason": "blacklisted",
        "rejected": [
            {"recipient": "243812345678", "reason": "blacklisted"},
            {"recipient": "243899999999", "reason": "opted_out"},
            {"recipient": "x", "reason": "new_reason"},
            "garbage",
        ],
    }
    h = harness([api_error(422, "RECIPIENT_NOT_SENDABLE", details)])
    with pytest.raises(RecipientNotSendableError) as info:
        h.call(lambda c: c.sms.send_many(recipients=["0812345678", "0899999999"], body="Hi"))
    assert info.value.reason is RejectionReason.BLACKLISTED
    assert info.value.rejected == (
        RejectedRecipient("243812345678", RejectionReason.BLACKLISTED),
        RejectedRecipient("243899999999", RejectionReason.OPTED_OUT),
        RejectedRecipient("x", RejectionReason.UNKNOWN),
    )


def test_insufficient_credits(harness: HarnessFactory) -> None:
    h = harness([api_error(409, "INSUFFICIENT_CREDITS", {"available": 3, "required": "12"})])
    with pytest.raises(InsufficientCreditsError) as info:
        h.call(lambda c: c.sms.send(to="0812345678", body="Hi"))
    assert (info.value.available, info.value.required) == (3, 12)
    assert len(h.requests) == 1


def test_rate_limit_errors(harness: HarnessFactory) -> None:
    h = harness(
        [
            api_error(429, "OTP_RESEND_TOO_SOON", {"retry_after_seconds": 30}),
            api_error(429, "OTP_RATE_LIMITED", {"scope": "phone", "limit": 5}),
            api_error(429, "OTP_RATE_LIMITED", {"scope": "galaxy"}),
            api_error(429, "TOO_MANY_REQUESTS", {}, {"Retry-After": "60"}),
        ],
        max_retry_wait=1,
    )
    with pytest.raises(OtpResendTooSoonError) as too_soon:
        h.call(lambda c: c.otp.send(phone="0812345678"))
    assert too_soon.value.retry_after == 30
    assert isinstance(too_soon.value, RateLimitError)

    with pytest.raises(OtpRateLimitedError) as limited:
        h.call(lambda c: c.otp.send(phone="0812345678"))
    assert limited.value.scope is OtpRateLimitScope.PHONE
    assert limited.value.limit == 5

    with pytest.raises(OtpRateLimitedError) as unknown:
        h.call(lambda c: c.otp.send(phone="0812345678"))
    assert unknown.value.scope is OtpRateLimitScope.UNKNOWN
    assert unknown.value.limit is None

    with pytest.raises(RateLimitError) as generic:
        h.call(lambda c: c.balance.get())
    assert generic.value.retry_after == 60
    assert generic.value.headers["retry-after"] == "60"
    assert len(h.requests) == 4


def test_error_without_json_body(harness: HarnessFactory) -> None:
    h = harness([raw_response(502, b"<html>Bad gateway</html>"), raw_response(429, b"")], max_retries=0)
    with pytest.raises(ServiceUnavailableError) as info:
        h.call(lambda c: c.balance.get())
    assert info.value.code == "HTTP_ERROR"
    assert info.value.message == "Labyrinthe Connect API returned HTTP 502."
    with pytest.raises(RateLimitError) as limited:
        h.call(lambda c: c.balance.get())
    assert limited.value.code == "TOO_MANY_REQUESTS"
    assert limited.value.retry_after is None


def test_array_details_become_an_empty_object(harness: HarnessFactory) -> None:
    h = harness([api_error(404, "NOT_FOUND", [])])
    with pytest.raises(NotFoundError) as info:
        h.call(lambda c: c.sms.get("unknown"))
    assert info.value.details == {}


def test_request_id(harness: HarnessFactory) -> None:
    h = harness([api_error(404, "NOT_FOUND", {}, {"X-Request-Id": "req-42"})])
    with pytest.raises(NotFoundError) as info:
        h.call(lambda c: c.sms.get("unknown"))
    assert info.value.request_id == "req-42"


@pytest.mark.parametrize(
    ("body", "match"),
    [(b"not json", "not valid JSON"), (b"[1, 2]", "JSON object"), (b"\xff\xfe", "not valid JSON")],
)
def test_unreadable_success_responses(harness: HarnessFactory, body: bytes, match: str) -> None:
    h = harness([raw_response(200, body)])
    with pytest.raises(UnexpectedResponseError, match=match) as info:
        h.call(lambda c: c.balance.get())
    assert info.value.status == 200


def test_missing_fields_raise_unexpected_response(harness: HarnessFactory) -> None:
    h = harness([json_response(200, {"data": {"balance": "lots"}}), raw_response(200, b"")])
    with pytest.raises(UnexpectedResponseError, match='"balance" should be an integer'):
        h.call(lambda c: c.balance.get())
    with pytest.raises(UnexpectedResponseError, match='"data"'):
        h.call(lambda c: c.balance.get())


@pytest.mark.parametrize(
    "error",
    [
        InsufficientCreditsError("x", code="INSUFFICIENT_CREDITS", status=409, details={"available": 1}, headers={}),
        InvalidArgumentError("bad"),
        errors.APITimeoutError("slow"),
    ],
)
def test_errors_are_picklable(error: LabyrintheError) -> None:
    restored: Any = pickle.loads(pickle.dumps(error))  # noqa: S301 - our own data
    assert type(restored) is type(error)
    assert (restored.code, restored.status, restored.details, str(restored)) == (
        error.code,
        error.status,
        error.details,
        str(error),
    )


def test_sdk_error_codes_and_hierarchy() -> None:
    assert errors.APIConnectionError("x").code == "CONNECTION_ERROR"
    assert errors.APITimeoutError("x").code == "TIMEOUT"
    assert isinstance(errors.APITimeoutError("x"), errors.APIConnectionError)
    assert InvalidArgumentError("x").code == "INVALID_ARGUMENT"
    assert isinstance(InvalidArgumentError("x"), ValueError)
    assert errors.SignatureVerificationError("x").code == "SIGNATURE_VERIFICATION_FAILED"
    assert errors.InvalidWebhookPayloadError("x").code == "INVALID_WEBHOOK_PAYLOAD"
    assert UnexpectedResponseError("x").status is None
    assert "code='INVALID_ARGUMENT'" in repr(InvalidArgumentError("x"))

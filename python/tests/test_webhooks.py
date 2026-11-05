from __future__ import annotations

import hashlib
import hmac
import json
from datetime import datetime, timezone
from typing import Any, Dict

import pytest

from labyrinthe_connect import (
    BalanceLow,
    CampaignCompleted,
    InvalidArgumentError,
    InvalidWebhookPayloadError,
    MessageStatus,
    MessageStatusChanged,
    SignatureVerificationError,
    TestPing,
    UnknownEvent,
    WebhookEventType,
    construct_event,
    webhooks,
)

SECRET = "whsec_test_secret"
NOW = 1793952000


def envelope(event: str = "message.status_changed", data: Any = None, timestamp: int = NOW) -> bytes:
    if data is None:
        data = {
            "message_id": "9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10",
            "recipient": "243812345678",
            "status": "delivered",
            "provider_message_id": "DD-88231",
            "campaign_id": None,
        }
    body: Dict[str, Any] = {
        "id": "1f0c7e52-8d3a-4b6e-9c2f-5a7d1e3b9f40",
        "event": event,
        "data": data,
        "timestamp": timestamp,
    }
    return json.dumps(body).encode()


def signature(body: bytes, secret: str = SECRET) -> str:
    return "sha256=" + hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()


def test_message_status_changed() -> None:
    body = envelope()
    event = construct_event(body, signature(body), SECRET, now=NOW)
    assert isinstance(event, MessageStatusChanged)
    assert event.event is WebhookEventType.MESSAGE_STATUS_CHANGED
    assert event.id == "1f0c7e52-8d3a-4b6e-9c2f-5a7d1e3b9f40"
    assert event.type == "message.status_changed"
    assert event.timestamp == datetime.fromtimestamp(NOW, tz=timezone.utc)
    assert event.data.message_id == "9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10"
    assert event.data.status is MessageStatus.DELIVERED
    assert event.data.provider_message_id == "DD-88231"
    assert event.data.campaign_id is None
    assert event.raw["event"] == "message.status_changed"


def test_campaign_completed() -> None:
    body = envelope(
        "campaign.completed",
        {
            "campaign_id": "3c9a1f7e-2b4d-4e6a-8f0c-9d1b5e7a3c20",
            "name": "Promo de novembre",
            "recipients_count": 1200,
            "messages_by_status": {"delivered": 1150, "failed": 30, "undeliverable": 20},
            "credits_charged": 1200,
            "completed_at": "2026-11-06T10:15:00+00:00",
        },
    )
    event = construct_event(body, signature(body), SECRET, now=NOW)
    assert isinstance(event, CampaignCompleted)
    assert event.data.messages_by_status == {"delivered": 1150, "failed": 30, "undeliverable": 20}
    assert event.data.completed_at == datetime(2026, 11, 6, 10, 15, tzinfo=timezone.utc)
    assert event.data.recipients_count == 1200


def test_balance_low_and_test_ping_and_unknown() -> None:
    body = envelope("balance.low", {"balance": 950, "threshold": 1000})
    low = construct_event(body, signature(body), SECRET, now=NOW)
    assert isinstance(low, BalanceLow)
    assert (low.data.balance, low.data.threshold) == (950, 1000)

    body = envelope("test.ping", [])
    ping = construct_event(body, signature(body), SECRET, now=NOW)
    assert isinstance(ping, TestPing)
    assert ping.data == {}

    body = envelope("otp.verified", {"otp_id": "x"})
    unknown = construct_event(body, signature(body), SECRET, now=NOW)
    assert isinstance(unknown, UnknownEvent)
    assert unknown.type == "otp.verified"
    assert unknown.event is WebhookEventType.UNKNOWN
    assert unknown.data == {"otp_id": "x"}


def test_accepts_str_bytearray_and_memoryview_bodies() -> None:
    body = envelope()
    for raw in (body.decode(), bytearray(body), memoryview(body)):
        assert isinstance(construct_event(raw, signature(body), SECRET, now=NOW), MessageStatusChanged)


def test_accepts_an_upper_case_signature_with_spaces() -> None:
    body = envelope()
    header = "  " + signature(body).upper().replace("SHA256=", "sha256=") + " "
    assert webhooks.verify_signature(body, header, SECRET)


@pytest.mark.parametrize(
    "header",
    [
        None,
        "",
        "sha256=",
        "sha1=abc",
        "sha256=" + "0" * 64,
        "sha256=" + "z" * 64,
        "sha256=" + "a" * 63,
        "deadbeef",
    ],
)
def test_rejects_invalid_signatures(header: Any) -> None:
    with pytest.raises(SignatureVerificationError) as info:
        construct_event(envelope(), header, SECRET, now=NOW)
    assert info.value.code == "SIGNATURE_VERIFICATION_FAILED"


def test_rejects_a_signature_made_with_another_secret_or_body() -> None:
    body = envelope()
    with pytest.raises(SignatureVerificationError):
        construct_event(body, signature(body, "other"), SECRET, now=NOW)
    with pytest.raises(SignatureVerificationError):
        construct_event(body + b" ", signature(body), SECRET, now=NOW)
    # Corps re-sérialisé (espaces différents) : la signature ne correspond plus.
    reencoded = json.dumps(json.loads(body), separators=(",", ":")).encode()
    with pytest.raises(SignatureVerificationError):
        construct_event(reencoded, signature(body), SECRET, now=NOW)


def test_rejects_expired_or_future_timestamps() -> None:
    body = envelope(timestamp=NOW - 301)
    with pytest.raises(SignatureVerificationError, match="tolerance"):
        construct_event(body, signature(body), SECRET, now=NOW)
    body = envelope(timestamp=NOW + 301)
    with pytest.raises(SignatureVerificationError):
        construct_event(body, signature(body), SECRET, now=NOW)
    old = envelope(timestamp=NOW - 3600)
    assert construct_event(old, signature(old), SECRET, tolerance=7200, now=NOW).id
    assert construct_event(old, signature(old), SECRET, tolerance=0, now=NOW).id


def test_uses_the_current_time_by_default() -> None:
    body = envelope(timestamp=NOW)
    with pytest.raises(SignatureVerificationError, match="tolerance"):
        construct_event(body, signature(body), SECRET)


@pytest.mark.parametrize(
    "body",
    [
        b"not json",
        b"[1,2]",
        b'{"event":"test.ping","data":{},"timestamp":1793952000}',
        b'{"id":"x","event":"test.ping","data":{}}',
        b'{"id":"x","event":"balance.low","data":{"balance":"a lot"},"timestamp":1793952000}',
        b'{"id":"x","event":"test.ping","data":"nope","timestamp":1793952000}',
        b'{"id":"x","event":"test.ping","data":{},"timestamp":99999999999999999}',
    ],
)
def test_rejects_signed_but_invalid_payloads(body: bytes) -> None:
    with pytest.raises(InvalidWebhookPayloadError) as info:
        construct_event(body, signature(body), SECRET, tolerance=0, now=NOW)
    assert info.value.code == "INVALID_WEBHOOK_PAYLOAD"


def test_rejects_parsed_objects_and_empty_secrets() -> None:
    with pytest.raises(InvalidArgumentError, match="RAW"):
        construct_event({"id": "x"}, "sha256=", SECRET)  # type: ignore[arg-type]
    with pytest.raises(InvalidArgumentError, match="secret"):
        construct_event(envelope(), signature(envelope()), "")
    with pytest.raises(InvalidArgumentError, match="tolerance"):
        construct_event(envelope(), signature(envelope()), SECRET, tolerance=-1)


def test_sign_round_trip() -> None:
    body = envelope()
    assert webhooks.sign(body, SECRET) == signature(body)
    assert webhooks.verify_signature(body, webhooks.sign(body, SECRET), SECRET)
    assert webhooks.SIGNATURE_HEADER == "X-Signature"
    assert webhooks.DEFAULT_TOLERANCE == 300


def test_uses_constant_time_comparison(monkeypatch: pytest.MonkeyPatch) -> None:
    calls = []
    original = hmac.compare_digest

    def spy(a: Any, b: Any) -> bool:
        calls.append(1)
        return original(a, b)

    monkeypatch.setattr(hmac, "compare_digest", spy)
    body = envelope()
    construct_event(body, signature(body), SECRET, now=NOW)
    assert calls == [1]


def test_events_are_frozen() -> None:
    body = envelope()
    event = construct_event(body, signature(body), SECRET, now=NOW)
    with pytest.raises(AttributeError):
        event.id = "other"  # type: ignore[misc]

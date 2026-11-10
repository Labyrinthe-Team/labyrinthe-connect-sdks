from __future__ import annotations

import asyncio
import uuid
from datetime import date, datetime, timedelta, timezone
from typing import Any, List

import pytest

from labyrinthe_connect import (
    Encoding,
    InvalidArgumentError,
    Message,
    MessageStatus,
    UnexpectedResponseError,
)

from .helpers import HarnessFactory, Recorder, async_client, json_response, message, page, sync_client

ESTIMATE = {
    "data": {
        "encoding": "ucs2",
        "characters": 19,
        "segments_per_message": 1,
        "recipients_count": 2,
        "total_credits": 2,
        "balance_after": 8,
    }
}


def test_send_normalizes_the_number_and_returns_the_message(harness: HarnessFactory) -> None:
    h = harness([json_response(202, {"data": [message()]})])
    result: Message = h.call(
        lambda c: c.sms.send(to="+243 81 234 5678", body="Hello", sender="MYSHOP", reference="order-1042")
    )

    assert h.json() == {"recipient": "+243812345678", "body": "Hello", "sender": "MYSHOP", "reference": "order-1042"}
    request = h.requests[0]
    assert request.method == "POST"
    assert request.url.path == "/api/public/v1/sms"
    assert request.headers["content-type"] == "application/json"
    assert uuid.UUID(request.headers["idempotency-key"]).version == 4

    assert result.id == "9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10"
    assert result.status is MessageStatus.QUEUED
    assert result.encoding is Encoding.GSM7
    assert result.recipient == "243812345678"
    assert result.credits_charged == 1
    assert result.created_at == datetime(2026, 11, 5, 10, 0, tzinfo=timezone.utc)
    assert result.submitted_at is None


def test_send_uses_the_given_idempotency_key_and_the_default_sender(harness: HarnessFactory) -> None:
    h = harness([json_response(202, {"data": [message()]})], default_sender="DEFAULT")
    h.call(lambda c: c.sms.send(to="0812345678", body="Hi", idempotency_key="order-1042-ready"))
    assert h.requests[0].headers["idempotency-key"] == "order-1042-ready"
    assert h.json()["sender"] == "DEFAULT"
    assert "reference" not in h.json()


def test_each_send_gets_its_own_idempotency_key(harness: HarnessFactory) -> None:
    h = harness([json_response(202, {"data": [message()]}), json_response(202, {"data": [message()]})])
    h.call(lambda c: c.sms.send(to="0812345678", body="Hi"))
    h.call(lambda c: c.sms.send(to="0812345678", body="Hi"))
    assert h.requests[0].headers["idempotency-key"] != h.requests[1].headers["idempotency-key"]


def test_send_keeps_non_ascii_text_intact(harness: HarnessFactory) -> None:
    h = harness([json_response(202, {"data": [message(encoding="ucs2")]})])
    h.call(lambda c: c.sms.send(to="0812345678", body="Réunion à 9h — merci ✅"))
    assert h.json()["body"] == "Réunion à 9h — merci ✅"


def test_send_many_returns_a_batch_with_replayed(harness: HarnessFactory) -> None:
    second = message(id="1f0c7e52-8d3a-4b6e-9c2f-5a7d1e3b9f41", recipient="33612345678")
    h = harness([json_response(202, {"data": [message(), second]}, {"Idempotent-Replayed": "true"})])
    batch = h.call(lambda c: c.sms.send_many(recipients=["0812345678", "+33 6 12 34 56 78"], body="Hello"))

    assert h.json() == {"recipients": ["+243812345678", "+33612345678"], "body": "Hello"}
    assert batch.replayed is True
    assert len(batch) == 2
    assert [m.recipient for m in batch.messages] == ["243812345678", "33612345678"]
    assert batch.idempotency_key == h.requests[0].headers["idempotency-key"]


def test_send_many_not_replayed_by_default(harness: HarnessFactory) -> None:
    h = harness([json_response(202, {"data": [message()]})])
    assert h.call(lambda c: c.sms.send_many(recipients=["0812345678"], body="Hello")).replayed is False


@pytest.mark.parametrize(
    ("recipients", "match"),
    [
        ([], "At least one recipient"),
        ("0812345678", "list of phone numbers"),
        (["0812345678"] * 1001, "At most 1000"),
        (["0812345678", "abc", "12"], "index 1, 2"),
    ],
)
def test_send_many_validates_recipients_locally(harness: HarnessFactory, recipients: Any, match: str) -> None:
    h = harness([])
    with pytest.raises(InvalidArgumentError, match=match) as info:
        h.call(lambda c: c.sms.send_many(recipients=recipients, body="Hello"))
    assert "abc" not in str(info.value)
    assert h.requests == []


@pytest.mark.parametrize(
    ("kwargs", "match"),
    [
        ({"to": "0812345678", "body": ""}, "body cannot be empty"),
        ({"to": "0812345678", "body": "x" * 1601}, "at most 1600"),
        ({"to": "abc", "body": "Hello"}, 'Invalid phone number in "to"'),
        ({"to": "0812345678", "body": "Hello", "sender": "TOOLONGSENDER"}, "sender"),
        ({"to": "0812345678", "body": "Hello", "sender": ""}, "sender"),
        ({"to": "0812345678", "body": "Hello", "reference": "r" * 65}, "reference"),
        ({"to": "0812345678", "body": "Hello", "idempotency_key": ""}, "idempotency_key"),
        ({"to": "0812345678", "body": "Hello", "idempotency_key": "k" * 256}, "idempotency_key"),
    ],
)
def test_send_validates_its_arguments(harness: HarnessFactory, kwargs: Any, match: str) -> None:
    h = harness([])
    with pytest.raises(InvalidArgumentError, match=match):
        h.call(lambda c: c.sms.send(**kwargs))
    assert h.requests == []


def test_body_length_counts_characters_not_bytes(harness: HarnessFactory) -> None:
    h = harness([json_response(202, {"data": [message(encoding="ucs2")]})])
    h.call(lambda c: c.sms.send(to="0812345678", body="é" * 1600))
    assert len(h.json()["body"]) == 1600


def test_send_without_message_in_the_response(harness: HarnessFactory) -> None:
    h = harness([json_response(202, {"data": []})])
    with pytest.raises(UnexpectedResponseError):
        h.call(lambda c: c.sms.send(to="0812345678", body="Hello"))


def test_estimate(harness: HarnessFactory) -> None:
    h = harness([json_response(200, ESTIMATE), json_response(200, ESTIMATE)])
    estimate = h.call(lambda c: c.sms.estimate(body="Réunion demain à 9h", recipients=["0812345678", "not-a-number"]))
    assert h.json() == {"body": "Réunion demain à 9h", "recipients": ["+243812345678", "not-a-number"]}
    assert estimate.encoding is Encoding.UCS2
    assert estimate.total_credits == 2
    assert estimate.balance_after == 8
    assert estimate.is_affordable

    h.call(lambda c: c.sms.estimate(body="Hi", recipients="0899999999"))
    assert h.json()["recipients"] == ["+243899999999"]
    assert "idempotency-key" not in h.requests[1].headers


def test_numbers_are_sent_in_e164_with_the_plus(harness: HarnessFactory) -> None:
    """Sans « + », l'API lit un numéro étranger court comme un numéro local RDC."""
    h = harness(
        [
            json_response(202, {"data": [message()]}),
            json_response(202, {"data": [message()]}),
            json_response(200, ESTIMATE),
            page([message()], total=1),
        ]
    )
    h.call(lambda c: c.sms.send(to="+2481234567", body="Hi"))
    assert h.json()["recipient"] == "+2481234567"
    h.call(lambda c: c.sms.send_many(recipients=["+2481234567", "0812345678"], body="Hi"))
    assert h.json()["recipients"] == ["+2481234567", "+243812345678"]
    h.call(lambda c: c.sms.estimate(body="Hi", recipients=["+2481234567", "0812345678"]))
    assert h.json()["recipients"] == ["+2481234567", "+243812345678"]
    h.call(lambda c: c.sms.list(recipient="+2481234567"))
    assert h.requests[3].url.params["recipient"] == "+2481234567"


def test_estimate_affordability() -> None:
    from labyrinthe_connect import Estimate

    def make(after: Any) -> Estimate:
        return Estimate(Encoding.GSM7, 5, 1, 1, 1, after)

    assert make(0).is_affordable
    assert not make(-3).is_affordable
    assert not make(None).is_affordable


def test_estimate_requires_recipients(harness: HarnessFactory) -> None:
    with pytest.raises(InvalidArgumentError):
        harness([]).call(lambda c: c.sms.estimate(body="Hi", recipients=[]))


def test_get_escapes_the_id(harness: HarnessFactory) -> None:
    h = harness([json_response(200, {"data": message(status="delivered", delivered_at="2026-11-05T10:01:00.123456Z")})])
    result = h.call(lambda c: c.sms.get("a/b c"))
    assert h.requests[0].url.raw_path == b"/api/public/v1/sms/a%2Fb%20c"
    assert result.status is MessageStatus.DELIVERED
    assert result.status.is_final
    assert result.delivered_at == datetime(2026, 11, 5, 10, 1, 0, 123456, tzinfo=timezone.utc)


def test_get_rejects_an_empty_id(harness: HarnessFactory) -> None:
    with pytest.raises(InvalidArgumentError):
        harness([]).call(lambda c: c.sms.get(" "))


def test_unknown_values_become_unknown(harness: HarnessFactory) -> None:
    h = harness([json_response(200, {"data": message(status="teleported", encoding=None)})])
    result = h.call(lambda c: c.sms.get("x"))
    assert result.status is MessageStatus.UNKNOWN
    assert result.encoding is None


def test_list_sends_the_filters(harness: HarnessFactory) -> None:
    h = harness([page([message()], total=1)])
    since = datetime(2026, 11, 1, 9, 30, tzinfo=timezone(timedelta(hours=1)))
    first = h.call(
        lambda c: c.sms.list(
            status=MessageStatus.FAILED,
            recipient="0812345678",
            reference="order-1042",
            from_=since,
            to=date(2026, 11, 30),
            per_page=50,
        )
    )
    params = dict(h.requests[0].url.params)
    assert params == {
        "status": "failed",
        "recipient": "+243812345678",
        "reference": "order-1042",
        "from": "2026-11-01T08:30:00Z",
        "to": "2026-11-30",
        "per_page": "50",
        "page": "1",
    }
    assert first.total == 1
    assert first.data[0].reference == "order-1042"
    assert not first.has_more


@pytest.mark.parametrize(
    ("kwargs", "match"),
    [
        ({"page": 0}, "page"),
        ({"per_page": 101}, "per_page"),
        ({"per_page": 0}, "per_page"),
        ({"status": "teleported"}, "status"),
        ({"from_": datetime(2026, 11, 1)}, "timezone-aware"),
        ({"to": 12}, "Date filters"),
    ],
)
def test_list_validates_filters(harness: HarnessFactory, kwargs: Any, match: str) -> None:
    h = harness([])
    with pytest.raises(InvalidArgumentError, match=match):
        h.call(lambda c: c.sms.list(**kwargs))


def _three_pages() -> List[Any]:
    return [
        page([message(id="m1"), message(id="m2")], current_page=1, last_page=3, per_page=2, total=5),
        page([message(id="m3"), message(id="m4")], current_page=2, last_page=3, per_page=2, total=5),
        page([message(id="m5")], current_page=3, last_page=3, per_page=2, total=5),
    ]


def test_sync_pagination() -> None:
    recorder = Recorder(_three_pages())
    client = sync_client(recorder)
    first = client.sms.list(per_page=2, status="queued")
    assert [m.id for m in first.data] == ["m1", "m2"]
    assert first.has_more
    assert len(recorder.requests) == 1

    ids = [m.id for m in first.auto_paging_iter()]
    assert ids == ["m1", "m2", "m3", "m4", "m5"]
    assert [r.url.params["page"] for r in recorder.requests] == ["1", "2", "3"]
    assert all(r.url.params["status"] == "queued" for r in recorder.requests)


def test_sync_page_is_iterable_and_next_page() -> None:
    recorder = Recorder([*_three_pages(), page([message(id="last")], current_page=3, last_page=3, total=5)])
    client = sync_client(recorder)
    assert [m.id for m in client.sms.list(per_page=2)] == ["m1", "m2", "m3", "m4", "m5"]
    last = client.sms.list(page=3)
    assert last.next_page() is None
    assert "current_page=3" in repr(last)


async def test_async_pagination() -> None:
    recorder = Recorder(_three_pages())
    client = async_client(recorder)
    ids = [m.id async for m in client.sms.list(per_page=2).auto_paging_iter()]
    assert ids == ["m1", "m2", "m3", "m4", "m5"]
    assert [r.url.params["page"] for r in recorder.requests] == ["1", "2", "3"]


async def test_async_paginator_is_awaitable_and_iterable() -> None:
    recorder = Recorder([*_three_pages(), *_three_pages()])
    client = async_client(recorder)

    first = await client.sms.list(per_page=2)
    assert first.total == 5
    second = await first.next_page()
    assert second is not None
    assert [m.id for m in second.data] == ["m3", "m4"]
    third = await second.next_page()
    assert third is not None
    assert await third.next_page() is None

    ids = [m.id async for m in client.sms.list(per_page=2)]
    assert ids == ["m1", "m2", "m3", "m4", "m5"]
    await client.aclose()


async def test_async_paginator_is_lazy() -> None:
    recorder = Recorder([])
    client = async_client(recorder)
    paginator = client.sms.list()
    await asyncio.sleep(0)
    assert recorder.requests == []
    del paginator


def test_list_without_meta_uses_fallbacks(harness: HarnessFactory) -> None:
    h = harness([json_response(200, {"data": [message()], "meta": []})])
    result = h.call(lambda c: c.sms.list(page=4))
    assert (result.current_page, result.last_page, result.total) == (4, 4, 1)

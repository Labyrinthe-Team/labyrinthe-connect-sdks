"""Runs against a real backend with a TEST key (nothing is sent, nothing is billed)::

    LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 pytest -m integration

Skipped when LABYRINTHE_API_KEY is not set, or is not a test key. Every scenario runs with the synchronous
AND the asynchronous client.
"""

from __future__ import annotations

import asyncio
import inspect
import os
import secrets
from datetime import datetime, timezone
from typing import Any, Callable, Iterator, List, Union

import pytest

from labyrinthe_connect import (
    AsyncLabyrinthe,
    AuthenticationError,
    IdempotencyKeyReusedError,
    Labyrinthe,
    MessageStatus,
    NotFoundError,
    OtpFailureReason,
    OtpVerifyResult,
    SenderIdNotApprovedError,
    SenderIdStatus,
)

# Lus à l'import : la fixture autouse de conftest.py vide ensuite ces variables pour les tests unitaires.
KEY = os.environ.get("LABYRINTHE_API_KEY", "")
BASE_URL = os.environ.get("LABYRINTHE_BASE_URL", "http://localhost:8000")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not KEY.startswith("lsk_test_"), reason="needs LABYRINTHE_API_KEY=lsk_test_…"),
]

AnyClient = Union[Labyrinthe, AsyncLabyrinthe]


def random_phone() -> str:
    """Un numéro RDC aléatoire : les limites OTP par numéro ne se croisent jamais entre deux exécutions."""
    return f"0899{secrets.randbelow(1_000_000):06d}"


def reference() -> str:
    return f"sdk-python-it-{secrets.token_hex(6)}"


class Runner:
    """Exécute la même scénarisation avec le client synchrone ou asynchrone."""

    def __init__(self, kind: str) -> None:
        self.kind = kind
        self.loop = asyncio.new_event_loop() if kind == "async" else None
        self.client: AnyClient = (
            Labyrinthe(api_key=KEY, base_url=BASE_URL)
            if kind == "sync"
            else AsyncLabyrinthe(api_key=KEY, base_url=BASE_URL)
        )

    def __call__(self, fn: Callable[[Any], Any]) -> Any:
        result = fn(self.client)
        if inspect.isawaitable(result):
            assert self.loop is not None
            return self.loop.run_until_complete(_wait(result))
        return result

    def collect(self, fn: Callable[[Any], Any]) -> List[Any]:
        """Tous les éléments d'une liste paginée (``for`` ou ``async for``)."""
        if isinstance(self.client, Labyrinthe):
            return list(fn(self.client).auto_paging_iter())

        async def gather() -> List[Any]:
            return [item async for item in fn(self.client).auto_paging_iter()]

        assert self.loop is not None
        return self.loop.run_until_complete(gather())

    def close(self) -> None:
        if isinstance(self.client, Labyrinthe):
            self.client.close()
        else:
            assert self.loop is not None
            self.loop.run_until_complete(self.client.aclose())
            self.loop.close()


async def _wait(awaitable: Any) -> Any:
    return await awaitable


@pytest.fixture(params=["sync", "async"])
def run(request: pytest.FixtureRequest) -> Iterator[Runner]:
    runner = Runner(request.param)
    yield runner
    runner.close()


def test_reads_the_balance(run: Runner) -> None:
    balance = run(lambda c: c.balance.get())
    assert run.client.is_test_mode
    assert isinstance(balance.balance, int)
    assert balance.held >= 0


def test_estimates_a_send(run: Runner) -> None:
    estimate = run(
        lambda c: c.sms.estimate(body="Hello from the Python SDK", recipients=["0812345678", "not-a-number"])
    )
    assert estimate.encoding.value == "gsm7"
    assert estimate.segments_per_message == 1
    assert estimate.recipients_count == 1
    assert estimate.total_credits == 1
    assert run(lambda c: c.sms.estimate(body="Réunion à 9h", recipients="0812345678")).encoding.value == "ucs2"


def test_send_with_reference_and_idempotency_replay_then_409_then_list_and_get(run: Runner) -> None:
    ref = reference()
    key = f"sdk-python-it-{secrets.token_hex(8)}"
    phone = random_phone()

    message = run(lambda c: c.sms.send(to=phone, body="Integration test", reference=ref, idempotency_key=key))
    assert message.reference == ref
    assert message.recipient == "243" + phone[1:]
    assert message.segments == 1
    assert message.status is not MessageStatus.UNKNOWN
    assert isinstance(message.created_at, datetime)
    assert message.created_at.tzinfo is not None
    assert message.created_at <= datetime.now(timezone.utc)

    # Même clé, même contenu : rejoué, rien n'est renvoyé.
    replay = run(
        lambda c: c.sms.send_many(recipients=[phone], body="Integration test", reference=ref, idempotency_key=key)
    )
    assert replay.replayed is True
    assert replay.messages[0].id == message.id

    # Même clé, autre contenu : refusé.
    with pytest.raises(IdempotencyKeyReusedError) as info:
        run(lambda c: c.sms.send(to=phone, body="Another body", reference=ref, idempotency_key=key))
    assert info.value.status == 409

    first = run(lambda c: c.sms.list(reference=ref))
    assert first.total == 1
    assert first.data[0].id == message.id
    assert run(lambda c: c.sms.list(recipient=phone, reference=ref, per_page=5)).total == 1
    assert [m.id for m in run.collect(lambda c: c.sms.list(reference=ref, per_page=1))] == [message.id]
    assert run(lambda c: c.sms.get(message.id)).id == message.id


def test_a_refused_multi_send_sends_nothing(run: Runner) -> None:
    ref = reference()
    with pytest.raises(SenderIdNotApprovedError) as info:
        run(
            lambda c: c.sms.send_many(
                recipients=[random_phone(), random_phone()], body="Never sent", sender="NOTAPPROVED", reference=ref
            )
        )
    assert info.value.code == "SENDER_ID_NOT_APPROVED"
    assert run(lambda c: c.sms.list(reference=ref)).total == 0


def test_otp_send_then_wrong_code_then_verify_then_already_used(run: Runner) -> None:
    otp = run(lambda c: c.otp.send(phone=random_phone(), locale="en"))
    assert otp.otp_id
    assert otp.expires_at > datetime.now(timezone.utc)
    code = otp.test_code
    assert code, "a test key returns test_code"
    assert code not in repr(otp)

    wrong = "111111" if code == "000000" else "000000"
    assert run(lambda c: c.otp.verify(otp_id=otp.otp_id, code=wrong)) == OtpVerifyResult(
        False, OtpFailureReason.INVALID_CODE
    )
    assert run(lambda c: c.otp.verify(otp_id=otp.otp_id, code=code)) == OtpVerifyResult(True, None)
    assert run(lambda c: c.otp.verify(otp_id=otp.otp_id, code=code)) == OtpVerifyResult(
        False, OtpFailureReason.ALREADY_USED
    )


def test_unknown_message_and_otp_id_give_not_found(run: Runner) -> None:
    with pytest.raises(NotFoundError):
        run(lambda c: c.sms.get("00000000-0000-4000-8000-000000000000"))
    with pytest.raises(NotFoundError):
        run(lambda c: c.otp.verify(otp_id="00000000-0000-4000-8000-000000000000", code="123456"))


def test_an_invalid_key_is_rejected() -> None:
    with Labyrinthe(api_key="lsk_test_invalid", base_url=BASE_URL) as client, pytest.raises(AuthenticationError):
        client.balance.get()


def test_lists_approved_sender_ids(run: Runner) -> None:
    sender_ids = run(lambda c: c.sender_ids.list())
    assert isinstance(sender_ids, list)
    assert all(s.status is SenderIdStatus.APPROVED for s in sender_ids)

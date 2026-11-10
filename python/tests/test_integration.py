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
    OtpCodeType,
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
            # Attente longue acceptée : 10 OTP / minute par clé, partagée avec d'autres exécutions.
            Labyrinthe(api_key=KEY, base_url=BASE_URL, max_retries=3, max_retry_wait=65)
            if kind == "sync"
            else AsyncLabyrinthe(api_key=KEY, base_url=BASE_URL, max_retries=3, max_retry_wait=65)
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

    # Forme du code (API 1.2.0).
    assert otp.code_length == len(code)
    assert otp.code_type in (OtpCodeType.NUMERIC, OtpCodeType.ALPHANUMERIC)
    if otp.code_type is OtpCodeType.NUMERIC:
        assert code.isdigit()
    assert otp.resend_after_seconds is not None
    assert otp.resend_after_seconds >= 0
    assert otp.request_id

    wrong = "1" * len(code) if code != "1" * len(code) else "2" * len(code)
    assert run(lambda c: c.otp.verify(otp_id=otp.otp_id, code=wrong)) == OtpVerifyResult(
        False, OtpFailureReason.INVALID_CODE, otp.max_attempts - 1
    )
    assert run(lambda c: c.otp.verify(otp_id=otp.otp_id, code=code)) == OtpVerifyResult(True, None, None)
    assert run(lambda c: c.otp.verify(otp_id=otp.otp_id, code=code)) == OtpVerifyResult(
        False, OtpFailureReason.ALREADY_USED, None
    )


def test_otp_send_is_replayed_with_the_same_idempotency_key(run: Runner) -> None:
    key = f"sdk-python-it-otp-{secrets.token_hex(8)}"
    phone = random_phone()
    first = run(lambda c: c.otp.send(phone=phone, idempotency_key=key))
    # Même numéro sous une autre forme : même empreinte normalisée, donc rejeu.
    again = run(lambda c: c.otp.send(phone="243" + phone[1:], idempotency_key=key))
    assert first.replayed is False
    assert again.replayed is True
    assert again.otp_id == first.otp_id
    assert again.test_code == first.test_code
    assert again.idempotency_key == key
    with pytest.raises(IdempotencyKeyReusedError):
        run(lambda c: c.otp.send(phone=phone, locale="en", idempotency_key=key))


def test_estimate_strip_accents_gives_gsm7(run: Runner) -> None:
    body = "Votre réservation à l'hôtel est confirmée."
    assert run(lambda c: c.sms.estimate(body=body, recipients="0812345678")).encoding.value == "ucs2"
    stripped = run(lambda c: c.sms.estimate(body=body, recipients="0812345678", strip_accents=True))
    assert stripped.encoding.value == "gsm7"
    # Doublons fusionnés comme à l'envoi.
    deduplicated = run(
        lambda c: c.sms.estimate(body="Hello", recipients=["0812345678", "243812345678", "+243812345678"])
    )
    assert deduplicated.recipients_count == 1


def test_a_foreign_number_without_plus_is_kept(run: Runner) -> None:
    message = run(lambda c: c.sms.send(to="33612345678", body="Integration test", reference=reference()))
    assert message.recipient == "33612345678"


def test_a_short_foreign_number_keeps_its_country_code(run: Runner) -> None:
    """« +2481234567 » (Seychelles) n'est pas lu comme un numéro local RDC."""
    estimate = run(lambda c: c.sms.estimate(body="Hello", recipients=["+2481234567", "2481234567"]))
    assert estimate.recipients_count == 2  # sans « + », le second est un autre numéro (2432481234567)
    message = run(lambda c: c.sms.send(to="+2481234567", body="Integration test", reference=reference()))
    assert message.recipient == "2481234567"
    page = run(lambda c: c.sms.list(recipient="+2481234567", per_page=1))
    assert page.data
    assert page.data[0].recipient == "2481234567"


def test_errors_and_responses_carry_the_request_id(run: Runner) -> None:
    with pytest.raises(NotFoundError) as info:
        run(lambda c: c.sms.get("00000000-0000-4000-8000-000000000000", request_id="sdk-python-it.42"))
    assert info.value.request_id == "sdk-python-it.42"
    assert info.value.api_version == "1"
    with pytest.raises(NotFoundError) as generated:
        run(lambda c: c.sms.get("00000000-0000-4000-8000-000000000000"))
    assert generated.value.request_id
    assert generated.value.request_id != "sdk-python-it.42"
    assert run(lambda c: c.balance.get(request_id="sdk-python-it.43")).request_id == "sdk-python-it.43"


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

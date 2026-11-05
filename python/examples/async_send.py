"""Asynchronous client: send to several recipients (all or nothing), then walk the sent messages.

LABYRINTHE_API_KEY=lsk_test_… python examples/async_send.py
"""

from __future__ import annotations

import asyncio

from labyrinthe_connect import AsyncLabyrinthe, RecipientNotSendableError


async def main() -> None:
    async with AsyncLabyrinthe() as client:
        balance, sender_ids = await asyncio.gather(client.balance.get(), client.sender_ids.list())
        print(f"Balance: {balance.balance} credits; approved senders: {[s.value for s in sender_ids]}")

        try:
            batch = await client.sms.send_many(
                recipients=["0812345678", "+243 89 999 9999"],
                body="Meeting tomorrow at 9am.",
                reference="meeting-reminder",
                idempotency_key="meeting-reminder-2026-11-06",  # replaying the script does not send twice
            )
        except RecipientNotSendableError as error:
            # NOTHING was sent: fix the list and try again.
            for rejected in error.rejected:
                print(f"Refused: {rejected.reason}")
            return
        print(f"{len(batch.messages)} message(s) queued (replayed: {batch.replayed})")

        async for message in client.sms.list(reference="meeting-reminder", per_page=50).auto_paging_iter():
            print(f"{message.id} {message.status}")


if __name__ == "__main__":
    asyncio.run(main())

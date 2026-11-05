"""Send an SMS with a test key (nothing is sent, nothing is billed), then read its status.

LABYRINTHE_API_KEY=lsk_test_… python examples/send_sms.py 0812345678
"""

from __future__ import annotations

import sys

from labyrinthe_connect import InsufficientCreditsError, Labyrinthe, RecipientNotSendableError


def main() -> None:
    to = sys.argv[1] if len(sys.argv) > 1 else "0812345678"
    with Labyrinthe() as client:  # LABYRINTHE_API_KEY (and LABYRINTHE_BASE_URL) from the environment
        print(f"Test mode: {client.is_test_mode}")

        estimate = client.sms.estimate(body="Your order 1042 is ready.", recipients=to)
        print(f"Cost: {estimate.total_credits} credit(s), {estimate.encoding} — affordable: {estimate.is_affordable}")

        try:
            message = client.sms.send(to=to, body="Your order 1042 is ready.", reference="order-1042")
        except RecipientNotSendableError as error:
            print(f"Refused: {error.reason}")
            return
        except InsufficientCreditsError as error:
            print(f"Not enough credits: {error.available} available, {error.required} required")
            return

        print(f"Message {message.id}: {message.status} ({message.segments} segment(s))")
        latest = client.sms.get(message.id)
        print(f"Status now: {latest.status} (final: {latest.status.is_final})")
        print(f"Balance: {client.balance.get().balance} credits")


if __name__ == "__main__":
    main()

"""Phone verification with a one-time code, as a server would do it.

In a real app, ``otp_id`` lives in the user's server-side session, never in the browser.

    LABYRINTHE_API_KEY=lsk_test_… python examples/otp_login.py 0812345678
"""

from __future__ import annotations

import sys
from typing import Dict

from labyrinthe_connect import Labyrinthe, OtpFailureReason, OtpRateLimitedError, OtpResendTooSoonError


def main() -> None:
    phone = sys.argv[1] if len(sys.argv) > 1 else "0812345678"
    session: Dict[str, str] = {}  # stand-in for your session store

    with Labyrinthe() as client:
        # 1. The user enters their phone number: send the code.
        try:
            otp = client.otp.send(phone=phone, locale="fr")
        except OtpResendTooSoonError as error:
            print(f"Wait {error.retry_after or 0:.0f} s before asking for a new code.")
            return
        except OtpRateLimitedError as error:
            print(f"Too many codes for this {error.scope}: try again later.")
            return
        session["otp_id"] = otp.otp_id
        print(f"Code sent, valid until {otp.expires_at:%H:%M:%S} UTC ({otp.max_attempts} attempts)")

        # 2. The user types the code. In test mode the API returns it, so the flow can be tested end to end.
        typed = (otp.test_code or "") if client.is_test_mode else input("Code: ")

        # 3. Verify it. A wrong code is not an exception.
        result = client.otp.verify(otp_id=session["otp_id"], code=typed)
        if result.verified:
            del session["otp_id"]
            print("Phone verified: log the user in.")
        elif result.reason is OtpFailureReason.INVALID_CODE:
            print("Wrong code, try again.")
        else:
            print(f"Please request a new code ({result.reason}).")


if __name__ == "__main__":
    main()

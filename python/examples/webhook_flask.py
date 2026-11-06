"""Webhook receiver with Flask.

    pip install "labyrinthe-connect[flask]"
    LABYRINTHE_WEBHOOK_SECRET=… flask --app examples.webhook_flask run --port 8080

Then point an endpoint of the client area (Developers > Webhooks) to
``https://your-host/webhooks/labyrinthe`` and press *Test*.
"""

from __future__ import annotations

import logging
from typing import Set

from flask import Flask

from labyrinthe_connect import MessageStatusChanged, OtpFailed, OtpVerified, WebhookEvent, phone
from labyrinthe_connect.contrib.flask import create_webhook_blueprint

logger = logging.getLogger("webhooks")
seen: Set[str] = set()  # use your database in production: deliveries are retried and may arrive twice


def handle_event(event: WebhookEvent) -> None:
    """Called once the signature (raw body) and the timestamp are verified."""
    if event.id in seen:
        return
    seen.add(event.id)

    if isinstance(event, MessageStatusChanged):
        logger.info(
            "Message %s to %s is %s", event.data.message_id, phone.mask(event.data.recipient), event.data.status
        )
    elif isinstance(event, (OtpVerified, OtpFailed)):
        logger.info("OTP %s: %s", event.data.otp_id, event.data.status)
    # Answer 2xx within 10 seconds: the blueprint does it once this function returns; queue slow work.


app = Flask(__name__)
# Secret: app.config["LABYRINTHE_WEBHOOK_SECRET"], or the LABYRINTHE_WEBHOOK_SECRET environment variable.
app.register_blueprint(create_webhook_blueprint(handle_event))  # POST /webhooks/labyrinthe, 400 on a bad signature

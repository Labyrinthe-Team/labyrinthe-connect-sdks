"""Webhook receiver with FastAPI.

    pip install "labyrinthe-connect[fastapi]" uvicorn
    LABYRINTHE_WEBHOOK_SECRET=… uvicorn examples.webhook_fastapi:app --port 8080

Then point an endpoint of the client area (Developers > Webhooks) to
``https://your-host/webhooks/labyrinthe`` and press *Test*.
"""

from __future__ import annotations

import logging
from typing import Dict, Set

from fastapi import Depends, FastAPI

from labyrinthe_connect import BalanceLow, CampaignCompleted, MessageStatusChanged, WebhookEvent, phone
from labyrinthe_connect.contrib.fastapi import WebhookVerifier

logger = logging.getLogger("webhooks")
app = FastAPI()
verify_webhook = WebhookVerifier()  # secret from LABYRINTHE_WEBHOOK_SECRET; 400 on a bad signature
seen: Set[str] = set()  # use your database in production: deliveries are retried and may arrive twice


@app.post("/webhooks/labyrinthe")
async def labyrinthe_webhook(event: WebhookEvent = Depends(verify_webhook)) -> Dict[str, bool]:  # noqa: B008
    if event.id in seen:
        return {"received": True}
    seen.add(event.id)

    if isinstance(event, MessageStatusChanged):
        logger.info(
            "Message %s to %s is %s", event.data.message_id, phone.mask(event.data.recipient), event.data.status
        )
    elif isinstance(event, CampaignCompleted):
        logger.info("Campaign %s done: %s", event.data.name, event.data.messages_by_status)
    elif isinstance(event, BalanceLow):
        logger.warning("Balance low: %d credits (threshold %d)", event.data.balance, event.data.threshold)
    # Answer 2xx within 10 seconds; queue slow work.
    return {"received": True}

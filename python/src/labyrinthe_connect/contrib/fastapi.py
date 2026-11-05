"""FastAPI (and Starlette) integration: a dependency that verifies webhooks on the raw body.

Dependency::

    from fastapi import Depends, FastAPI
    from labyrinthe_connect import MessageStatusChanged, WebhookEvent
    from labyrinthe_connect.contrib.fastapi import WebhookVerifier

    app = FastAPI()
    verify_webhook = WebhookVerifier()          # secret from LABYRINTHE_WEBHOOK_SECRET

    @app.post("/webhooks/labyrinthe")
    async def labyrinthe_webhook(event: WebhookEvent = Depends(verify_webhook)) -> dict:
        if isinstance(event, MessageStatusChanged):
            await update_message(event.data.message_id, event.data.status)
        return {"received": True}

Ready-made route::

    from labyrinthe_connect.contrib.fastapi import create_webhook_router

    app.include_router(create_webhook_router(on_event=handle_event))

An invalid signature or payload gives ``400``; an exception in your handler
gives ``500`` (the delivery is retried: deduplicate on ``event.id``).
"""

# Pas de « from __future__ import annotations » : FastAPI lit les annotations à l'exécution,
# et sur Python 3.9 il ne sait pas résoudre une annotation différée de WebhookVerifier.__call__.
import inspect
from typing import Any, Awaitable, Callable, Dict, Optional, Union

from fastapi import APIRouter, Depends, HTTPException
from starlette.requests import Request

from ..errors import InvalidWebhookPayloadError, SignatureVerificationError
from ..webhooks import DEFAULT_TOLERANCE, SIGNATURE_HEADER, WebhookEvent, construct_event
from . import error_body, resolve_secret

__all__ = ["WebhookVerifier", "create_webhook_router"]

#: Handler of ``create_webhook_router``: a function or a coroutine function.
EventHandler = Callable[[WebhookEvent], Union[Awaitable[None], None]]


class WebhookVerifier:
    """FastAPI dependency returning the verified :data:`~labyrinthe_connect.webhooks.WebhookEvent`."""

    def __init__(self, secret: Optional[str] = None, tolerance: int = DEFAULT_TOLERANCE) -> None:
        self.secret = secret
        self.tolerance = tolerance

    async def __call__(self, request: Request) -> WebhookEvent:
        secret = resolve_secret(self.secret)
        body = await request.body()  # corps brut, avant tout parsing JSON
        try:
            return construct_event(body, request.headers.get(SIGNATURE_HEADER), secret, self.tolerance)
        except (SignatureVerificationError, InvalidWebhookPayloadError) as error:
            raise HTTPException(status_code=400, detail=error_body(error)["error"]) from None

    def __repr__(self) -> str:
        return f"WebhookVerifier(tolerance={self.tolerance!r})"


def create_webhook_router(
    on_event: EventHandler,
    *,
    path: str = "/webhooks/labyrinthe",
    secret: Optional[str] = None,
    tolerance: int = DEFAULT_TOLERANCE,
    **router_options: Any,
) -> APIRouter:
    """An ``APIRouter`` with a ``POST`` route that verifies the webhook and calls ``on_event`` (sync or async)."""
    router = APIRouter(**router_options)
    verifier = WebhookVerifier(secret=secret, tolerance=tolerance)

    async def labyrinthe_webhook(event: Any = Depends(verifier)) -> Dict[str, bool]:  # noqa: B008
        result = on_event(event)
        if inspect.isawaitable(result):
            await result
        return {"received": True}

    router.add_api_route(path, labyrinthe_webhook, methods=["POST"], include_in_schema=False)
    return router

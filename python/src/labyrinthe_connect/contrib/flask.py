"""Flask integration: a blueprint that verifies webhooks on the raw body.

::

    from flask import Flask
    from labyrinthe_connect import MessageStatusChanged
    from labyrinthe_connect.contrib.flask import create_webhook_blueprint

    def handle_event(event):
        if isinstance(event, MessageStatusChanged):
            update_message(event.data.message_id, event.data.status)

    app = Flask(__name__)
    app.config["LABYRINTHE_WEBHOOK_SECRET"] = "…"   # or the environment variable
    app.register_blueprint(create_webhook_blueprint(handle_event))   # POST /webhooks/labyrinthe

Every verified event is also sent through the :data:`webhook_received` signal
(blinker). An invalid signature or payload gives ``400``; an exception in your
handler gives ``500`` (the delivery is retried: deduplicate on ``event.id``).
"""

from __future__ import annotations

from typing import Callable, Optional, Tuple

from blinker import Namespace
from flask import Blueprint, Response, current_app, jsonify, request

from ..errors import InvalidWebhookPayloadError, SignatureVerificationError
from ..webhooks import DEFAULT_TOLERANCE, SIGNATURE_HEADER, WebhookEvent, construct_event
from . import error_body, resolve_secret

__all__ = ["create_webhook_blueprint", "webhook_received"]

_signals = Namespace()
#: Every verified event; receivers get ``sender`` (the app) and ``event``.
webhook_received = _signals.signal("labyrinthe-connect-webhook-received")

#: Handler of ``create_webhook_blueprint``; its return value is ignored.
EventHandler = Callable[[WebhookEvent], object]


def create_webhook_blueprint(
    on_event: Optional[EventHandler] = None,
    *,
    secret: Optional[str] = None,
    tolerance: int = DEFAULT_TOLERANCE,
    rule: str = "/webhooks/labyrinthe",
    name: str = "labyrinthe_webhooks",
    url_prefix: Optional[str] = None,
) -> Blueprint:
    """A blueprint with a ``POST`` route that verifies the webhook, then calls ``on_event``.

    The secret defaults to ``app.config["LABYRINTHE_WEBHOOK_SECRET"]``, then the
    ``LABYRINTHE_WEBHOOK_SECRET`` environment variable.
    """
    blueprint = Blueprint(name, __name__, url_prefix=url_prefix)

    @blueprint.route(rule, methods=["POST"])
    def labyrinthe_webhook() -> Tuple[Response, int]:
        key = resolve_secret(secret, current_app.config.get("LABYRINTHE_WEBHOOK_SECRET"))
        try:
            event = construct_event(request.get_data(), request.headers.get(SIGNATURE_HEADER), key, tolerance)
        except (SignatureVerificationError, InvalidWebhookPayloadError) as error:
            return jsonify(error_body(error)), 400
        if on_event is not None:
            on_event(event)
        webhook_received.send(current_app._get_current_object(), event=event)  # type: ignore[attr-defined]
        return jsonify({"received": True}), 200

    return blueprint

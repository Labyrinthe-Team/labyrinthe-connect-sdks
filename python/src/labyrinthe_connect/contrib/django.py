"""Django integration: a signed webhook view and signals.

``urls.py``::

    from django.urls import path
    from labyrinthe_connect.contrib.django import WebhookView

    urlpatterns = [path("webhooks/labyrinthe/", WebhookView.as_view())]

``settings.py``: ``LABYRINTHE_WEBHOOK_SECRET = os.environ["LABYRINTHE_WEBHOOK_SECRET"]`` (or only the
environment variable of the same name, or ``WebhookView.as_view(secret=...)``).

Receivers (e.g. imported from ``AppConfig.ready()``)::

    from django.dispatch import receiver
    from labyrinthe_connect.contrib.django import message_status_changed, otp_verified

    @receiver(message_status_changed)
    def on_status(sender, event, **kwargs):
        Order.objects.filter(sms_id=event.data.message_id).update(sms_status=event.data.status)

    @receiver(otp_verified)
    def on_otp_verified(sender, event, **kwargs):
        PhoneCheck.objects.filter(otp_id=event.data.otp_id).update(verified=True)

The view verifies the signature on the raw body (``request.body``), answers
400 for an invalid signature or payload, sends :data:`webhook_received` and
the signal of the event type, then answers 200. A receiver that raises makes
the view answer 500, so the delivery is retried: deduplicate on ``event.id``.
"""

from __future__ import annotations

from typing import Any, Dict, Optional

from django.conf import settings
from django.core.exceptions import ImproperlyConfigured
from django.dispatch import Signal
from django.http import HttpRequest, HttpResponse, JsonResponse
from django.utils.decorators import method_decorator
from django.views import View
from django.views.decorators.csrf import csrf_exempt

from ..errors import InvalidArgumentError, InvalidWebhookPayloadError, SignatureVerificationError
from ..types import WebhookEventType
from ..webhooks import DEFAULT_TOLERANCE, SIGNATURE_HEADER, WebhookEvent, construct_event
from . import error_body, resolve_secret

__all__ = [
    "WebhookView",
    "balance_low",
    "campaign_completed",
    "message_status_changed",
    "otp_failed",
    "otp_verified",
    "test_ping",
    "unknown_event",
    "webhook_received",
]

#: Every verified event. Receivers get ``sender`` (the event class), ``event`` and ``request``.
webhook_received = Signal()
#: ``message.status_changed`` events.
message_status_changed = Signal()
#: ``campaign.completed`` events.
campaign_completed = Signal()
#: ``balance.low`` events.
balance_low = Signal()
#: ``otp.verified`` events.
otp_verified = Signal()
#: ``otp.failed`` events.
otp_failed = Signal()
#: ``test.ping`` events.
test_ping = Signal()
#: Event types added to the API after this SDK version.
unknown_event = Signal()

_SIGNALS: Dict[WebhookEventType, Signal] = {
    WebhookEventType.MESSAGE_STATUS_CHANGED: message_status_changed,
    WebhookEventType.CAMPAIGN_COMPLETED: campaign_completed,
    WebhookEventType.BALANCE_LOW: balance_low,
    WebhookEventType.OTP_VERIFIED: otp_verified,
    WebhookEventType.OTP_FAILED: otp_failed,
    WebhookEventType.TEST_PING: test_ping,
    WebhookEventType.UNKNOWN: unknown_event,
}


@method_decorator(csrf_exempt, name="dispatch")
class WebhookView(View):
    """Receives Labyrinthe Connect webhooks (POST only, CSRF-exempt: the HMAC signature authenticates them)."""

    http_method_names = ["post"]  # noqa: RUF012 - Django API
    #: Endpoint secret; defaults to ``settings.LABYRINTHE_WEBHOOK_SECRET``, then the environment variable.
    secret: Optional[str] = None
    #: Max age of a delivery, in seconds (0 disables the check).
    tolerance: int = DEFAULT_TOLERANCE

    def post(self, request: HttpRequest, *args: Any, **kwargs: Any) -> HttpResponse:
        try:
            secret = resolve_secret(self.secret, getattr(settings, "LABYRINTHE_WEBHOOK_SECRET", None))
        except InvalidArgumentError as error:
            raise ImproperlyConfigured(error.message) from None
        try:
            event = construct_event(request.body, request.headers.get(SIGNATURE_HEADER), secret, self.tolerance)
        except (SignatureVerificationError, InvalidWebhookPayloadError) as error:
            return JsonResponse(error_body(error), status=400)
        self.handle_event(event, request)
        return JsonResponse({"received": True})

    def handle_event(self, event: WebhookEvent, request: HttpRequest) -> None:
        """Sends the signals; override it to handle events without signals."""
        webhook_received.send(sender=type(event), event=event, request=request)
        _SIGNALS[event.event].send(sender=type(event), event=event, request=request)

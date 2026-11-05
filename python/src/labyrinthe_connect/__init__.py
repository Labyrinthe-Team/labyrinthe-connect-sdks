"""Official Python SDK for Labyrinthe Connect: SMS and OTP for the DR Congo and Central Africa.

>>> from labyrinthe_connect import Labyrinthe
>>> client = Labyrinthe(api_key="lsk_test_…")        # test key: nothing is sent, nothing is billed
>>> message = client.sms.send(to="0812345678", body="Hello from Labyrinthe Connect!")
>>> message.status
<MessageStatus.QUEUED: 'queued'>
"""

import logging as _logging

from . import errors, phone, webhooks
from ._client import DEFAULT_BASE_URL, AsyncLabyrinthe, Labyrinthe
from ._version import __version__
from .errors import (
    APIConnectionError,
    ApiError,
    APITimeoutError,
    AuthenticationError,
    IdempotencyKeyReusedError,
    InsufficientCreditsError,
    InvalidArgumentError,
    InvalidPhoneError,
    InvalidWebhookPayloadError,
    LabyrintheError,
    NotFoundError,
    OtpRateLimitedError,
    OtpResendTooSoonError,
    PermissionError,  # noqa: A004 - same name as in the other SDKs
    RateLimitError,
    RecipientNotSendableError,
    SenderIdNotApprovedError,
    ServiceUnavailableError,
    SignatureVerificationError,
    UnexpectedResponseError,
    ValidationError,
)
from .pagination import AsyncPage, AsyncPaginator, SyncPage
from .phone import DEFAULT_COUNTRY_CODE
from .types import (
    Balance,
    Encoding,
    Estimate,
    Message,
    MessageBatch,
    MessageStatus,
    OtpFailureReason,
    OtpLocale,
    OtpRateLimitScope,
    OtpSendResult,
    OtpVerifyResult,
    RejectedRecipient,
    RejectionReason,
    SenderId,
    SenderIdStatus,
    WebhookEventType,
)
from .webhooks import (
    BalanceLow,
    CampaignCompleted,
    MessageStatusChanged,
    TestPing,
    UnknownEvent,
    WebhookEvent,
    construct_event,
)

# Bibliothèque : aucun log émis sans configuration explicite de l'application.
_logging.getLogger("labyrinthe_connect").addHandler(_logging.NullHandler())

__all__ = [
    "DEFAULT_BASE_URL",
    "DEFAULT_COUNTRY_CODE",
    "APIConnectionError",
    "APITimeoutError",
    "ApiError",
    "AsyncLabyrinthe",
    "AsyncPage",
    "AsyncPaginator",
    "AuthenticationError",
    "Balance",
    "BalanceLow",
    "CampaignCompleted",
    "Encoding",
    "Estimate",
    "IdempotencyKeyReusedError",
    "InsufficientCreditsError",
    "InvalidArgumentError",
    "InvalidPhoneError",
    "InvalidWebhookPayloadError",
    "Labyrinthe",
    "LabyrintheError",
    "Message",
    "MessageBatch",
    "MessageStatus",
    "MessageStatusChanged",
    "NotFoundError",
    "OtpFailureReason",
    "OtpLocale",
    "OtpRateLimitScope",
    "OtpRateLimitedError",
    "OtpResendTooSoonError",
    "OtpSendResult",
    "OtpVerifyResult",
    "PermissionError",
    "RateLimitError",
    "RecipientNotSendableError",
    "RejectedRecipient",
    "RejectionReason",
    "SenderId",
    "SenderIdNotApprovedError",
    "SenderIdStatus",
    "ServiceUnavailableError",
    "SignatureVerificationError",
    "SyncPage",
    "TestPing",
    "UnexpectedResponseError",
    "UnknownEvent",
    "ValidationError",
    "WebhookEvent",
    "WebhookEventType",
    "__version__",
    "construct_event",
    "errors",
    "phone",
    "webhooks",
]

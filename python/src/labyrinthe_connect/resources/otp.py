"""One-time passwords by SMS (``client.otp``).

1. ``send(phone=...)`` and keep ``otp_id`` server-side (session);
2. the user types the code received by SMS (``code_length`` and ``code_type`` give its shape);
3. ``verify(otp_id=..., code=...)``: log the user in when ``verified`` is true.

Code length, alphabet, validity, attempts, resend delay, sender and template come
from the account's OTP settings (client area > OTP verification).
"""

from __future__ import annotations

import re
from typing import TYPE_CHECKING, Dict, Optional, Union

from .._data import api, to_otp_verify_result
from .._transport import ApiResponse, Json, RetryMode, idempotency_key_or_new
from ..errors import InvalidArgumentError
from ..phone import _to_e164, normalize_or_raise
from ..types import OtpCodeType, OtpLocale, OtpSendResult, OtpVerifyResult

if TYPE_CHECKING:
    from .._transport import AsyncTransport, SyncTransport

__all__ = [
    "MAX_CODE_LENGTH",
    "MAX_TEMPLATE_LENGTH",
    "MAX_WEB_OTP_DOMAIN_LENGTH",
    "WEB_OTP_DOMAIN_PATTERN",
    "AsyncOtp",
    "Otp",
]

MAX_TEMPLATE_LENGTH = 1600
MAX_CODE_LENGTH = 20
MAX_WEB_OTP_DOMAIN_LENGTH = 253
#: Nom d'hôte accepté par l'API pour ``web_otp_domain`` (spec : ``SendOtpRequest.web_otp_domain.pattern``).
WEB_OTP_DOMAIN_PATTERN = (
    r"^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$"
)
_WEB_OTP_DOMAIN = re.compile(WEB_OTP_DOMAIN_PATTERN)

LocaleParam = Union[OtpLocale, str, None]


class _OtpCore:
    def __init__(self, default_country_code: str) -> None:
        self._default_country_code = default_country_code

    def _send_request(
        self,
        phone: str,
        template: Optional[str],
        locale: LocaleParam,
        web_otp_domain: Optional[str],
        strip_accents: bool,
        idempotency_key: Optional[str],
    ) -> tuple[Json, Dict[str, str], str]:
        body: Json = {"phone": _to_e164(normalize_or_raise(phone, self._default_country_code, "phone"))}
        if template is not None:
            if not isinstance(template, str) or "{code}" not in template:
                raise InvalidArgumentError("The OTP template must contain the {code} token.")
            if len(template) > MAX_TEMPLATE_LENGTH:
                raise InvalidArgumentError(f"The OTP template must be at most {MAX_TEMPLATE_LENGTH} characters.")
            body["template"] = template
        if locale is not None:
            if locale not in ("fr", "en"):
                raise InvalidArgumentError('locale must be "fr" or "en".')
            body["locale"] = str(OtpLocale(locale))
        if web_otp_domain is not None:
            if not isinstance(web_otp_domain, str) or not _WEB_OTP_DOMAIN.fullmatch(web_otp_domain):
                raise InvalidArgumentError(
                    "web_otp_domain must be a host name with at least two labels, "
                    "without scheme, port or path (e.g. app.example.com)."
                )
            body["web_otp_domain"] = web_otp_domain
        if not isinstance(strip_accents, bool):
            raise InvalidArgumentError("strip_accents must be a boolean.")
        if strip_accents:
            body["strip_accents"] = True
        # Générée une fois et réutilisée par chaque relance : un rejeu ne renvoie jamais de SMS.
        key = idempotency_key_or_new(idempotency_key)
        return body, {"Idempotency-Key": key}, key

    @staticmethod
    def _verify_body(otp_id: str, code: str) -> Json:
        if not isinstance(otp_id, str) or not otp_id.strip():
            raise InvalidArgumentError("otp_id cannot be empty.")
        if not isinstance(code, str) or not code.strip() or len(code) > MAX_CODE_LENGTH:
            # Le code saisi n'apparaît jamais dans le message.
            raise InvalidArgumentError(f"The code must be 1 to {MAX_CODE_LENGTH} characters.")
        return {"otp_id": otp_id, "code": code}

    @staticmethod
    def _send_result(response: ApiResponse, idempotency_key: str) -> OtpSendResult:
        data = api.obj(response.body, "data")
        # code_length, code_type, resend_after_seconds : absents avant l'API 1.2.0 → None / UNKNOWN.
        code_type = api.nullable_string(data, "code_type")
        return OtpSendResult(
            otp_id=api.string(data, "otp_id"),
            expires_at=api.date_time(data, "expires_at"),
            max_attempts=api.integer(data, "max_attempts"),
            test_code=api.nullable_string(data, "test_code"),
            code_length=api.nullable_integer(data, "code_length"),
            code_type=OtpCodeType.UNKNOWN if code_type is None else OtpCodeType.parse(code_type),
            resend_after_seconds=api.nullable_integer(data, "resend_after_seconds"),
            replayed=response.replayed,
            idempotency_key=idempotency_key,
            request_id=response.request_id,
        )

    @staticmethod
    def _verify_result(response: ApiResponse) -> OtpVerifyResult:
        return to_otp_verify_result(api.obj(response.body, "data"), response.request_id)


class Otp(_OtpCore):
    """One-time passwords by SMS (``client.otp``)."""

    def __init__(self, transport: SyncTransport, default_country_code: str) -> None:
        super().__init__(default_country_code)
        self._transport = transport

    def send(
        self,
        *,
        phone: str,
        template: Optional[str] = None,
        locale: LocaleParam = None,
        web_otp_domain: Optional[str] = None,
        strip_accents: bool = False,
        idempotency_key: Optional[str] = None,
        request_id: Optional[str] = None,
        timeout: Optional[float] = None,
    ) -> OtpSendResult:
        """Generates a code and sends it by SMS (billed like any SMS).

        A new send to the same number invalidates the previous code. An
        ``Idempotency-Key`` is always sent (a UUID4 when you give none) and reused
        by every retry, so network errors, 5xx, ``TOO_MANY_REQUESTS`` and
        ``IDEMPOTENCY_REQUEST_IN_PROGRESS`` are retried safely: a replay returns the
        same ``otp_id`` (``replayed``), without a second SMS. ``OTP_RESEND_TOO_SOON``
        and ``OTP_RATE_LIMITED`` are never retried, nor ``IDEMPOTENCY_KEY_UNRESOLVED``
        (``IdempotencyKeyUnresolvedError``: outcome of the original request unknown).
        ``FeatureDisabledError`` (503 ``FEATURE_DISABLED`` / ``OTP_SERVICE_DISABLED``, maintenance) is
        retried only after ``Retry-After`` when it is present and within ``max_retry_wait``.

        :param web_otp_domain: host name of the site reading the code (Web OTP API): adds the
            ``@<domain> #<code>`` line to the SMS for autofill.
        :param strip_accents: replace accents with ASCII (GSM-7, cheaper).
        """
        body, headers, key = self._send_request(phone, template, locale, web_otp_domain, strip_accents, idempotency_key)
        response = self._transport.request(
            "POST", "/otp/send", RetryMode.SAFE, body=body, headers=headers, timeout=timeout, request_id=request_id
        )
        return self._send_result(response, key)

    def verify(
        self, *, otp_id: str, code: str, request_id: Optional[str] = None, timeout: Optional[float] = None
    ) -> OtpVerifyResult:
        """Checks a code. A wrong code is NOT an exception: ``verified`` is false with a ``reason``.

        One attempt is consumed per wrong code (``attempts_remaining``). Free.
        Retried only on 429 ``TOO_MANY_REQUESTS`` and, after ``Retry-After`` when it is present and
        within ``max_retry_wait``, on 503 ``FEATURE_DISABLED`` (both refused before processing). Raises
        :class:`~labyrinthe_connect.NotFoundError` for an unknown ``otp_id``.
        """
        body = self._verify_body(otp_id, code)
        return self._verify_result(
            self._transport.request(
                "POST", "/otp/verify", RetryMode.THROTTLED_ONLY, body=body, timeout=timeout, request_id=request_id
            )
        )

    def __repr__(self) -> str:
        return f"Otp({self._transport!r})"


class AsyncOtp(_OtpCore):
    """One-time passwords by SMS (``client.otp`` of :class:`~labyrinthe_connect.AsyncLabyrinthe`)."""

    def __init__(self, transport: AsyncTransport, default_country_code: str) -> None:
        super().__init__(default_country_code)
        self._transport = transport

    async def send(
        self,
        *,
        phone: str,
        template: Optional[str] = None,
        locale: LocaleParam = None,
        web_otp_domain: Optional[str] = None,
        strip_accents: bool = False,
        idempotency_key: Optional[str] = None,
        request_id: Optional[str] = None,
        timeout: Optional[float] = None,
    ) -> OtpSendResult:
        """Generates a code and sends it by SMS. See :meth:`Otp.send`."""
        body, headers, key = self._send_request(phone, template, locale, web_otp_domain, strip_accents, idempotency_key)
        response = await self._transport.request(
            "POST", "/otp/send", RetryMode.SAFE, body=body, headers=headers, timeout=timeout, request_id=request_id
        )
        return self._send_result(response, key)

    async def verify(
        self, *, otp_id: str, code: str, request_id: Optional[str] = None, timeout: Optional[float] = None
    ) -> OtpVerifyResult:
        """Checks a code. See :meth:`Otp.verify`."""
        body = self._verify_body(otp_id, code)
        return self._verify_result(
            await self._transport.request(
                "POST", "/otp/verify", RetryMode.THROTTLED_ONLY, body=body, timeout=timeout, request_id=request_id
            )
        )

    def __repr__(self) -> str:
        return f"AsyncOtp({self._transport!r})"

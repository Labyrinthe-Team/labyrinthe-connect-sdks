"""One-time passwords by SMS (``client.otp``).

1. ``send(phone=...)`` and keep ``otp_id`` server-side (session);
2. the user types the code received by SMS;
3. ``verify(otp_id=..., code=...)``: log the user in when ``verified`` is true.

Code length, validity, attempts, sender and template come from the account's
OTP settings (client area > OTP verification).
"""

from __future__ import annotations

from typing import TYPE_CHECKING, Optional, Union

from .._data import api, to_otp_verify_result
from .._transport import ApiResponse, Json, RetryMode
from ..errors import InvalidArgumentError
from ..phone import normalize_or_raise
from ..types import OtpLocale, OtpSendResult, OtpVerifyResult

if TYPE_CHECKING:
    from .._transport import AsyncTransport, SyncTransport

__all__ = ["MAX_CODE_LENGTH", "MAX_TEMPLATE_LENGTH", "AsyncOtp", "Otp"]

MAX_TEMPLATE_LENGTH = 1600
MAX_CODE_LENGTH = 20

LocaleParam = Union[OtpLocale, str, None]


class _OtpCore:
    def __init__(self, default_country_code: str) -> None:
        self._default_country_code = default_country_code

    def _send_body(self, phone: str, template: Optional[str], locale: LocaleParam) -> Json:
        body: Json = {"phone": normalize_or_raise(phone, self._default_country_code, "phone")}
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
        return body

    @staticmethod
    def _verify_body(otp_id: str, code: str) -> Json:
        if not isinstance(otp_id, str) or not otp_id.strip():
            raise InvalidArgumentError("otp_id cannot be empty.")
        if not isinstance(code, str) or not code.strip() or len(code) > MAX_CODE_LENGTH:
            # Le code saisi n'apparaît jamais dans le message.
            raise InvalidArgumentError(f"The code must be 1 to {MAX_CODE_LENGTH} characters.")
        return {"otp_id": otp_id, "code": code}

    @staticmethod
    def _send_result(response: ApiResponse) -> OtpSendResult:
        data = api.obj(response.body, "data")
        return OtpSendResult(
            otp_id=api.string(data, "otp_id"),
            expires_at=api.date_time(data, "expires_at"),
            max_attempts=api.integer(data, "max_attempts"),
            test_code=api.nullable_string(data, "test_code"),
        )

    @staticmethod
    def _verify_result(response: ApiResponse) -> OtpVerifyResult:
        return to_otp_verify_result(api.obj(response.body, "data"))


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
        timeout: Optional[float] = None,
    ) -> OtpSendResult:
        """Generates a code and sends it by SMS (billed like any SMS).

        A new send to the same number invalidates the previous code. Never
        retried automatically, except on 429 ``TOO_MANY_REQUESTS`` (refused
        before processing): a second success would send a second, billed SMS.
        """
        body = self._send_body(phone, template, locale)
        return self._send_result(
            self._transport.request("POST", "/otp/send", RetryMode.THROTTLED_ONLY, body=body, timeout=timeout)
        )

    def verify(self, *, otp_id: str, code: str, timeout: Optional[float] = None) -> OtpVerifyResult:
        """Checks a code. A wrong code is NOT an exception: ``verified`` is false with a ``reason``.

        One attempt is consumed per wrong code. Free. Raises
        :class:`~labyrinthe_connect.NotFoundError` for an unknown ``otp_id``.
        """
        body = self._verify_body(otp_id, code)
        return self._verify_result(
            self._transport.request("POST", "/otp/verify", RetryMode.THROTTLED_ONLY, body=body, timeout=timeout)
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
        timeout: Optional[float] = None,
    ) -> OtpSendResult:
        """Generates a code and sends it by SMS. See :meth:`Otp.send`."""
        body = self._send_body(phone, template, locale)
        return self._send_result(
            await self._transport.request("POST", "/otp/send", RetryMode.THROTTLED_ONLY, body=body, timeout=timeout)
        )

    async def verify(self, *, otp_id: str, code: str, timeout: Optional[float] = None) -> OtpVerifyResult:
        """Checks a code. See :meth:`Otp.verify`."""
        body = self._verify_body(otp_id, code)
        return self._verify_result(
            await self._transport.request("POST", "/otp/verify", RetryMode.THROTTLED_ONLY, body=body, timeout=timeout)
        )

    def __repr__(self) -> str:
        return f"AsyncOtp({self._transport!r})"

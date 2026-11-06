"""Garde le SDK aligné sur openapi/openapi.yaml (source de vérité).

Opérations, énumérations, codes d'erreur, limites ; validation (JSON Schema
Draft 2020-12) des réponses simulées et des requêtes du SDK contre les schémas
de la spec ; exemples de la spec lus par le SDK. Ignoré hors du monorepo.
"""

from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any, Dict, List, Sequence

import pytest

from labyrinthe_connect import (
    DEFAULT_BASE_URL,
    Encoding,
    MessageStatus,
    OtpCodeType,
    OtpFailureReason,
    OtpLocale,
    OtpRateLimitScope,
    OtpSendResult,
    OtpVerificationStatus,
    OtpVerifyResult,
    RejectionReason,
    SenderIdStatus,
    WebhookEventType,
    errors,
    webhooks,
)
from labyrinthe_connect import _transport as transport
from labyrinthe_connect.resources import otp as otp_limits
from labyrinthe_connect.resources import sms as sms_limits

from .helpers import Harness, json_response, message, page

SPEC_PATH = Path(__file__).resolve().parents[2] / "openapi" / "openapi.yaml"

yaml = pytest.importorskip("yaml")
jsonschema = pytest.importorskip("jsonschema")
referencing = pytest.importorskip("referencing")
referencing_jsonschema = pytest.importorskip("referencing.jsonschema")

pytestmark = pytest.mark.skipif(not SPEC_PATH.exists(), reason="openapi/openapi.yaml not found (outside the monorepo)")

SPEC_URI = "urn:labyrinthe:openapi"


@pytest.fixture(scope="module")
def spec() -> Dict[str, Any]:
    loaded: Dict[str, Any] = yaml.safe_load(SPEC_PATH.read_text(encoding="utf-8"))
    return loaded


def at(spec: Dict[str, Any], *path: Any) -> Any:
    node: Any = spec
    for segment in path:
        node = node[segment]
    return node


def pointer(path: Sequence[Any]) -> str:
    return "/".join(str(p).replace("~", "~0").replace("/", "~1") for p in path)


def validator(spec: Dict[str, Any], path: Sequence[Any]) -> Any:
    resource = referencing.Resource.from_contents(spec, default_specification=referencing_jsonschema.DRAFT202012)
    registry = referencing.Registry().with_resource(SPEC_URI, resource)
    schema = {"$schema": "https://json-schema.org/draft/2020-12/schema", "$ref": f"{SPEC_URI}#/{pointer(path)}"}
    return jsonschema.Draft202012Validator(
        schema, registry=registry, format_checker=jsonschema.Draft202012Validator.FORMAT_CHECKER
    )


def assert_valid(spec: Dict[str, Any], path: Sequence[Any], value: Any) -> None:
    problems = [e.message for e in validator(spec, path).iter_errors(value)]
    assert problems == [], f"{' > '.join(map(str, path))}: {problems}"


def response_schema(path: str, method: str, status: str) -> List[Any]:
    return ["paths", path, method, "responses", status, "content", "application/json", "schema"]


def request_schema(path: str) -> List[Any]:
    return ["paths", path, "post", "requestBody", "content", "application/json", "schema"]


def known(enum: Any) -> List[str]:
    return [m.value for m in enum.known()]


def test_targets_spec_1_2_0(spec: Dict[str, Any]) -> None:
    assert spec["info"]["version"] == "1.2.0"


def test_implements_every_operation_and_only_those(spec: Dict[str, Any]) -> None:
    operations = sorted(
        f"{method} {path}"
        for path, methods in spec["paths"].items()
        for method in methods
        if method in ("get", "post", "put", "patch", "delete")
    )
    assert operations == sorted(
        [
            "get /balance",
            "get /sender-ids",
            "get /sms",
            "get /sms/{id}",
            "post /otp/send",
            "post /otp/verify",
            "post /sms",
            "post /sms/estimate",
        ]
    )


def test_defaults_to_the_production_server(spec: Dict[str, Any]) -> None:
    assert spec["servers"][0]["url"] == DEFAULT_BASE_URL


def test_has_the_enums_of_the_spec(spec: Dict[str, Any]) -> None:
    schemas = spec["components"]["schemas"]
    assert known(MessageStatus) == schemas["MessageStatus"]["enum"]
    assert known(SenderIdStatus) == schemas["SenderIdStatus"]["enum"]
    assert known(RejectionReason) == schemas["RejectionReason"]["enum"]
    assert known(Encoding) == schemas["Encoding"]["enum"]
    assert schemas["Message"]["properties"]["encoding"]["$ref"] == "#/components/schemas/Encoding"
    assert schemas["Estimate"]["properties"]["encoding"]["$ref"] == "#/components/schemas/Encoding"
    assert known(OtpFailureReason) == schemas["OtpVerifyResult"]["properties"]["reason"]["oneOf"][1]["enum"]
    assert known(OtpCodeType) == schemas["OtpSendResult"]["properties"]["code_type"]["enum"]
    assert known(OtpVerificationStatus) == schemas["OtpWebhookData"]["properties"]["status"]["enum"]
    # Les motifs d'un webhook OTP sont un sous-ensemble de ceux de /otp/verify (même énumération côté SDK).
    assert set(schemas["OtpWebhookData"]["properties"]["reason"]["oneOf"][1]["enum"]) <= set(known(OtpFailureReason))
    scope = at(schemas, "OtpRateLimitedError", "allOf", 1, "properties", "error", "properties", "details")
    assert known(OtpRateLimitScope) == scope["properties"]["scope"]["enum"]
    assert known(WebhookEventType) == schemas["WebhookEnvelope"]["properties"]["event"]["enum"]
    assert known(WebhookEventType) == list(spec["webhooks"])
    assert [m.value for m in OtpLocale] == schemas["SendOtpRequest"]["properties"]["locale"]["enum"]


def test_handles_every_documented_error_code(spec: Dict[str, Any]) -> None:
    generic = {"HTTP_ERROR", "INVALID_OTP_TEMPLATE"}  # remontés volontairement en ApiError générique
    for code in spec["components"]["schemas"]["ErrorCode"]["examples"]:
        assert code in errors.ERROR_CLASSES_BY_CODE or code in generic, f"{code} is not handled"
    assert errors.ERROR_CLASSES_BY_CODE["ACCOUNT_SUSPENDED"] is errors.AccountSuspendedError
    assert issubclass(errors.AccountSuspendedError, errors.PermissionError)
    in_progress = errors.ERROR_CLASSES_BY_CODE["IDEMPOTENCY_REQUEST_IN_PROGRESS"]
    assert in_progress is errors.IdempotencyRequestInProgressError
    assert errors.ERROR_CLASSES_BY_CODE["IDEMPOTENCY_KEY_UNRESOLVED"] is errors.IdempotencyKeyUnresolvedError


def test_sends_and_reads_the_headers_of_the_spec(spec: Dict[str, Any]) -> None:
    parameters = spec["components"]["parameters"]
    assert parameters["RequestId"]["name"] == "X-Request-Id"
    assert parameters["RequestId"]["schema"]["pattern"] == transport.REQUEST_ID_PATTERN
    assert parameters["IdempotencyKey"]["name"] == "Idempotency-Key"
    assert set(spec["components"]["headers"]) >= {"X-Request-Id", "X-Api-Version", "Idempotent-Replayed", "Retry-After"}
    # Idempotency-Key accepté par les deux opérations d'envoi, et elles seules.
    with_key = sorted(
        f"{method} {path}"
        for path, methods in spec["paths"].items()
        for method, operation in methods.items()
        if any(p.get("$ref") == "#/components/parameters/IdempotencyKey" for p in operation.get("parameters", []))
    )
    assert with_key == ["post /otp/send", "post /sms"]
    # X-Request-Id accepté par chaque opération.
    for path, methods in spec["paths"].items():
        for method, operation in methods.items():
            refs = [p.get("$ref") for p in operation.get("parameters", [])]
            assert "#/components/parameters/RequestId" in refs, f"{method} {path}"


def test_enforces_the_limits_of_the_spec(spec: Dict[str, Any]) -> None:
    send = spec["components"]["schemas"]["SendSmsRequest"]["properties"]
    assert send["body"]["maxLength"] == sms_limits.MAX_BODY_LENGTH
    assert send["sender"]["maxLength"] == sms_limits.MAX_SENDER_LENGTH
    assert send["reference"]["maxLength"] == sms_limits.MAX_REFERENCE_LENGTH
    assert send["recipients"]["maxItems"] == sms_limits.MAX_RECIPIENTS
    assert (
        spec["components"]["parameters"]["IdempotencyKey"]["schema"]["maxLength"]
        == sms_limits.MAX_IDEMPOTENCY_KEY_LENGTH
    )
    per_page = next(p for p in spec["paths"]["/sms"]["get"]["parameters"] if p.get("name") == "per_page")
    assert per_page["schema"]["maximum"] == sms_limits.MAX_PER_PAGE
    assert per_page["schema"]["default"] == sms_limits.DEFAULT_PER_PAGE
    otp = spec["components"]["schemas"]
    assert otp["SendOtpRequest"]["properties"]["template"]["maxLength"] == otp_limits.MAX_TEMPLATE_LENGTH
    assert otp["VerifyOtpRequest"]["properties"]["code"]["maxLength"] == otp_limits.MAX_CODE_LENGTH
    domain = otp["SendOtpRequest"]["properties"]["web_otp_domain"]
    assert domain["pattern"] == otp_limits.WEB_OTP_DOMAIN_PATTERN
    assert domain["maxLength"] == otp_limits.MAX_WEB_OTP_DOMAIN_LENGTH
    assert "strip_accents" in otp["SendSmsRequest"]["properties"]
    assert "strip_accents" in otp["EstimateSmsRequest"]["properties"]
    assert "strip_accents" in otp["SendOtpRequest"]["properties"]
    assert spec["components"]["parameters"]["WebhookSignature"]["name"] == webhooks.SIGNATURE_HEADER


def test_message_fixture_has_exactly_the_required_fields(spec: Dict[str, Any]) -> None:
    assert sorted(message()) == sorted(spec["components"]["schemas"]["Message"]["required"])


def _decoded(responder: Any) -> Any:
    import httpx

    return json.loads(responder(httpx.Request("GET", "https://x")).content)


def test_mocked_responses_are_valid_responses_of_the_spec(spec: Dict[str, Any]) -> None:
    fixtures: List[Any] = [
        (response_schema("/sms", "post", "202"), {"data": [message(), message(status="delivered")]}),
        (response_schema("/sms/{id}", "get", "200"), {"data": message(reference=None, encoding="ucs2")}),
        (response_schema("/balance", "get", "200"), {"data": {"balance": 120, "held": 5}}),
        (
            response_schema("/sms/estimate", "post", "200"),
            {
                "data": {
                    "encoding": "ucs2",
                    "characters": 19,
                    "segments_per_message": 1,
                    "recipients_count": 1,
                    "total_credits": 1,
                    "balance_after": None,
                }
            },
        ),
        (
            response_schema("/sender-ids", "get", "200"),
            {
                "data": [
                    {
                        "id": "3c9a1f7e-2b4d-4e6a-8f0c-9d1b5e7a3c20",
                        "value": "MYSHOP",
                        "status": "approved",
                        "purpose": None,
                        "rejection_reason": None,
                        "is_default": True,
                        "created_at": "2026-10-01T08:00:00+00:00",
                    }
                ]
            },
        ),
        (
            response_schema("/otp/send", "post", "201"),
            {
                "data": {
                    "otp_id": "9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10",
                    "expires_at": "2026-11-05T10:05:00+00:00",
                    "max_attempts": 3,
                    "code_length": 6,
                    "code_type": "numeric",
                    "resend_after_seconds": 60,
                    "test_code": "482913",
                }
            },
        ),
        (
            response_schema("/otp/verify", "post", "200"),
            {"data": {"verified": False, "reason": "max_attempts_reached", "attempts_remaining": 0}},
        ),
        (response_schema("/sms", "get", "200"), _decoded(page([message()]))),
        (
            ["components", "schemas", "InsufficientCreditsError"],
            {"error": {"code": "INSUFFICIENT_CREDITS", "message": "x", "details": {"available": 3, "required": 12}}},
        ),
        (
            ["components", "schemas", "RecipientNotSendableError"],
            {
                "error": {
                    "code": "RECIPIENT_NOT_SENDABLE",
                    "message": "x",
                    "details": {
                        "reason": "blacklisted",
                        "rejected": [{"recipient": "243812345678", "reason": "blacklisted"}],
                    },
                }
            },
        ),
        (
            ["components", "schemas", "OtpRateLimitedError"],
            {
                "error": {
                    "code": "OTP_RATE_LIMITED",
                    "message": "x",
                    "details": {"retry_after_seconds": 1800, "scope": "phone", "limit": 5},
                }
            },
        ),
        (
            ["components", "schemas", "IdempotencyInProgressError"],
            {
                "error": {
                    "code": "IDEMPOTENCY_REQUEST_IN_PROGRESS",
                    "message": "x",
                    "details": {"retry_after_seconds": 5},
                }
            },
        ),
        (
            ["components", "schemas", "Error"],
            {"error": {"code": "ACCOUNT_SUSPENDED", "message": "x", "details": {}}},
        ),
        (
            ["components", "schemas", "OtpResendTooSoonError"],
            {"error": {"code": "OTP_RESEND_TOO_SOON", "message": "x", "details": {"retry_after_seconds": 30}}},
        ),
        (
            ["components", "schemas", "ValidationError"],
            {
                "error": {
                    "code": "VALIDATION_ERROR",
                    "message": "x",
                    "details": {"body": ["Le champ body est obligatoire."]},
                }
            },
        ),
    ]
    for path, value in fixtures:
        assert_valid(spec, path, value)


def test_the_validator_rejects_invalid_values(spec: Dict[str, Any]) -> None:
    check = validator(spec, response_schema("/balance", "get", "200"))
    assert not check.is_valid({"data": {"balance": "x", "held": 0}})
    assert not check.is_valid({"data": {"balance": 1}})
    assert not validator(spec, request_schema("/sms")).is_valid({"recipient": "1"})
    assert not validator(spec, ["components", "schemas", "Error"]).is_valid(
        {"error": {"code": "X", "message": "x", "details": []}}
    )
    assert not validator(spec, response_schema("/otp/send", "post", "201")).is_valid(
        {"data": {"otp_id": "9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10", "expires_at": "2026-11-05T10:05:00+00:00"}}
    )


def test_every_request_sent_by_the_sdk_is_valid_for_the_spec(spec: Dict[str, Any]) -> None:
    otp_sent = json_response(
        201, {"data": {"otp_id": "otp-1", "expires_at": "2026-11-05T10:05:00+00:00", "max_attempts": 3}}
    )
    verified = json_response(200, {"data": {"verified": True, "reason": None, "attempts_remaining": None}})
    estimate = json_response(
        200,
        {
            "data": {
                "encoding": "gsm7",
                "characters": 5,
                "segments_per_message": 1,
                "recipients_count": 1,
                "total_credits": 1,
                "balance_after": 1,
            }
        },
    )
    h = Harness(
        "sync",
        [
            json_response(202, {"data": [message()]}),
            json_response(202, {"data": [message()]}),
            estimate,
            otp_sent,
            verified,
            json_response(202, {"data": [message()]}),
            estimate,
            otp_sent,
        ],
    )
    h.call(lambda c: c.sms.send(to="0812345678", body="Hello", sender="MYSHOP", reference="order-1042"))
    h.call(lambda c: c.sms.send_many(recipients=["0812345678", "+33612345678"], body="Hello"))
    h.call(lambda c: c.sms.estimate(body="Hello", recipients=["0812345678"]))
    h.call(lambda c: c.otp.send(phone="0812345678", template="Code: {code}", locale="fr"))
    h.call(lambda c: c.otp.verify(otp_id="otp-1", code="482913"))
    h.call(lambda c: c.sms.send(to="33612345678", body="Réservation confirmée", strip_accents=True, request_id="r.1"))
    h.call(lambda c: c.sms.estimate(body="Réunion", recipients=["0812345678"], strip_accents=True))
    h.call(lambda c: c.otp.send(phone="+33612345678", web_otp_domain="app.example.com", strip_accents=True))
    assert json.loads(h.requests[5].content)["recipient"] == "+33612345678"
    assert json.loads(h.requests[5].content)["strip_accents"] is True

    request_id = spec["components"]["parameters"]["RequestId"]["schema"]["pattern"]
    assert re.fullmatch(request_id, h.requests[5].headers["x-request-id"])
    for request in h.requests:
        path = request.url.path.replace("/api/public/v1", "")
        assert_valid(spec, request_schema(path), json.loads(request.content))
    key = h.requests[0].headers["idempotency-key"]
    assert len(key) <= sms_limits.MAX_IDEMPOTENCY_KEY_LENGTH
    assert "idempotency-key" in h.requests[3].headers  # POST /otp/send
    assert "idempotency-key" not in h.requests[4].headers  # POST /otp/verify
    pattern = spec["components"]["parameters"]["WebhookSignature"]["schema"]["pattern"]
    assert re.fullmatch(pattern, webhooks.sign(b"{}", "secret"))


def test_reads_the_otp_verify_examples_of_the_spec(spec: Dict[str, Any]) -> None:
    examples = at(spec, "paths", "/otp/verify", "post", "responses", "200", "content", "application/json", "examples")
    h = Harness("sync", [json_response(200, e["value"]) for e in examples.values()])
    results = [h.call(lambda c: c.otp.verify(otp_id="otp-1", code="1")) for _ in examples]
    assert results == [
        OtpVerifyResult(True, None, None),
        OtpVerifyResult(False, OtpFailureReason.INVALID_CODE, 4),
        OtpVerifyResult(False, OtpFailureReason.MAX_ATTEMPTS_REACHED, 0),
        OtpVerifyResult(False, OtpFailureReason.EXPIRED, None),
    ]


def test_reads_the_otp_send_examples_of_the_spec(spec: Dict[str, Any]) -> None:
    examples = at(spec, "paths", "/otp/send", "post", "responses", "201", "content", "application/json", "examples")
    h = Harness("sync", [json_response(201, e["value"]) for e in examples.values()])
    live: OtpSendResult = h.call(lambda c: c.otp.send(phone="0812345678"))
    test: OtpSendResult = h.call(lambda c: c.otp.send(phone="0812345678"))
    assert (live.code_length, live.code_type, live.resend_after_seconds) == (6, OtpCodeType.NUMERIC, 60)
    assert live.test_code is None
    assert test.test_code == "482913"
    assert live == test  # test_code ne compte pas dans l'égalité


def test_maps_the_common_and_error_examples_of_the_spec(spec: Dict[str, Any]) -> None:
    common = spec["x-common-responses"]
    not_found = common["404"]["content"]["application/json"]["example"]
    not_allowed = common["405"]["content"]["application/json"]["example"]
    in_progress = at(
        spec, "paths", "/sms", "post", "responses", "409", "content", "application/json", "examples", "enCours", "value"
    )
    h = Harness(
        "sync",
        [
            json_response(404, not_found, {"X-Request-Id": "r-404", "X-Api-Version": "1"}),
            json_response(405, not_allowed, {"Allow": "GET, HEAD"}),
            json_response(409, in_progress, {"Retry-After": "5"}),
        ],
        max_retries=0,
    )
    with pytest.raises(errors.NotFoundError) as missing:
        h.call(lambda c: c.balance.get())
    assert (missing.value.request_id, missing.value.api_version, missing.value.details) == ("r-404", "1", {})
    with pytest.raises(errors.ApiError) as method:
        h.call(lambda c: c.balance.get())
    assert method.value.code == "HTTP_ERROR"
    assert method.value.details == {"allowed_methods": ["GET", "HEAD"]}
    with pytest.raises(errors.IdempotencyRequestInProgressError) as busy:
        h.call(lambda c: c.sms.send(to="0812345678", body="Hello"))
    assert busy.value.retry_after == 5


def test_maps_the_error_example_of_the_spec(spec: Dict[str, Any]) -> None:
    example = at(spec, "components", "responses", "Unauthenticated", "content", "application/json", "example")
    h = Harness("sync", [json_response(401, example)])
    with pytest.raises(errors.AuthenticationError) as info:
        h.call(lambda c: c.balance.get())
    assert (info.value.code, info.value.status, info.value.message) == ("UNAUTHENTICATED", 401, "Clé API invalide.")


def test_parses_every_webhook_example_and_reads_every_required_field(spec: Dict[str, Any]) -> None:
    for name in spec["webhooks"]:
        content = at(spec, "webhooks", name, "post", "requestBody", "content", "application/json")
        example = content.get("example") or {
            "id": "00000000-0000-4000-8000-000000000000",
            "event": name,
            "data": {},
            "timestamp": 1794046500,
        }
        assert_valid(spec, ["webhooks", name, "post", "requestBody", "content", "application/json", "schema"], example)

        body = json.dumps(example).encode()
        event = webhooks.construct_event(body, webhooks.sign(body, "secret"), "secret", now=example["timestamp"])
        assert event.type == name
        assert event.event.value == name

        data_schema = content["schema"]["allOf"][1]["properties"]["data"]
        ref = data_schema.get("$ref")
        if isinstance(ref, str):
            required = at(spec, *ref[2:].split("/"))["required"]
            for field in required:
                assert hasattr(event.data, field), f"{name}.{field}"

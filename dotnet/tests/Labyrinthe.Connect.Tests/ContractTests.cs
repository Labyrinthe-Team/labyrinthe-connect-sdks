using System;
using System.Collections.Generic;
using System.Linq;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Threading.Tasks;
using Labyrinthe.Connect.Internal;
using Labyrinthe.Connect.Resources;
using Labyrinthe.Connect.Tests.Support;
using Xunit;

namespace Labyrinthe.Connect.Tests;

/// <summary>
/// Garde le SDK aligné sur openapi/openapi.yaml (source de vérité) : opérations, énumérations, codes d'erreur,
/// limites ; validation des réponses simulées et des requêtes du SDK contre les schémas ; exemples de la spec lus par
/// le SDK. Ignoré hors du monorepo (spec introuvable).
/// </summary>
public class ContractTests
{
    private static Spec S => Spec.Current ?? throw new InvalidOperationException("openapi/openapi.yaml not found");

    [SpecFact]
    public void Targets_spec_1_3_1() => Assert.Equal("1.3.1", S.At("info", "version").GetValue<string>());

    [SpecFact]
    public void Every_response_documents_the_request_id_and_api_version_headers()
    {
        var count = 0;
        foreach (var (_, path) in S.At("paths").AsObject())
        {
            foreach (var (_, operation) in path!.AsObject())
            {
                // Le X-Request-Id de l'appelant est accepté partout (WithRequestId), avec le motif que le SDK applique.
                Assert.Contains(operation!["parameters"]!.AsArray(), p => p!["$ref"]?.GetValue<string>() == "#/components/parameters/RequestId");
                foreach (var (_, response) in operation["responses"]!.AsObject())
                {
                    var resolved = response!["$ref"] is { } reference ? S.At(reference.GetValue<string>().TrimStart('#', '/').Split('/')) : response;
                    Assert.NotNull(resolved["headers"]?["X-Request-Id"]);
                    Assert.NotNull(resolved["headers"]?["X-Api-Version"]);
                    count++;
                }
            }
        }

        Assert.True(count > 30);
        Assert.Equal("^[A-Za-z0-9._:-]{1,128}$", S.At("components", "parameters", "RequestId", "schema", "pattern").GetValue<string>());
        using var client = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_x" });
        Assert.Equal("ok.A-z_0:9", client.WithRequestId("ok.A-z_0:9").RequestId);
        Assert.Equal(new string('a', 128), client.WithRequestId(new string('a', 128)).RequestId);
        foreach (var invalid in new[] { "", new string('a', 129), "with space", "slash/", "é" })
        {
            Assert.Throws<ArgumentException>(() => client.WithRequestId(invalid));
        }
    }

    [SpecFact]
    public void Implements_every_operation_and_only_those()
    {
        var operations = S.At("paths").AsObject()
            .SelectMany(path => path.Value!.AsObject().Select(method => $"{method.Key} {path.Key}"))
            .OrderBy(o => o, StringComparer.Ordinal);
        Assert.Equal(
            new[] { "get /balance", "get /sender-ids", "get /sms", "get /sms/{id}", "post /otp/send", "post /otp/verify", "post /sms", "post /sms/estimate" }.OrderBy(o => o, StringComparer.Ordinal),
            operations);
    }

    [SpecFact]
    public void Defaults_to_the_production_server() =>
        Assert.Equal(LabyrintheClientOptions.DefaultBaseUrl, S.At("servers")[0]!["url"]!.GetValue<string>());

    [SpecFact]
    public void Has_the_enums_of_the_spec()
    {
        static string[] Sorted(IEnumerable<string> values) => values.OrderBy(v => v, StringComparer.Ordinal).ToArray();
        static IEnumerable<string> Values(JsonNode node) => node.AsArray().Select(v => v!.GetValue<string>());

        Assert.Equal(Sorted(S.Enum("MessageStatus")), Sorted(ApiValues.Known<MessageStatus>()));
        // 1.2.0 : schéma Encoding partagé par Message et Estimate.
        Assert.Equal(Sorted(S.Enum("Encoding")), Sorted(ApiValues.Known<SmsEncoding>()));
        Assert.Equal("#/components/schemas/Encoding", S.Schema("Message")["properties"]!["encoding"]!["$ref"]!.GetValue<string>());
        Assert.Equal("#/components/schemas/Encoding", S.Schema("Estimate")["properties"]!["encoding"]!["$ref"]!.GetValue<string>());
        Assert.Equal(Sorted(S.Enum("SenderIdStatus")), Sorted(ApiValues.Known<SenderIdStatus>()));
        Assert.Equal(Sorted(S.Enum("RejectionReason")), Sorted(ApiValues.Known<RejectionReason>()));
        Assert.Equal(Sorted(Values(S.Schema("OtpVerifyResult")["properties"]!["reason"]!["oneOf"]![1]!["enum"]!)), Sorted(ApiValues.Known<OtpFailureReason>()));
        Assert.Equal(Sorted(Values(S.Schema("OtpRateLimitedError")["allOf"]![1]!["properties"]!["error"]!["properties"]!["details"]!["properties"]!["scope"]!["enum"]!)), Sorted(ApiValues.Known<OtpRateLimitScope>()));
        Assert.Equal(Sorted(Values(S.Schema("SendOtpRequest")["properties"]!["locale"]!["enum"]!)), Sorted(ApiValues.Known<OtpLocale>()));
        Assert.Equal(Sorted(Values(S.Schema("WebhookEnvelope")["properties"]!["event"]!["enum"]!)), Sorted(ApiValues.Known<WebhookEventType>()));
        Assert.Equal(Sorted(S.At("webhooks").AsObject().Select(w => w.Key)), Sorted(ApiValues.Known<WebhookEventType>()));
        Assert.Equal(Sorted(Values(S.Schema("OtpSendResult")["properties"]!["code_type"]!["enum"]!)), Sorted(ApiValues.Known<OtpCodeType>()));
        Assert.Equal(Sorted(Values(S.Schema("OtpWebhookData")["properties"]!["status"]!["enum"]!)), Sorted(ApiValues.Known<OtpWebhookStatus>()));
        Assert.All(Values(S.Schema("OtpWebhookData")["properties"]!["reason"]!["oneOf"]![1]!["enum"]!), reason => Assert.Contains(reason, ApiValues.Known<OtpFailureReason>()));
    }

    [SpecFact]
    public void Handles_every_documented_error_code()
    {
        var documented = S.Schema("ErrorCode")["examples"]!.AsArray().Select(v => v!.GetValue<string>()).ToHashSet(StringComparer.Ordinal);
        // Codes sans classe dédiée : ApiException générique.
        var generic = new HashSet<string>(StringComparer.Ordinal) { "HTTP_ERROR", "INVALID_OTP_TEMPLATE" };
        Assert.Equal(documented.Except(generic).OrderBy(c => c, StringComparer.Ordinal), Mapping.ErrorTypesByCode.Keys.OrderBy(c => c, StringComparer.Ordinal));
    }

    [SpecFact]
    public void Reads_the_details_of_a_disabled_feature_documented_by_the_spec()
    {
        static string[] Values(JsonNode node) => node.AsArray().Select(v => v!.GetValue<string>()).OrderBy(v => v, StringComparer.Ordinal).ToArray();
        var error = S.Schema("FeatureDisabledError")["allOf"]![1]!["properties"]!["error"]!["properties"]!;
        var codes = Values(error["code"]!["enum"]!);
        Assert.Equal(new[] { "FEATURE_DISABLED", "OTP_SERVICE_DISABLED" }, codes);
        Assert.All(codes, code => Assert.Equal(typeof(FeatureDisabledException), Mapping.ErrorTypesByCode[code]));
        Assert.Equal(new[] { "blocked_by", "feature", "message", "until" }, Values(error["details"]!["required"]!));
        Assert.Equal(
            "#/components/headers/Retry-After-Feature",
            S.At("components", "responses", "ServiceUnavailable", "headers", "Retry-After", "$ref").GetValue<string>());
    }

    [SpecFact]
    public void Enforces_the_limits_of_the_spec()
    {
        long Limit(JsonNode node, string key) => node[key]!.GetValue<long>();
        var send = S.Schema("SendSmsRequest")["properties"]!;
        Assert.Equal(SmsResource.MaxBodyLength, Limit(send["body"]!, "maxLength"));
        Assert.Equal(SmsResource.MaxRecipients, Limit(send["recipients"]!, "maxItems"));
        Assert.Equal(SmsResource.MaxSenderLength, Limit(send["sender"]!, "maxLength"));
        Assert.Equal(SmsResource.MaxReferenceLength, Limit(send["reference"]!, "maxLength"));
        Assert.Equal(SmsResource.MaxBodyLength, Limit(S.Schema("EstimateSmsRequest")["properties"]!["body"]!, "maxLength"));
        Assert.Equal(SmsResource.MaxIdempotencyKeyLength, Limit(S.At("components", "parameters", "IdempotencyKey", "schema"), "maxLength"));
        var perPage = S.At("paths", "/sms", "get", "parameters").AsArray().Single(p => p!["name"]?.GetValue<string>() == "per_page")!["schema"]!;
        Assert.Equal(SmsResource.MaxPerPage, Limit(perPage, "maximum"));
        Assert.Equal(new ListSmsOptions().PerPage, Limit(perPage, "default"));
        Assert.Equal(OtpResource.MaxTemplateLength, Limit(S.Schema("SendOtpRequest")["properties"]!["template"]!, "maxLength"));
        Assert.Equal(OtpResource.MaxCodeLength, Limit(S.Schema("VerifyOtpRequest")["properties"]!["code"]!, "maxLength"));
        Assert.Equal(OtpResource.MaxWebOtpDomainLength, Limit(S.Schema("SendOtpRequest")["properties"]!["web_otp_domain"]!, "maxLength"));
        Assert.Equal(OtpResource.MaxIdempotencyKeyLength, Limit(S.At("components", "parameters", "IdempotencyKey", "schema"), "maxLength"));
        Assert.Contains(S.At("paths", "/otp/send", "post", "parameters").AsArray(), p => p!["$ref"]?.GetValue<string>() == "#/components/parameters/IdempotencyKey");
        Assert.DoesNotContain(S.At("paths", "/otp/verify", "post", "parameters").AsArray(), p => p!["$ref"]?.GetValue<string>() == "#/components/parameters/IdempotencyKey");
        foreach (var schema in new[] { "SendSmsRequest", "EstimateSmsRequest", "SendOtpRequest" })
        {
            Assert.Equal("#/components/schemas/StripAccents", S.Schema(schema)["properties"]!["strip_accents"]!["$ref"]!.GetValue<string>());
        }
    }

    [SpecFact]
    public void The_web_otp_domain_check_matches_the_pattern_of_the_spec()
    {
        var pattern = new System.Text.RegularExpressions.Regex(S.Schema("SendOtpRequest")["properties"]!["web_otp_domain"]!["pattern"]!.GetValue<string>());
        foreach (var domain in new[] { "app.exemple.com", "exemple.com", "a-b.c-d.io", "x.co", "localhost", "app.123", "-a.com", "a.com:80", "https://a.com", "a..com", new string('a', 64) + ".com" })
        {
            var sdkAccepts = true;
            try
            {
                using var h = new Harness();
                h.Http.Json(201, Fixtures.OtpSent);
                h.Client.Otp.SendAsync(new SendOtpRequest { Phone = "0812345678", WebOtpDomain = domain }).GetAwaiter().GetResult();
            }
            catch (ArgumentException)
            {
                sdkAccepts = false;
            }

            Assert.True(pattern.IsMatch(domain) == sdkAccepts, domain);
        }
    }

    [SpecFact]
    public void Message_model_has_every_required_field()
    {
        var required = S.Schema("Message")["required"]!.AsArray().Select(v => v!.GetValue<string>()).ToArray();
        Assert.Equal(required.OrderBy(r => r, StringComparer.Ordinal), JsonNode.Parse(Fixtures.Message())!.AsObject().Select(p => p.Key).OrderBy(r => r, StringComparer.Ordinal));
        var properties = typeof(Message).GetProperties().Select(p => p.Name).ToHashSet(StringComparer.Ordinal);
        Assert.All(required, field => Assert.Contains(Pascal(field), properties));
        Assert.All(S.Schema("SenderId")["properties"]!.AsObject().Select(p => p.Key), field => Assert.Contains(Pascal(field), typeof(SenderId).GetProperties().Select(p => p.Name)));
        Assert.All(S.Schema("Estimate")["properties"]!.AsObject().Select(p => p.Key), field => Assert.Contains(Pascal(field), typeof(Estimate).GetProperties().Select(p => p.Name)));
        Assert.All(S.Schema("OtpSendResult")["properties"]!.AsObject().Select(p => p.Key), field => Assert.Contains(Pascal(field), typeof(OtpSendResult).GetProperties().Select(p => p.Name)));
        Assert.All(S.Schema("MessageStatusChangedData")["properties"]!.AsObject().Select(p => p.Key), field => Assert.Contains(Pascal(field), typeof(MessageStatusChangedData).GetProperties().Select(p => p.Name)));
        Assert.All(S.Schema("CampaignCompletedData")["properties"]!.AsObject().Select(p => p.Key), field => Assert.Contains(Pascal(field), typeof(CampaignCompletedData).GetProperties().Select(p => p.Name)));
        Assert.All(S.Schema("OtpWebhookData")["properties"]!.AsObject().Select(p => p.Key), field => Assert.Contains(Pascal(field), typeof(OtpWebhookData).GetProperties().Select(p => p.Name)));
        Assert.All(S.Schema("OtpVerifyResult")["properties"]!.AsObject().Select(p => p.Key), field => Assert.Contains(Pascal(field), typeof(OtpVerifyResult).GetProperties().Select(p => p.Name)));
        Assert.All(S.Schema("SendOtpRequest")["properties"]!.AsObject().Select(p => p.Key), field => Assert.Contains(Pascal(field), typeof(SendOtpRequest).GetProperties().Select(p => p.Name)));
        Assert.All(S.Schema("EstimateSmsRequest")["properties"]!.AsObject().Select(p => p.Key), field => Assert.Contains(Pascal(field), typeof(EstimateSmsRequest).GetProperties().Select(p => p.Name)));
        Assert.All(S.Schema("SendSmsRequest")["properties"]!.AsObject().Select(p => p.Key).Where(f => f is not ("recipient" or "recipients")), field => Assert.Contains(Pascal(field), typeof(SendSmsRequest).GetProperties().Select(p => p.Name)));
        Assert.All(S.Schema("SendSmsRequest")["properties"]!.AsObject().Select(p => p.Key).Where(f => f != "recipient"), field => Assert.Contains(Pascal(field), typeof(SendManySmsRequest).GetProperties().Select(p => p.Name)));
    }

    [SpecFact]
    public void Mocked_responses_are_valid_responses_of_the_spec()
    {
        var cases = new (JsonNode Schema, string Json)[]
        {
            (S.ResponseSchema("/sms", "post", "202"), Fixtures.Messages(Fixtures.Message(), Fixtures.Message(id: "2c9a1f7e-2b4d-4e6a-8f0c-9d1b5e7a3c21", reference: "r"))),
            (S.ResponseSchema("/sms", "get", "200"), Fixtures.Page(1, 3, 5, 2, Fixtures.Message())),
            (S.ResponseSchema("/sms/{id}", "get", "200"), Fixtures.Data(Fixtures.Message(status: "delivered"))),
            (S.ResponseSchema("/sms/estimate", "post", "200"), Fixtures.Estimate),
            (S.ResponseSchema("/balance", "get", "200"), Fixtures.Balance),
            (S.ResponseSchema("/sender-ids", "get", "200"), Fixtures.SenderIds),
            (S.ResponseSchema("/otp/send", "post", "201"), Fixtures.OtpSent),
            (S.ResponseSchema("/otp/send", "post", "201"), Fixtures.OtpSentWithCode),
            (S.ResponseSchema("/otp/verify", "post", "200"), Fixtures.Verified),
            (S.ResponseSchema("/otp/verify", "post", "200"), Fixtures.WrongCode),
            (S.ResponseSchema("/sms", "post", "409"), Error("INSUFFICIENT_CREDITS", """{"available":3,"required":10}""")),
            (S.ResponseSchema("/sms", "post", "409"), Error("IDEMPOTENCY_KEY_REUSED", "{}")),
            (S.ResponseSchema("/sms", "post", "409"), Error("IDEMPOTENCY_KEY_UNRESOLVED", "{}")),
            (S.ResponseSchema("/otp/send", "post", "409"), Error("IDEMPOTENCY_KEY_UNRESOLVED", "{}")),
            (S.ResponseSchema("/sms", "post", "409"), Error("IDEMPOTENCY_REQUEST_IN_PROGRESS", """{"retry_after_seconds":5}""")),
            (S.ResponseSchema("/otp/send", "post", "409"), Error("IDEMPOTENCY_REQUEST_IN_PROGRESS", """{"retry_after_seconds":5}""")),
            (S.ResponseSchema("/otp/send", "post", "403"), Error("ACCOUNT_SUSPENDED", "{}")),
            (S.At("components", "responses", "Forbidden", "content", "application/json", "schema"), Error("ACCOUNT_SUSPENDED", "{}")),
            (S.ResponseSchema("/otp/verify", "post", "200"), """{"data":{"verified":false,"reason":"max_attempts_reached","attempts_remaining":0}}"""),
            (S.ResponseSchema("/sms", "post", "422"), Error("RECIPIENT_NOT_SENDABLE", """{"reason":"blacklisted","rejected":[{"recipient":"243812345678","reason":"blacklisted"}]}""")),
            (S.ResponseSchema("/sms", "post", "422"), Error("VALIDATION_ERROR", """{"body":["Le champ body est obligatoire."]}""")),
            (S.ResponseSchema("/otp/send", "post", "429"), Error("OTP_RESEND_TOO_SOON", """{"retry_after_seconds":42}""")),
            (S.ResponseSchema("/otp/send", "post", "429"), Error("OTP_RATE_LIMITED", """{"retry_after_seconds":1800,"scope":"account","limit":100}""")),
            (S.At("components", "responses", "TooManyRequests", "content", "application/json", "schema"), Error("TOO_MANY_REQUESTS", """{"retry_after_seconds":30}""")),
        };

        foreach (var (schema, json) in cases)
        {
            Assert.Empty(S.Validate(schema, json));
        }
    }

    [SpecFact]
    public void The_validator_rejects_invalid_values()
    {
        Assert.NotEmpty(S.Validate(S.ResponseSchema("/balance", "get", "200"), """{"data":{"balance":"5"}}"""));
        Assert.NotEmpty(S.Validate(S.Schema("Message"), Fixtures.Message(status: "teleported")));
        Assert.NotEmpty(S.Validate(S.RequestSchema("/sms"), """{"recipient":"243812345678"}"""));
        Assert.NotEmpty(S.Validate(S.RequestSchema("/sms"), $$"""{"recipient":"243812345678","body":"{{new string('x', 1601)}}"}"""));
        Assert.NotEmpty(S.Validate(S.ResponseSchema("/otp/verify", "post", "200"), """{"data":{"verified":false,"reason":"nope","attempts_remaining":1}}"""));
        // 1.2.0 : réponses de l'ancienne forme refusées par la spec (le SDK les tolère, voir OtpTests).
        Assert.NotEmpty(S.Validate(S.ResponseSchema("/otp/verify", "post", "200"), """{"data":{"verified":false,"reason":"invalid_code"}}"""));
        Assert.NotEmpty(S.Validate(S.ResponseSchema("/otp/send", "post", "201"), """{"data":{"otp_id":"o","expires_at":"2026-11-05T10:05:00+00:00","max_attempts":3}}"""));
        Assert.NotEmpty(S.Validate(S.Schema("Error"), """{"error":{"code":"X","message":"m","details":[]}}"""));
    }

    [SpecFact]
    public async Task Every_request_sent_by_the_sdk_is_valid_for_the_spec()
    {
        using var h = new Harness();
        h.Http
            .Json(202, Fixtures.Messages(Fixtures.Message()))
            .Json(202, Fixtures.Messages(Fixtures.Message()))
            .Json(202, Fixtures.Messages(Fixtures.Message()))
            .Json(200, Fixtures.Estimate)
            .Json(201, Fixtures.OtpSent)
            .Json(201, Fixtures.OtpSent)
            .Json(201, Fixtures.OtpSent)
            .Json(200, Fixtures.Verified);

        await h.Client.Sms.SendAsync("0812345678", "Hello");
        await h.Client.Sms.SendManyAsync(new SendManySmsRequest { Recipients = new[] { "0812345678", "+33612345678" }, Body = "Hi", Sender = "MABOUTIQUE", Reference = "RAPPEL-1" });
        await h.Client.Sms.SendAsync(new SendSmsRequest { To = "33612345678", Body = "Réservation à l'hôtel", StripAccents = true });
        await h.Client.Sms.EstimateAsync(new EstimateSmsRequest { Body = "Hello", Recipients = new[] { "0812345678", "bad" }, StripAccents = true });
        await h.Client.Otp.SendAsync("0812345678");
        await h.Client.Otp.SendAsync(new SendOtpRequest { Phone = "0812345678", Template = "Code : {code}", Locale = OtpLocale.Fr });
        await h.Client.WithRequestId("trace-42").Otp.SendAsync(new SendOtpRequest { Phone = "+33612345678", WebOtpDomain = "app.exemple.com", StripAccents = true, IdempotencyKey = "login-1" });
        await h.Client.Otp.VerifyAsync("9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10", "482913");

        var paths = new[] { "/sms", "/sms", "/sms", "/sms/estimate", "/otp/send", "/otp/send", "/otp/send", "/otp/verify" };
        var key = S.At("components", "parameters", "IdempotencyKey", "schema");
        for (var i = 0; i < paths.Length; i++)
        {
            Assert.Equal("/api/public/v1" + paths[i], h.Requests[i].Path);
            Assert.Empty(S.Validate(S.RequestSchema(paths[i]), h.Requests[i].Body!));
            if (paths[i] is "/sms" or "/otp/send")
            {
                Assert.Empty(S.Validate(key, JsonValue.Create(h.Requests[i].Header("Idempotency-Key"))));
            }
        }

        Assert.Equal("trace-42", h.Requests[6].Header("X-Request-Id"));
        Assert.Null(h.Requests[5].Header("X-Request-Id"));
    }

    [SpecFact]
    public async Task Request_examples_of_the_spec_are_valid_and_accepted_by_the_sdk()
    {
        foreach (var path in new[] { "/sms", "/otp/send" })
        {
            foreach (var example in S.At("paths", path, "post", "requestBody", "content", "application/json", "examples").AsObject())
            {
                Assert.Empty(S.Validate(S.RequestSchema(path), example.Value!["value"]));
            }
        }

        // L'exemple d'envoi multiple, rejoué par le SDK : mêmes champs, numéros normalisés.
        var many = S.At("paths", "/sms", "post", "requestBody", "content", "application/json", "examples", "plusieurs", "value");
        using var h = new Harness();
        h.Http.Json(202, Fixtures.Messages(Fixtures.Message()));
        await h.Client.Sms.SendManyAsync(new SendManySmsRequest
        {
            Recipients = many["recipients"]!.AsArray().Select(r => r!.GetValue<string>()).ToArray(),
            Body = many["body"]!.GetValue<string>(),
            Sender = many["sender"]!.GetValue<string>(),
            Reference = many["reference"]!.GetValue<string>(),
        });
        var sent = JsonNode.Parse(h.Requests[0].Body!)!;
        Assert.Equal(new[] { "+243812345678", "+243899999999" }, sent["recipients"]!.AsArray().Select(r => r!.GetValue<string>()));
        Assert.Equal(many["reference"]!.GetValue<string>(), sent["reference"]!.GetValue<string>());
    }

    [SpecFact]
    public async Task Reads_the_otp_examples_of_the_spec()
    {
        var examples = S.At("paths", "/otp/verify", "post", "responses", "200", "content", "application/json", "examples");
        using var h = new Harness();
        foreach (var name in new[] { "valide", "incorrect", "bloque", "expire" })
        {
            Assert.Empty(S.Validate(S.ResponseSchema("/otp/verify", "post", "200"), examples[name]!["value"]));
            h.Http.Json(200, examples[name]!["value"]!.ToJsonString());
        }

        Assert.Equal(new OtpVerifyResult { Verified = true }, await h.Client.Otp.VerifyAsync("o", "1"));
        Assert.Equal(new OtpVerifyResult { Verified = false, Reason = OtpFailureReason.InvalidCode, AttemptsRemaining = 4 }, await h.Client.Otp.VerifyAsync("o", "1"));
        Assert.Equal(new OtpVerifyResult { Verified = false, Reason = OtpFailureReason.MaxAttemptsReached, AttemptsRemaining = 0 }, await h.Client.Otp.VerifyAsync("o", "1"));
        Assert.Equal(new OtpVerifyResult { Verified = false, Reason = OtpFailureReason.Expired }, await h.Client.Otp.VerifyAsync("o", "1"));

        var sends = S.At("paths", "/otp/send", "post", "responses", "201", "content", "application/json", "examples");
        h.Http.Json(201, sends["live"]!["value"]!.ToJsonString()).Json(201, sends["test"]!["value"]!.ToJsonString());
        var live = await h.Client.Otp.SendAsync("0812345678");
        Assert.Equal((6, OtpCodeType.Numeric, 60, (string?)null), (live.CodeLength, live.CodeType, live.ResendAfterSeconds, live.TestCode));
        Assert.Equal("482913", (await h.Client.Otp.SendAsync("0812345678")).TestCode);

        // L'exemple Web OTP de la requête, rejoué par le SDK : mêmes champs.
        var webOtp = S.At("paths", "/otp/send", "post", "requestBody", "content", "application/json", "examples", "webOtp", "value");
        h.Http.Json(201, Fixtures.OtpSent);
        await h.Client.Otp.SendAsync(new SendOtpRequest
        {
            Phone = webOtp["phone"]!.GetValue<string>(),
            WebOtpDomain = webOtp["web_otp_domain"]!.GetValue<string>(),
            StripAccents = webOtp["strip_accents"]!.GetValue<bool>(),
        });
        var sent = JsonNode.Parse(h.Requests[^1].Body!)!;
        Assert.Equal("+33612345678", sent["phone"]!.GetValue<string>());
        Assert.Equal(webOtp["web_otp_domain"]!.GetValue<string>(), sent["web_otp_domain"]!.GetValue<string>());
        Assert.True(sent["strip_accents"]!.GetValue<bool>());
    }

    [SpecFact]
    public async Task Maps_the_idempotency_in_progress_example_of_the_spec()
    {
        var example = S.At("paths", "/sms", "post", "responses", "409", "content", "application/json", "examples", "enCours", "value");
        Assert.Empty(S.Validate(S.ResponseSchema("/sms", "post", "409"), example));
        using var h = new Harness(o => o.MaxRetries = 0);
        h.Http.Json(409, example.ToJsonString(), ("Retry-After", "5"));
        var error = await Assert.ThrowsAsync<IdempotencyRequestInProgressException>(() => h.Client.Sms.SendAsync("0812345678", "x"));
        Assert.Equal(TimeSpan.FromSeconds(5), error.RetryAfter);
    }

    [SpecFact]
    public async Task Maps_the_error_example_of_the_spec()
    {
        var example = S.At("components", "responses", "Unauthenticated", "content", "application/json", "example");
        using var h = new Harness();
        h.Http.Json(401, example.ToJsonString());
        var error = await Assert.ThrowsAsync<AuthenticationException>(() => h.Client.Balance.GetAsync());
        Assert.Equal("UNAUTHENTICATED", error.Code);
        Assert.Equal(example["error"]!["message"]!.GetValue<string>(), error.Message);
    }

    [SpecFact]
    public void Parses_every_webhook_example_and_reads_every_required_field()
    {
        const string secret = "whsec_contract";
        var seen = new List<Type>();
        foreach (var (name, webhook) in S.At("webhooks").AsObject())
        {
            var content = webhook!["post"]!["requestBody"]!["content"]!["application/json"]!;
            var example = content["example"]?.DeepClone() ?? JsonNode.Parse($$"""{"id":"5e2b","event":"{{name}}","data":{},"timestamp":1794046500}""")!;
            Assert.Empty(S.Validate(content["schema"]!, example));

            var body = example.ToJsonString();
            var evt = Webhook.ConstructEvent(System.Text.Encoding.UTF8.GetBytes(body), Webhook.Sign(body, secret), secret, TimeSpan.Zero);
            Assert.Equal(name, evt.Type);
            Assert.Equal(example["id"]!.GetValue<string>(), evt.Id);
            Assert.Equal(DateTimeOffset.FromUnixTimeSeconds(example["timestamp"]!.GetValue<long>()), evt.Timestamp);
            Assert.NotEqual(WebhookEventType.Unknown, evt.EventType);
            seen.Add(evt.GetType());

            switch (evt)
            {
                case MessageStatusChanged changed:
                    Assert.Equal(example["data"]!["message_id"]!.GetValue<string>(), changed.Data.MessageId);
                    Assert.Equal(MessageStatus.Delivered, changed.Data.Status);
                    Assert.Equal("DD-88231", changed.Data.ProviderMessageId);
                    Assert.Equal("CMD-1042", changed.Data.Reference);
                    Assert.Null(changed.Data.ErrorCode);
                    Assert.Null(changed.Data.ErrorMessage);
                    break;
                case OtpVerified verified:
                    Assert.Equal(example["data"]!["otp_id"]!.GetValue<string>(), verified.Data.OtpId);
                    Assert.Equal("243812345678", verified.Data.Phone);
                    Assert.Equal(OtpWebhookStatus.Verified, verified.Data.Status);
                    Assert.Null(verified.Data.Reason);
                    Assert.Equal(new DateTimeOffset(2026, 11, 6, 9, 41, 2, TimeSpan.Zero), verified.Data.VerifiedAt);
                    break;
                case OtpFailed failed:
                    Assert.Equal(OtpWebhookStatus.Failed, failed.Data.Status);
                    Assert.Equal(OtpFailureReason.MaxAttemptsReached, failed.Data.Reason);
                    Assert.Null(failed.Data.VerifiedAt);
                    break;
                case CampaignCompleted completed:
                    Assert.Equal(1200, completed.Data.RecipientsCount);
                    Assert.Equal(1150, completed.Data.MessagesByStatus["delivered"]);
                    Assert.Equal(1200, completed.Data.CreditsCharged);
                    Assert.NotNull(completed.Data.CompletedAt);
                    break;
                case BalanceLow low:
                    Assert.Equal(950, low.Data.Balance);
                    Assert.Equal(1000, low.Data.Threshold);
                    break;
                case TestPing ping:
                    Assert.Equal(JsonValueKind.Object, ping.Data.ValueKind);
                    break;
            }
        }

        Assert.Equal(6, seen.Distinct().Count());
    }

    private static string Error(string code, string details) => "{\"error\":{\"code\":\"" + code + "\",\"message\":\"…\",\"details\":" + details + "}}";

    private static string Pascal(string snake) =>
        string.Concat(snake.Split('_').Select(part => char.ToUpperInvariant(part[0]) + part[1..])) switch
        {
            // Le solde est exposé en Balance.Available (une propriété ne peut pas porter le nom de son type).
            "Balance" => "Available",
            var name => name,
        };
}

/// <summary>Test de contrat : ignoré quand openapi/openapi.yaml est introuvable (hors du monorepo).</summary>
public sealed class SpecFactAttribute : FactAttribute
{
    public SpecFactAttribute()
    {
        if (Spec.SpecPath is null)
        {
            Skip = "openapi/openapi.yaml not found (outside the monorepo)";
        }
    }
}

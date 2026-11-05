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
    public void Targets_spec_1_1_0() => Assert.Equal("1.1.0", S.At("info", "version").GetValue<string>());

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
        Assert.Equal(Sorted(Values(S.Schema("Message")["properties"]!["encoding"]!["enum"]!)), Sorted(ApiValues.Known<SmsEncoding>()));
        Assert.Equal(Sorted(Values(S.Schema("Estimate")["properties"]!["encoding"]!["enum"]!)), Sorted(ApiValues.Known<SmsEncoding>()));
        Assert.Equal(Sorted(S.Enum("SenderIdStatus")), Sorted(ApiValues.Known<SenderIdStatus>()));
        Assert.Equal(Sorted(S.Enum("RejectionReason")), Sorted(ApiValues.Known<RejectionReason>()));
        Assert.Equal(Sorted(Values(S.Schema("OtpVerifyResult")["properties"]!["reason"]!["oneOf"]![1]!["enum"]!)), Sorted(ApiValues.Known<OtpFailureReason>()));
        Assert.Equal(Sorted(Values(S.Schema("OtpRateLimitedError")["allOf"]![1]!["properties"]!["error"]!["properties"]!["details"]!["properties"]!["scope"]!["enum"]!)), Sorted(ApiValues.Known<OtpRateLimitScope>()));
        Assert.Equal(Sorted(Values(S.Schema("SendOtpRequest")["properties"]!["locale"]!["enum"]!)), Sorted(ApiValues.Known<OtpLocale>()));
        Assert.Equal(Sorted(Values(S.Schema("WebhookEnvelope")["properties"]!["event"]!["enum"]!)), Sorted(ApiValues.Known<WebhookEventType>()));
        Assert.Equal(Sorted(S.At("webhooks").AsObject().Select(w => w.Key)), Sorted(ApiValues.Known<WebhookEventType>()));
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
            (S.ResponseSchema("/sms", "post", "422"), Error("RECIPIENT_NOT_SENDABLE", """{"reason":"blacklisted","rejected":[{"recipient":"243812345678","reason":"blacklisted"}]}""")),
            (S.ResponseSchema("/sms", "post", "422"), Error("VALIDATION_ERROR", """{"body":["Le champ body est obligatoire."]}""")),
            (S.ResponseSchema("/otp/send", "post", "429"), Error("OTP_RESEND_TOO_SOON", """{"retry_after_seconds":42}""")),
            (S.ResponseSchema("/otp/send", "post", "429"), Error("OTP_RATE_LIMITED", """{"scope":"account","limit":100}""")),
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
        Assert.NotEmpty(S.Validate(S.ResponseSchema("/otp/verify", "post", "200"), """{"data":{"verified":false,"reason":"nope"}}"""));
    }

    [SpecFact]
    public async Task Every_request_sent_by_the_sdk_is_valid_for_the_spec()
    {
        using var h = new Harness();
        h.Http
            .Json(202, Fixtures.Messages(Fixtures.Message()))
            .Json(202, Fixtures.Messages(Fixtures.Message()))
            .Json(200, Fixtures.Estimate)
            .Json(201, Fixtures.OtpSent)
            .Json(201, Fixtures.OtpSent)
            .Json(200, Fixtures.Verified);

        await h.Client.Sms.SendAsync("0812345678", "Hello");
        await h.Client.Sms.SendManyAsync(new SendManySmsRequest { Recipients = new[] { "0812345678", "+33612345678" }, Body = "Hi", Sender = "MABOUTIQUE", Reference = "RAPPEL-1" });
        await h.Client.Sms.EstimateAsync(new EstimateSmsRequest { Body = "Hello", Recipients = new[] { "0812345678", "bad" } });
        await h.Client.Otp.SendAsync("0812345678");
        await h.Client.Otp.SendAsync(new SendOtpRequest { Phone = "0812345678", Template = "Code : {code}", Locale = OtpLocale.Fr });
        await h.Client.Otp.VerifyAsync("9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10", "482913");

        var paths = new[] { "/sms", "/sms", "/sms/estimate", "/otp/send", "/otp/send", "/otp/verify" };
        for (var i = 0; i < paths.Length; i++)
        {
            Assert.Equal("/api/public/v1" + paths[i], h.Requests[i].Path);
            Assert.Empty(S.Validate(S.RequestSchema(paths[i]), h.Requests[i].Body!));
        }
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
        Assert.Equal(new[] { "243812345678", "243899999999" }, sent["recipients"]!.AsArray().Select(r => r!.GetValue<string>()));
        Assert.Equal(many["reference"]!.GetValue<string>(), sent["reference"]!.GetValue<string>());
    }

    [SpecFact]
    public async Task Reads_the_otp_verify_examples_of_the_spec()
    {
        var examples = S.At("paths", "/otp/verify", "post", "responses", "200", "content", "application/json", "examples");
        using var h = new Harness();
        h.Http.Json(200, examples["valide"]!["value"]!.ToJsonString()).Json(200, examples["incorrect"]!["value"]!.ToJsonString());
        Assert.Equal(new OtpVerifyResult { Verified = true }, await h.Client.Otp.VerifyAsync("o", "1"));
        Assert.Equal(new OtpVerifyResult { Verified = false, Reason = OtpFailureReason.InvalidCode }, await h.Client.Otp.VerifyAsync("o", "1"));
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

        Assert.Equal(4, seen.Distinct().Count());
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

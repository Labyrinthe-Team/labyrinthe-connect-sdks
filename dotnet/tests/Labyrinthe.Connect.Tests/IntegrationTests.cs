using System;
using System.Collections.Generic;
using System.Security.Cryptography;
using System.Threading.Tasks;
using Labyrinthe.Connect.Tests.Support;
using Xunit;

namespace Labyrinthe.Connect.Tests;

/// <summary>
/// Contre un vrai backend, avec une clé de TEST uniquement (rien n'est envoyé, rien n'est facturé) :
/// <c>LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 dotnet test --filter Category=Integration</c>.
/// Ignorés sans <c>LABYRINTHE_API_KEY</c> (ou avec une clé live).
/// </summary>
[Trait("Category", "Integration")]
[Collection(EnvironmentGroup.Name)]
public sealed class IntegrationTests : IDisposable
{
    private static readonly string Key = Environment.GetEnvironmentVariable("LABYRINTHE_API_KEY") ?? string.Empty;
    private static readonly string BaseUrl = Environment.GetEnvironmentVariable("LABYRINTHE_BASE_URL") is { Length: > 0 } url ? url : "http://localhost:8000";

    private readonly LabyrintheClient client;

    public IntegrationTests()
    {
        client = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = Key.Length > 0 ? Key : "lsk_test_missing", BaseUrl = BaseUrl });
    }

    public void Dispose() => client.Dispose();

    [IntegrationFact]
    public async Task Reads_the_balance()
    {
        var balance = await client.Balance.GetAsync();
        Assert.True(client.IsTestMode);
        Assert.True(balance.Held >= 0);
    }

    [IntegrationFact]
    public async Task Estimates_a_send()
    {
        var estimate = await client.Sms.EstimateAsync(new EstimateSmsRequest { Body = "Hello from the .NET SDK", Recipients = new[] { "0812345678", "not-a-number" } });
        Assert.Equal(SmsEncoding.Gsm7, estimate.Encoding);
        Assert.Equal(1, estimate.SegmentsPerMessage);
        Assert.Equal(1, estimate.RecipientsCount);
        Assert.Equal(1, estimate.TotalCredits);

        var accented = await client.Sms.EstimateAsync(new EstimateSmsRequest { Body = "Réunion à 9h", Recipients = new[] { "0812345678" } });
        Assert.Equal(SmsEncoding.Ucs2, accented.Encoding);
    }

    [IntegrationFact]
    public async Task Sends_with_a_reference_then_replays_then_refuses_the_reused_key_then_lists_and_gets()
    {
        var reference = Reference();
        var key = "sdk-dotnet-it-" + Hex(8);
        var phone = RandomPhone();

        var message = await client.Sms.SendAsync(new SendSmsRequest { To = phone, Body = "Integration test", Reference = reference, IdempotencyKey = key });
        Assert.Equal(reference, message.Reference);
        Assert.Equal("243" + phone[1..], message.Recipient);
        Assert.Equal(1, message.Segments);
        Assert.NotEqual(MessageStatus.Unknown, message.Status);
        Assert.NotNull(message.CreatedAt);
        Assert.True(message.CreatedAt <= DateTimeOffset.UtcNow.AddMinutes(1));

        // Même clé, même contenu : rejoué, rien n'est renvoyé.
        var replay = await client.Sms.SendManyAsync(new SendManySmsRequest { Recipients = new[] { phone }, Body = "Integration test", Reference = reference, IdempotencyKey = key });
        Assert.True(replay.Replayed);
        Assert.Equal(message.Id, Assert.Single(replay.Messages).Id);

        // Même clé, autre contenu : refusé.
        var reused = await Assert.ThrowsAsync<IdempotencyKeyReusedException>(() =>
            client.Sms.SendAsync(new SendSmsRequest { To = phone, Body = "Another body", Reference = reference, IdempotencyKey = key }));
        Assert.Equal(409, reused.StatusCode);

        var page = await client.Sms.ListAsync(new ListSmsOptions { Reference = reference });
        Assert.Equal(1, page.Total);
        Assert.Equal(message.Id, page.Data[0].Id);
        Assert.Equal(1, (await client.Sms.ListAsync(new ListSmsOptions { Recipient = phone, Reference = reference, PerPage = 5, From = DateFilter.At(DateTimeOffset.UtcNow.AddHours(-1)) })).Total);

        var all = new List<string>();
        await foreach (var item in client.Sms.ListAutoPagingAsync(new ListSmsOptions { Reference = reference, PerPage = 1 }))
        {
            all.Add(item.Id);
        }

        Assert.Equal(new[] { message.Id }, all);
        Assert.Equal(message.Id, (await client.Sms.GetAsync(message.Id)).Id);
    }

    [IntegrationFact]
    public async Task A_refused_multi_send_sends_nothing()
    {
        var reference = Reference();
        var error = await Assert.ThrowsAsync<SenderIdNotApprovedException>(() => client.Sms.SendManyAsync(new SendManySmsRequest
        {
            Recipients = new[] { RandomPhone(), RandomPhone() },
            Body = "Never sent",
            Sender = "NOTAPPROVED",
            Reference = reference,
        }));
        Assert.Equal("SENDER_ID_NOT_APPROVED", error.Code);
        Assert.Equal(0, (await client.Sms.ListAsync(new ListSmsOptions { Reference = reference })).Total);
    }

    [IntegrationFact]
    public async Task Otp_send_then_wrong_code_then_verify_then_already_used()
    {
        var otp = await client.Otp.SendAsync(new SendOtpRequest { Phone = RandomPhone(), Locale = OtpLocale.En });
        Assert.False(string.IsNullOrEmpty(otp.OtpId));
        Assert.True(otp.ExpiresAt > DateTimeOffset.UtcNow);
        var code = otp.TestCode;
        Assert.False(string.IsNullOrEmpty(code), "a test key returns test_code");
        Assert.DoesNotContain(code!, otp.ToString(), StringComparison.Ordinal);

        var wrong = code == "000000" ? "111111" : "000000";
        // 1.2.0 : tentatives restantes après un code faux.
        var failed = await client.Otp.VerifyAsync(otp.OtpId, wrong);
        Assert.Equal(new OtpVerifyResult { Verified = false, Reason = OtpFailureReason.InvalidCode, AttemptsRemaining = otp.MaxAttempts - 1 }, failed);
        Assert.False(string.IsNullOrEmpty(failed.RequestId));
        Assert.Equal(new OtpVerifyResult { Verified = true }, await client.Otp.VerifyAsync(otp.OtpId, code!));
        Assert.Equal(new OtpVerifyResult { Verified = false, Reason = OtpFailureReason.AlreadyUsed }, await client.Otp.VerifyAsync(otp.OtpId, code!));
    }

    [IntegrationFact]
    public async Task Otp_send_is_replayed_with_the_same_idempotency_key_and_describes_the_code()
    {
        var phone = RandomPhone();
        var key = "sdk-dotnet-it-otp-" + Hex(8);
        var first = await client.Otp.SendAsync(new SendOtpRequest { Phone = phone, IdempotencyKey = key });
        Assert.False(first.Replayed);
        Assert.Equal(key, first.IdempotencyKey);
        Assert.InRange(first.CodeLength, 4, 8);
        Assert.NotEqual(OtpCodeType.Unknown, first.CodeType);
        Assert.Equal(first.CodeLength, first.TestCode!.Length);
        if (first.CodeType == OtpCodeType.Numeric)
        {
            Assert.All(first.TestCode, c => Assert.InRange(c, '0', '9'));
        }

        Assert.True(first.ResendAfterSeconds >= 0);

        // Rejeu : même otp_id, même code, aucun nouvel envoi (sinon OTP_RESEND_TOO_SOON).
        var replay = await client.Otp.SendAsync(new SendOtpRequest { Phone = phone, IdempotencyKey = key });
        Assert.True(replay.Replayed);
        Assert.Equal(first.OtpId, replay.OtpId);
        Assert.Equal(first.TestCode, replay.TestCode);

        // Clé générée par le SDK, numéro au format local : envoi normal, puis renvoi trop rapproché (Retry-After).
        var other = RandomPhone();
        var generated = await client.Otp.SendAsync(other);
        Assert.True(Guid.TryParse(generated.IdempotencyKey, out _));
        if (generated.ResendAfterSeconds > 0)
        {
            var tooSoon = await Assert.ThrowsAsync<OtpResendTooSoonException>(() => client.Otp.SendAsync(other));
            Assert.NotNull(tooSoon.RetryAfter);
            // 1.2.0 : l'en-tête Retry-After est présent et égal à details.retry_after_seconds.
            Assert.True(tooSoon.Headers.ContainsKey("Retry-After"), "Retry-After header on OTP_RESEND_TOO_SOON");
            Assert.Equal(tooSoon.Headers["Retry-After"].Trim(), tooSoon.Details["retry_after_seconds"].GetRawText());
            Assert.False(string.IsNullOrEmpty(tooSoon.RequestId));
        }
    }

    [IntegrationFact]
    public async Task Estimate_strips_accents_to_gsm7_and_deduplicates_recipients()
    {
        var accented = new EstimateSmsRequest { Body = "Réunion à l'hôtel demain", Recipients = new[] { "0812345678", "243812345678", "+243812345678" } };
        var ucs2 = await client.Sms.EstimateAsync(accented);
        Assert.Equal(SmsEncoding.Ucs2, ucs2.Encoding);
        Assert.Equal(1, ucs2.RecipientsCount);

        var gsm7 = await client.Sms.EstimateAsync(accented with { StripAccents = true });
        Assert.Equal(SmsEncoding.Gsm7, gsm7.Encoding);
        Assert.False(string.IsNullOrEmpty(gsm7.RequestId));
    }

    [IntegrationFact]
    public async Task A_foreign_number_without_plus_is_kept_and_the_request_id_is_echoed()
    {
        var foreign = "336" + RandomNumberGenerator.GetInt32(10_000_000, 100_000_000).ToString(System.Globalization.CultureInfo.InvariantCulture);
        Assert.Equal(foreign, PhoneNumber.Normalize(foreign));
        var requestId = "sdk-dotnet-it-" + Hex(6);
        var batch = await client.WithRequestId(requestId).Sms.SendManyAsync(new SendManySmsRequest { Recipients = new[] { foreign }, Body = "Integration test", StripAccents = true });
        Assert.Equal(foreign, Assert.Single(batch.Messages).Recipient);
        Assert.Equal(SmsEncoding.Gsm7, batch.Messages[0].Encoding);
        Assert.Equal(requestId, batch.RequestId);
    }

    // Un numéro étranger court (Seychelles, 10 chiffres) envoyé avec "+" garde son indicatif.
    [IntegrationFact]
    public async Task A_short_foreign_number_keeps_its_country_code()
    {
        var estimate = await client.Sms.EstimateAsync(new EstimateSmsRequest { Body = "Integration test", Recipients = new[] { "+2481234567", "0812345678" } });
        Assert.Equal(2, estimate.RecipientsCount);

        // Corrigé côté API : POST /sms ne renormalise plus le destinataire.
        var message = await client.Sms.SendAsync(new SendSmsRequest { To = "+2481234567", Body = "Integration test" });
        Assert.Equal("2481234567", message.Recipient);
    }

    [IntegrationFact]
    public async Task Api_errors_carry_the_request_id_and_api_version()
    {
        var error = await Assert.ThrowsAsync<NotFoundException>(() => client.Sms.GetAsync("00000000-0000-4000-8000-000000000000"));
        Assert.False(string.IsNullOrEmpty(error.RequestId));
        Assert.Equal("1", error.ApiVersion);

        var mine = await Assert.ThrowsAsync<NotFoundException>(() => client.WithRequestId("sdk-dotnet-it-err").Sms.GetAsync("00000000-0000-4000-8000-000000000000"));
        Assert.Equal("sdk-dotnet-it-err", mine.RequestId);
    }

    [IntegrationFact]
    public async Task Unknown_message_and_otp_id_give_not_found()
    {
        await Assert.ThrowsAsync<NotFoundException>(() => client.Sms.GetAsync("00000000-0000-4000-8000-000000000000"));
        await Assert.ThrowsAsync<NotFoundException>(() => client.Otp.VerifyAsync("00000000-0000-4000-8000-000000000000", "123456"));
    }

    [IntegrationFact]
    public async Task An_invalid_key_is_rejected()
    {
        using var invalid = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_invalid", BaseUrl = BaseUrl });
        await Assert.ThrowsAsync<AuthenticationException>(() => invalid.Balance.GetAsync());
    }

    [IntegrationFact]
    public async Task Lists_approved_sender_ids()
    {
        var senderIds = await client.SenderIds.ListAsync();
        Assert.All(senderIds, s => Assert.Equal(SenderIdStatus.Approved, s.Status));
    }

    /// <summary>Un numéro RDC aléatoire : les limites OTP par numéro ne se croisent jamais entre deux exécutions.</summary>
    private static string RandomPhone() => "0899" + RandomNumberGenerator.GetInt32(0, 1_000_000).ToString("D6", System.Globalization.CultureInfo.InvariantCulture);

    private static string Reference() => "sdk-dotnet-it-" + Hex(6);

    private static string Hex(int bytes) => Convert.ToHexString(RandomNumberGenerator.GetBytes(bytes)).ToLowerInvariant();
}

/// <summary>Test d'intégration : ignoré sans clé de test dans <c>LABYRINTHE_API_KEY</c>.</summary>
public sealed class IntegrationFactAttribute : FactAttribute
{
    public IntegrationFactAttribute()
    {
        if (!(Environment.GetEnvironmentVariable("LABYRINTHE_API_KEY") ?? string.Empty).StartsWith("lsk_test_", StringComparison.Ordinal))
        {
            Skip = "needs LABYRINTHE_API_KEY=lsk_test_…";
        }
    }
}

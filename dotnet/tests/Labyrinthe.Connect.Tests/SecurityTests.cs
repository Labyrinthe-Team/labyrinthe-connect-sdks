using System;
using System.Linq;
using System.Net.Http;
using System.Threading.Tasks;
using Labyrinthe.Connect.Tests.Support;
using Xunit;

namespace Labyrinthe.Connect.Tests;

public class SecurityTests
{
    private const string Key = "lsk_test_unit_secret_123";

    [Fact]
    public void The_key_never_appears_in_to_string()
    {
        using var h = new Harness();
        var options = new LabyrintheClientOptions { ApiKey = Key };
        foreach (var text in new[] { h.Client.ToString(), h.Client.Sms.ToString(), h.Client.Otp.ToString(), h.Client.Balance.ToString(), h.Client.SenderIds.ToString(), options.ToString() })
        {
            Assert.DoesNotContain("unit_secret", text, StringComparison.Ordinal);
        }

        Assert.Contains("lsk_test_****", h.Client.ToString(), StringComparison.Ordinal);
        using var live = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_live_abcdef" });
        Assert.Contains("lsk_live_****", live.ToString(), StringComparison.Ordinal);
        using var other = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "weird-key" });
        Assert.DoesNotContain("weird", other.ToString(), StringComparison.Ordinal);
    }

    [Fact]
    public async Task Logs_never_contain_the_key_the_code_the_body_or_full_numbers()
    {
        using var h = new Harness(o => o.MaxRetries = 1);
        h.Http
            .NetworkError().Json(202, Fixtures.Messages(Fixtures.Message()))
            .Json(201, Fixtures.OtpSentWithCode)
            .Json(200, Fixtures.Verified)
            .Json(200, Fixtures.Page(1, 1, 0, 15))
            .Error(422, "VALIDATION_ERROR");

        await h.Client.Sms.SendAsync("0812345678", "Votre code secret est 482913");
        await h.Client.Otp.SendAsync("0899999999");
        await h.Client.Otp.VerifyAsync("otp-1", "482913");
        await h.Client.Sms.ListAsync(new ListSmsOptions { Recipient = "0812345678" });
        await Assert.ThrowsAsync<ValidationException>(() => h.Client.Sms.SendAsync("0812345678", "x"));

        var logs = h.Logs.All;
        Assert.NotEmpty(h.Logs.Records);
        Assert.DoesNotContain("unit_secret", logs, StringComparison.Ordinal);
        Assert.DoesNotContain("482913", logs, StringComparison.Ordinal);
        Assert.DoesNotContain("Votre code", logs, StringComparison.Ordinal);
        Assert.DoesNotContain("243812345678", logs, StringComparison.Ordinal);
        Assert.Contains("recipient=24381*****78", logs, StringComparison.Ordinal);
        Assert.Contains(h.Logs.Records, r => r.Level == LabyrintheLogLevel.Warning);
        Assert.Contains(h.Logs.Records, r => r.Level == LabyrintheLogLevel.Information && r.Message.Contains("VALIDATION_ERROR", StringComparison.Ordinal));
    }

    [Fact]
    public async Task Network_errors_never_contain_the_key_or_the_query()
    {
        using var h = new Harness(o => o.MaxRetries = 0);
        h.Http.Respond((_, _) => throw new HttpRequestException("Failed GET https://api.test/sms?recipient=243812345678 with Bearer " + Key));
        var error = await Assert.ThrowsAsync<ApiConnectionException>(() => h.Client.Sms.ListAsync(new ListSmsOptions { Recipient = "0812345678" }));
        Assert.DoesNotContain("243812345678", error.Message, StringComparison.Ordinal);
        Assert.Null(error.InnerException);
        Assert.DoesNotContain("243812345678", error.ToString(), StringComparison.Ordinal);
    }

    [Fact]
    public async Task Api_errors_do_not_carry_the_request()
    {
        using var h = new Harness();
        h.Http.Error(401, "UNAUTHENTICATED");
        var error = await Assert.ThrowsAsync<AuthenticationException>(() => h.Client.Balance.GetAsync());
        Assert.DoesNotContain("unit_secret", error.ToString(), StringComparison.Ordinal);
        Assert.DoesNotContain(error.Headers, header => header.Value.Contains("unit_secret", StringComparison.Ordinal));
    }

    [Fact]
    public void Simple_logger_filters_by_level()
    {
        var records = new System.Collections.Generic.List<string>();
        var logger = LabyrintheLogger.Create((_, message) => records.Add(message), LabyrintheLogLevel.Warning);
        logger.Log(LabyrintheLogLevel.Debug, "debug");
        logger.Log(LabyrintheLogLevel.Error, "error");
        Assert.Equal(new[] { "error" }, records);
        Assert.False(logger.IsEnabled(LabyrintheLogLevel.Information));
        Assert.Throws<ArgumentNullException>(() => LabyrintheLogger.Create(null!));
    }

    [Fact]
    public void Otp_results_with_the_same_code_are_equal_but_print_nothing()
    {
        var otp = new OtpSendResult { OtpId = "o1", MaxAttempts = 3, TestCode = "123456" };
        Assert.Equal(otp, otp with { });
        Assert.DoesNotContain("123456", otp.ToString(), StringComparison.Ordinal);
        Assert.DoesNotContain("123456", new[] { otp }.Select(o => o.ToString()).Single(), StringComparison.Ordinal);
    }
}

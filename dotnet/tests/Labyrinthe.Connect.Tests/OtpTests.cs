using System;
using System.Threading.Tasks;
using Labyrinthe.Connect.Tests.Support;
using Xunit;

namespace Labyrinthe.Connect.Tests;

public class OtpTests
{
    [Fact]
    public async Task Sends_an_otp_with_a_normalized_phone()
    {
        using var h = new Harness();
        h.Http.Json(201, Fixtures.OtpSent);

        var otp = await h.Client.Otp.SendAsync("0812345678");

        Assert.Equal("/api/public/v1/otp/send", h.Requests[0].Path);
        Assert.Equal("""{"phone":"243812345678"}""", h.Requests[0].Body);
        Assert.Null(h.Requests[0].Header("Idempotency-Key"));
        Assert.Equal("7b2e4c10-3f5a-4d8e-a1b9-0c6d2e8f4a71", otp.OtpId);
        Assert.Equal(new DateTimeOffset(2026, 11, 5, 10, 5, 0, TimeSpan.Zero), otp.ExpiresAt);
        Assert.Equal(3, otp.MaxAttempts);
        Assert.Null(otp.TestCode);
    }

    [Fact]
    public async Task Sends_the_template_and_locale()
    {
        using var h = new Harness();
        h.Http.Json(201, Fixtures.OtpSent);
        await h.Client.Otp.SendAsync(new SendOtpRequest { Phone = "+33612345678", Template = "Code MaBoutique : {code}", Locale = OtpLocale.En });
        Assert.Equal("""{"phone":"33612345678","template":"Code MaBoutique : {code}","locale":"en"}""", h.Requests[0].Body);
    }

    [Fact]
    public async Task Exposes_the_test_code_but_never_prints_it()
    {
        using var h = new Harness();
        h.Http.Json(201, Fixtures.OtpSentWithCode);
        var otp = await h.Client.Otp.SendAsync("0812345678");
        Assert.Equal("482913", otp.TestCode);
        Assert.DoesNotContain("482913", otp.ToString(), StringComparison.Ordinal);
        Assert.Contains("[redacted]", otp.ToString(), StringComparison.Ordinal);
        Assert.DoesNotContain("482913", h.Logs.All, StringComparison.Ordinal);
    }

    [Fact]
    public async Task Validates_before_any_call()
    {
        using var h = new Harness();
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Otp.SendAsync("12"));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Otp.SendAsync(new SendOtpRequest { Phone = "0812345678", Template = "No token" }));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Otp.SendAsync(new SendOtpRequest { Phone = "0812345678", Template = "{code}" + new string('x', 1600) }));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Otp.SendAsync(new SendOtpRequest { Phone = "0812345678", Locale = (OtpLocale)42 }));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Otp.VerifyAsync("", "123456"));
        var code = await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Otp.VerifyAsync("otp-1", new string('9', 21)));
        Assert.DoesNotContain("999999", code.Message, StringComparison.Ordinal);
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Otp.VerifyAsync("otp-1", "  "));
        Assert.Empty(h.Requests);
    }

    [Fact]
    public async Task Verifies_a_code()
    {
        using var h = new Harness();
        h.Http.Json(200, Fixtures.Verified).Json(200, Fixtures.WrongCode).Json(200, """{"data":{"verified":false,"reason":"brand_new"}}""");

        Assert.Equal(new OtpVerifyResult { Verified = true, Reason = null }, await h.Client.Otp.VerifyAsync("otp-1", "482913"));
        Assert.Equal("""{"otp_id":"otp-1","code":"482913"}""", h.Requests[0].Body);
        Assert.Equal(new OtpVerifyResult { Verified = false, Reason = OtpFailureReason.InvalidCode }, await h.Client.Otp.VerifyAsync("otp-1", "000000"));
        Assert.Equal(OtpFailureReason.Unknown, (await h.Client.Otp.VerifyAsync("otp-1", "000000")).Reason);
        Assert.DoesNotContain("482913", h.Logs.All, StringComparison.Ordinal);
    }

    [Fact]
    public async Task Maps_the_otp_errors()
    {
        using var h = new Harness();
        h.Http
            .Error(429, "OTP_RESEND_TOO_SOON", """{"retry_after_seconds":42}""")
            .Error(429, "OTP_RATE_LIMITED", """{"scope":"account","limit":100}""")
            .Error(503, "OTP_SERVICE_DISABLED")
            .Error(403, "OTP_DISABLED_FOR_ACCOUNT")
            .Error(422, "INVALID_PHONE")
            .Error(422, "INVALID_OTP_TEMPLATE");

        var tooSoon = await Assert.ThrowsAsync<OtpResendTooSoonException>(() => h.Client.Otp.SendAsync("0812345678"));
        Assert.Equal(TimeSpan.FromSeconds(42), tooSoon.RetryAfter);
        var limited = await Assert.ThrowsAsync<OtpRateLimitedException>(() => h.Client.Otp.SendAsync("0812345678"));
        Assert.Equal(OtpRateLimitScope.Account, limited.Scope);
        Assert.Equal(100, limited.Limit);
        Assert.IsAssignableFrom<RateLimitException>(limited);
        await Assert.ThrowsAsync<ServiceUnavailableException>(() => h.Client.Otp.SendAsync("0812345678"));
        var disabled = await Assert.ThrowsAsync<PermissionException>(() => h.Client.Otp.SendAsync("0812345678"));
        Assert.Equal("OTP_DISABLED_FOR_ACCOUNT", disabled.Code);
        await Assert.ThrowsAsync<InvalidPhoneException>(() => h.Client.Otp.SendAsync("0812345678"));
        var template = await Assert.ThrowsAsync<ApiException>(() => h.Client.Otp.SendAsync("0812345678"));
        Assert.Equal("INVALID_OTP_TEMPLATE", template.Code);
        Assert.Equal(6, h.Requests.Count);
        Assert.Empty(h.Sleeps);
    }
}

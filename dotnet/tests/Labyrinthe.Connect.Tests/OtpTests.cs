using System;
using System.Linq;
using System.Threading.Tasks;
using Labyrinthe.Connect.Tests.Support;
using Xunit;

namespace Labyrinthe.Connect.Tests;

public class OtpTests
{
    // BACKEND-TODO n° 32 : le numéro part en E.164 avec "+" (un numéro étranger court garde son indicatif).
    [Fact]
    public async Task Sends_the_otp_phone_in_e164_with_plus()
    {
        using var h = new Harness();
        h.Http.Json(201, Fixtures.OtpSent).Json(201, Fixtures.OtpSent);

        await h.Client.Otp.SendAsync("+2481234567");
        await h.Client.Otp.SendAsync("0812345678");

        Assert.Equal("""{"phone":"+2481234567"}""", h.Requests[0].Body);
        Assert.Equal("""{"phone":"+243812345678"}""", h.Requests[1].Body);
    }

    [Fact]
    public async Task Sends_an_otp_with_a_normalized_phone_and_an_idempotency_key()
    {
        using var h = new Harness();
        h.Http.Json(201, Fixtures.OtpSent, ("X-Request-Id", "req-otp-1"));

        var otp = await h.Client.Otp.SendAsync("0812345678");

        Assert.Equal("/api/public/v1/otp/send", h.Requests[0].Path);
        Assert.Equal("""{"phone":"+243812345678"}""", h.Requests[0].Body);
        var key = h.Requests[0].Header("Idempotency-Key");
        Assert.True(Guid.TryParse(key, out _));
        Assert.Equal(key, otp.IdempotencyKey);
        Assert.False(otp.Replayed);
        Assert.Equal("req-otp-1", otp.RequestId);
        Assert.Equal("7b2e4c10-3f5a-4d8e-a1b9-0c6d2e8f4a71", otp.OtpId);
        Assert.Equal(new DateTimeOffset(2026, 11, 5, 10, 5, 0, TimeSpan.Zero), otp.ExpiresAt);
        Assert.Equal(3, otp.MaxAttempts);
        Assert.Equal(6, otp.CodeLength);
        Assert.Equal(OtpCodeType.Numeric, otp.CodeType);
        Assert.Equal(60, otp.ResendAfterSeconds);
        Assert.Null(otp.TestCode);
    }

    [Fact]
    public async Task Two_sends_get_two_different_keys_and_a_given_key_is_used()
    {
        using var h = new Harness();
        h.Http.Json(201, Fixtures.OtpSent).Json(201, Fixtures.OtpSent).Json(201, Fixtures.OtpSent, ("Idempotent-Replayed", "true"));
        await h.Client.Otp.SendAsync("0812345678");
        await h.Client.Otp.SendAsync("0812345678");
        Assert.NotEqual(h.Requests[0].Header("Idempotency-Key"), h.Requests[1].Header("Idempotency-Key"));

        var replay = await h.Client.Otp.SendAsync(new SendOtpRequest { Phone = "0812345678", IdempotencyKey = "login-42" });
        Assert.Equal("login-42", h.Requests[2].Header("Idempotency-Key"));
        Assert.Equal("login-42", replay.IdempotencyKey);
        Assert.True(replay.Replayed);
    }

    [Fact]
    public async Task Sends_the_template_locale_web_otp_domain_and_strip_accents()
    {
        using var h = new Harness();
        h.Http.Json(201, Fixtures.OtpSent).Json(201, Fixtures.OtpSent);
        await h.Client.Otp.SendAsync(new SendOtpRequest { Phone = "+33612345678", Template = "Code MaBoutique : {code}", Locale = OtpLocale.En });
        Assert.Equal("""{"phone":"+33612345678","template":"Code MaBoutique : {code}","locale":"en"}""", h.Requests[0].Body);

        await h.Client.Otp.SendAsync(new SendOtpRequest { Phone = "33612345678", WebOtpDomain = " App.Exemple.com ", StripAccents = true });
        Assert.Equal("""{"phone":"+33612345678","web_otp_domain":"App.Exemple.com","strip_accents":true}""", h.Requests[1].Body);
    }

    [Fact]
    public async Task Reads_an_alphanumeric_code_and_tolerates_an_older_api()
    {
        using var h = new Harness();
        h.Http
            .Json(201, """{"data":{"otp_id":"o1","expires_at":"2026-11-05T10:05:00+00:00","max_attempts":5,"code_length":8,"code_type":"alphanumeric","resend_after_seconds":0}}""")
            .Json(201, """{"data":{"otp_id":"o2","expires_at":"2026-11-05T10:05:00.000000Z","max_attempts":5}}""")
            .Json(201, """{"data":{"otp_id":"o3","expires_at":"2026-11-05T10:05:00+00:00","max_attempts":5,"code_length":6,"code_type":"emoji","resend_after_seconds":30}}""");

        var alpha = await h.Client.Otp.SendAsync("0812345678");
        Assert.Equal((8, OtpCodeType.Alphanumeric, 0), (alpha.CodeLength, alpha.CodeType, alpha.ResendAfterSeconds));

        var legacy = await h.Client.Otp.SendAsync("0812345678");
        Assert.Equal((0, OtpCodeType.Unknown, 0), (legacy.CodeLength, legacy.CodeType, legacy.ResendAfterSeconds));
        Assert.Equal(new DateTimeOffset(2026, 11, 5, 10, 5, 0, TimeSpan.Zero), legacy.ExpiresAt);

        Assert.Equal(OtpCodeType.Unknown, (await h.Client.Otp.SendAsync("0812345678")).CodeType);
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
        Assert.Contains("CodeLength = 6", otp.ToString(), StringComparison.Ordinal);
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
        foreach (var domain in new[] { "", "localhost", "https://app.exemple.com", "app.exemple.com:443", "app.exemple.com/login", "-app.exemple.com", "app.123", new string('a', 250) + ".com" })
        {
            await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Otp.SendAsync(new SendOtpRequest { Phone = "0812345678", WebOtpDomain = domain }));
        }

        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Otp.SendAsync(new SendOtpRequest { Phone = "0812345678", IdempotencyKey = "" }));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Otp.SendAsync(new SendOtpRequest { Phone = "0812345678", IdempotencyKey = new string('k', 256) }));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Otp.VerifyAsync("", "123456"));
        var code = await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Otp.VerifyAsync("otp-1", new string('9', 21)));
        Assert.DoesNotContain("999999", code.Message, StringComparison.Ordinal);
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Otp.VerifyAsync("otp-1", "  "));
        Assert.Empty(h.Requests);
    }

    [Fact]
    public async Task Verifies_a_code_and_reads_the_remaining_attempts()
    {
        using var h = new Harness();
        h.Http
            .Json(200, Fixtures.Verified, ("X-Request-Id", "req-v"))
            .Json(200, Fixtures.WrongCode)
            .Json(200, """{"data":{"verified":false,"reason":"max_attempts_reached","attempts_remaining":0}}""")
            .Json(200, """{"data":{"verified":false,"reason":"brand_new"}}""");

        var verified = await h.Client.Otp.VerifyAsync("otp-1", "482913");
        Assert.Equal(new OtpVerifyResult { Verified = true, Reason = null, AttemptsRemaining = null }, verified);
        Assert.Equal("req-v", verified.RequestId);
        Assert.Equal("""{"otp_id":"otp-1","code":"482913"}""", h.Requests[0].Body);
        Assert.Equal(new OtpVerifyResult { Verified = false, Reason = OtpFailureReason.InvalidCode, AttemptsRemaining = 2 }, await h.Client.Otp.VerifyAsync("otp-1", "000000"));
        Assert.Equal(new OtpVerifyResult { Verified = false, Reason = OtpFailureReason.MaxAttemptsReached, AttemptsRemaining = 0 }, await h.Client.Otp.VerifyAsync("otp-1", "000000"));
        var unknown = await h.Client.Otp.VerifyAsync("otp-1", "000000");
        Assert.Equal((OtpFailureReason.Unknown, (int?)null), (unknown.Reason!.Value, unknown.AttemptsRemaining));
        Assert.All(h.Requests, r => Assert.Null(r.Header("Idempotency-Key")));
        Assert.DoesNotContain("482913", h.Logs.All, StringComparison.Ordinal);
    }

    [Fact]
    public void The_request_id_is_not_part_of_the_equality_of_results()
    {
        var a = new OtpVerifyResult { Verified = true, RequestId = "a" };
        var b = new OtpVerifyResult { Verified = true, RequestId = "b" };
        Assert.Equal(a, b);
        Assert.Equal(a.GetHashCode(), b.GetHashCode());
        Assert.Equal("b", (a with { RequestId = "b" }).RequestId);
        Assert.NotEqual(a, a with { AttemptsRemaining = 1 });
        Assert.Equal(new Balance { Available = 1, RequestId = "x" }, new Balance { Available = 1 });
    }

    [Fact]
    public async Task Maps_the_otp_errors()
    {
        using var h = new Harness(o => o.MaxRetries = 0);
        h.Http
            .Error(429, "OTP_RESEND_TOO_SOON", """{"retry_after_seconds":42}""", ("Retry-After", "42"), ("X-Request-Id", "req-soon"))
            .Error(429, "OTP_RATE_LIMITED", """{"retry_after_seconds":1800,"scope":"account","limit":100}""", ("Retry-After", "1800"))
            .Error(503, "OTP_SERVICE_DISABLED")
            .Error(403, "OTP_DISABLED_FOR_ACCOUNT")
            .Error(403, "ACCOUNT_SUSPENDED")
            .Error(422, "INVALID_PHONE")
            .Error(422, "INVALID_OTP_TEMPLATE");

        var tooSoon = await Assert.ThrowsAsync<OtpResendTooSoonException>(() => h.Client.Otp.SendAsync("0812345678"));
        Assert.Equal(TimeSpan.FromSeconds(42), tooSoon.RetryAfter);
        Assert.Equal("req-soon", tooSoon.RequestId);
        var limited = await Assert.ThrowsAsync<OtpRateLimitedException>(() => h.Client.Otp.SendAsync("0812345678"));
        Assert.Equal(OtpRateLimitScope.Account, limited.Scope);
        Assert.Equal(100, limited.Limit);
        Assert.Equal(TimeSpan.FromMinutes(30), limited.RetryAfter);
        Assert.IsAssignableFrom<RateLimitException>(limited);
        await Assert.ThrowsAsync<ServiceUnavailableException>(() => h.Client.Otp.SendAsync("0812345678"));
        var disabled = await Assert.ThrowsAsync<PermissionException>(() => h.Client.Otp.SendAsync("0812345678"));
        Assert.Equal("OTP_DISABLED_FOR_ACCOUNT", disabled.Code);
        var suspended = await Assert.ThrowsAsync<AccountSuspendedException>(() => h.Client.Otp.SendAsync("0812345678"));
        Assert.IsAssignableFrom<PermissionException>(suspended);
        await Assert.ThrowsAsync<InvalidPhoneException>(() => h.Client.Otp.SendAsync("0812345678"));
        var template = await Assert.ThrowsAsync<ApiException>(() => h.Client.Otp.SendAsync("0812345678"));
        Assert.Equal("INVALID_OTP_TEMPLATE", template.Code);
        Assert.Equal(7, h.Requests.Count);
        Assert.Empty(h.Sleeps);
        Assert.Equal(7, h.Requests.Select(r => r.Header("Idempotency-Key")).Distinct().Count());
    }
}

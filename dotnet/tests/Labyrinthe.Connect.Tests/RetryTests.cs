using System;
using System.Globalization;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using Labyrinthe.Connect.Tests.Support;
using Xunit;

namespace Labyrinthe.Connect.Tests;

public class RetryTests
{
    [Fact]
    public async Task Retries_a_get_on_5xx_with_exponential_backoff()
    {
        using var h = new Harness(o => o.MaxRetries = 3);
        h.Http.Error(503, "HTTP_ERROR").Error(500, "HTTP_ERROR").Error(502, "HTTP_ERROR").Json(200, Fixtures.Balance);
        Assert.Equal(5, (await h.Client.Balance.GetAsync()).Available);
        Assert.Equal(4, h.Requests.Count);
        Assert.Equal(new[] { 0.5, 1.0, 2.0 }, h.Sleeps.Select(s => s.TotalSeconds));
    }

    [Fact]
    public async Task Jitter_keeps_the_delay_between_half_and_full_backoff()
    {
        using var h = new Harness();
        h.Client.Transport.Jitter = () => 0.0;
        h.Http.Error(503, "HTTP_ERROR").Json(200, Fixtures.Balance);
        await h.Client.Balance.GetAsync();
        Assert.Equal(TimeSpan.FromMilliseconds(250), Assert.Single(h.Sleeps));
    }

    [Fact]
    public async Task Backoff_is_capped_by_max_retry_wait()
    {
        using var h = new Harness(o =>
        {
            o.MaxRetries = 6;
            o.MaxRetryWait = TimeSpan.FromSeconds(3);
        });
        h.Http.Repeat(6, m => m.Error(503, "HTTP_ERROR")).Json(200, Fixtures.Balance);
        await h.Client.Balance.GetAsync();
        Assert.Equal(new[] { 0.5, 1.0, 2.0, 3.0, 3.0, 3.0 }, h.Sleeps.Select(s => s.TotalSeconds));
    }

    [Fact]
    public async Task Stops_after_max_retries()
    {
        using var h = new Harness(o => o.MaxRetries = 2);
        h.Http.Repeat(5, m => m.Error(503, "HTTP_ERROR"));
        await Assert.ThrowsAsync<ServiceUnavailableException>(() => h.Client.Balance.GetAsync());
        Assert.Equal(3, h.Requests.Count);
    }

    [Fact]
    public async Task Zero_max_retries_disables_retries()
    {
        using var h = new Harness(o => o.MaxRetries = 0);
        h.Http.Error(503, "HTTP_ERROR");
        await Assert.ThrowsAsync<ServiceUnavailableException>(() => h.Client.Balance.GetAsync());
        Assert.Single(h.Requests);
        Assert.Empty(h.Sleeps);
    }

    [Fact]
    public async Task Retries_gets_lists_estimates_and_sender_ids_on_network_errors()
    {
        using var h = new Harness();
        h.Http
            .NetworkError().Json(200, Fixtures.Data(Fixtures.Message()))
            .NetworkError().Json(200, Fixtures.Page(1, 1, 0, 15))
            .Error(502, "HTTP_ERROR").Json(200, Fixtures.Estimate)
            .NetworkError().Json(200, Fixtures.SenderIds);
        await h.Client.Sms.GetAsync("m1");
        await h.Client.Sms.ListAsync();
        await h.Client.Sms.EstimateAsync(new EstimateSmsRequest { Body = "x", Recipients = new[] { "0812345678" } });
        await h.Client.SenderIds.ListAsync();
        Assert.Equal(8, h.Requests.Count);
    }

    [Fact]
    public async Task Retries_an_sms_send_with_the_same_idempotency_key_and_body()
    {
        using var h = new Harness();
        h.Http.NetworkError().Error(503, "HTTP_ERROR").Json(202, Fixtures.Messages(Fixtures.Message()));
        await h.Client.Sms.SendAsync("0812345678", "Hello");
        Assert.Equal(3, h.Requests.Count);
        var key = h.Requests[0].Header("Idempotency-Key");
        Assert.False(string.IsNullOrEmpty(key));
        Assert.All(h.Requests, r => Assert.Equal(key, r.Header("Idempotency-Key")));
        Assert.All(h.Requests, r => Assert.Equal(h.Requests[0].Body, r.Body));
    }

    [Fact]
    public async Task Two_sends_get_two_different_keys()
    {
        using var h = new Harness();
        h.Http.Json(202, Fixtures.Messages(Fixtures.Message())).Json(202, Fixtures.Messages(Fixtures.Message()));
        await h.Client.Sms.SendAsync("0812345678", "Hello");
        await h.Client.Sms.SendAsync("0812345678", "Hello");
        Assert.NotEqual(h.Requests[0].Header("Idempotency-Key"), h.Requests[1].Header("Idempotency-Key"));
    }

    [Fact]
    public async Task Respects_retry_after_on_429()
    {
        using var h = new Harness();
        h.Http.Error(429, "TOO_MANY_REQUESTS", headers: ("Retry-After", "3")).Json(200, Fixtures.Balance);
        await h.Client.Balance.GetAsync();
        Assert.Equal(TimeSpan.FromSeconds(3), Assert.Single(h.Sleeps));
    }

    [Fact]
    public async Task Reads_retry_after_as_an_http_date_or_from_the_details()
    {
        using var h = new Harness();
        var at = DateTimeOffset.UtcNow.AddSeconds(5).ToString("r", CultureInfo.InvariantCulture);
        h.Http.Error(429, "TOO_MANY_REQUESTS", headers: ("Retry-After", at)).Error(429, "TOO_MANY_REQUESTS", """{"retry_after_seconds":2}""").Json(200, Fixtures.Balance);
        await h.Client.Balance.GetAsync();
        Assert.InRange(h.Sleeps[0].TotalSeconds, 3, 5);
        Assert.Equal(TimeSpan.FromSeconds(2), h.Sleeps[1]);
    }

    [Fact]
    public async Task Gives_up_when_retry_after_exceeds_max_retry_wait()
    {
        using var h = new Harness(o => o.MaxRetryWait = TimeSpan.FromSeconds(10));
        h.Http.Error(429, "TOO_MANY_REQUESTS", headers: ("Retry-After", "60"));
        var error = await Assert.ThrowsAsync<RateLimitException>(() => h.Client.Balance.GetAsync());
        Assert.Equal(TimeSpan.FromSeconds(60), error.RetryAfter);
        Assert.Single(h.Requests);
        Assert.Empty(h.Sleeps);
    }

    [Fact]
    public async Task Retries_otp_verify_only_on_too_many_requests()
    {
        using var h = new Harness();
        h.Http.Error(429, "TOO_MANY_REQUESTS", headers: ("Retry-After", "1")).Json(201, Fixtures.OtpSent)
            .Error(429, "TOO_MANY_REQUESTS").Json(200, Fixtures.Verified);
        await h.Client.Otp.SendAsync("0812345678");
        await h.Client.Otp.VerifyAsync("otp-1", "123456");
        Assert.Equal(4, h.Requests.Count);
        Assert.Equal(h.Requests[0].Header("Idempotency-Key"), h.Requests[1].Header("Idempotency-Key"));
    }

    [Fact]
    public async Task Retries_an_otp_send_with_the_same_idempotency_key_and_body()
    {
        using var h = new Harness(o => o.MaxRetries = 4);
        h.Http
            .NetworkError()
            .Error(503, "HTTP_ERROR")
            .Error(429, "TOO_MANY_REQUESTS", headers: ("Retry-After", "2"))
            .Error(409, "IDEMPOTENCY_REQUEST_IN_PROGRESS", """{"retry_after_seconds":5}""", ("Retry-After", "5"))
            .Json(201, Fixtures.OtpSentWithCode, ("Idempotent-Replayed", "true"));

        var otp = await h.Client.Otp.SendAsync(new SendOtpRequest { Phone = "0812345678", WebOtpDomain = "app.exemple.com" });

        Assert.Equal(5, h.Requests.Count);
        var key = h.Requests[0].Header("Idempotency-Key");
        Assert.All(h.Requests, r => Assert.Equal(key, r.Header("Idempotency-Key")));
        Assert.All(h.Requests, r => Assert.Equal(h.Requests[0].Body, r.Body));
        Assert.Equal(new[] { 0.5, 1.0, 2.0, 5.0 }, h.Sleeps.Select(s => s.TotalSeconds));
        Assert.True(otp.Replayed);
        Assert.Equal(key, otp.IdempotencyKey);
    }

    [Fact]
    public async Task Retries_an_sms_send_still_in_progress_after_retry_after()
    {
        using var h = new Harness();
        h.Http
            .Error(409, "IDEMPOTENCY_REQUEST_IN_PROGRESS", """{"retry_after_seconds":5}""", ("Retry-After", "5"))
            .Json(202, Fixtures.Messages(Fixtures.Message()), ("Idempotent-Replayed", "true"));
        var batch = await h.Client.Sms.SendManyAsync(new SendManySmsRequest { Recipients = new[] { "0812345678" }, Body = "x", IdempotencyKey = "k-1" });
        Assert.True(batch.Replayed);
        Assert.Equal(TimeSpan.FromSeconds(5), Assert.Single(h.Sleeps));
        Assert.All(h.Requests, r => Assert.Equal("k-1", r.Header("Idempotency-Key")));
    }

    [Fact]
    public async Task Gives_up_on_a_request_in_progress_after_max_retries_or_a_long_retry_after()
    {
        using var h = new Harness(o => o.MaxRetries = 1);
        h.Http.Repeat(2, m => m.Error(409, "IDEMPOTENCY_REQUEST_IN_PROGRESS", """{"retry_after_seconds":5}""", ("Retry-After", "5"), ("X-Request-Id", "req-busy")));
        var error = await Assert.ThrowsAsync<IdempotencyRequestInProgressException>(() => h.Client.Sms.SendAsync("0812345678", "x"));
        Assert.Equal(TimeSpan.FromSeconds(5), error.RetryAfter);
        Assert.Equal("req-busy", error.RequestId);
        Assert.Equal(409, error.StatusCode);
        Assert.Equal(2, h.Requests.Count);

        using var patient = new Harness(o => o.MaxRetryWait = TimeSpan.FromSeconds(3));
        patient.Http.Error(409, "IDEMPOTENCY_REQUEST_IN_PROGRESS", """{"retry_after_seconds":5}""");
        var tooLong = await Assert.ThrowsAsync<IdempotencyRequestInProgressException>(() => patient.Client.Otp.SendAsync("0812345678"));
        Assert.Equal(TimeSpan.FromSeconds(5), tooLong.RetryAfter);
        Assert.Single(patient.Requests);
        Assert.Empty(patient.Sleeps);
    }

    [Fact]
    public async Task Never_retries_otp_verify_after_a_network_error()
    {
        using var h = new Harness();
        h.Http.NetworkError();
        var error = await Assert.ThrowsAsync<ApiConnectionException>(() => Otp(h, "verify"));
        Assert.Equal("CONNECTION_ERROR", error.Code);
        Assert.Contains("may have been processed", error.Message, StringComparison.Ordinal);
        Assert.Single(h.Requests);
    }

    [Theory]
    [InlineData(500, "HTTP_ERROR")]
    [InlineData(409, "IDEMPOTENCY_REQUEST_IN_PROGRESS")]
    public async Task Never_retries_otp_verify_on_5xx_or_conflicts(int status, string code)
    {
        using var h = new Harness();
        h.Http.Error(status, code, """{"retry_after_seconds":1}""", ("Retry-After", "1"));
        await Assert.ThrowsAnyAsync<ApiException>(() => Otp(h, "verify"));
        Assert.Single(h.Requests);
    }

    [Theory]
    [InlineData("OTP_RESEND_TOO_SOON")]
    [InlineData("OTP_RATE_LIMITED")]
    public async Task Never_retries_business_rate_limits(string code)
    {
        using var h = new Harness();
        h.Http.Error(429, code, """{"retry_after_seconds":1}""", ("Retry-After", "1"));
        await Assert.ThrowsAnyAsync<RateLimitException>(() => h.Client.Otp.SendAsync("0812345678"));
        Assert.Single(h.Requests);
    }

    [Theory]
    [InlineData(400, "HTTP_ERROR")]
    [InlineData(401, "UNAUTHENTICATED")]
    [InlineData(404, "NOT_FOUND")]
    [InlineData(409, "IDEMPOTENCY_KEY_REUSED")]
    [InlineData(409, "INSUFFICIENT_CREDITS")]
    [InlineData(422, "VALIDATION_ERROR")]
    public async Task Never_retries_other_4xx(int status, string code)
    {
        using var h = new Harness();
        h.Http.Error(status, code);
        await Assert.ThrowsAnyAsync<ApiException>(() => h.Client.Sms.SendAsync("0812345678", "x"));
        Assert.Single(h.Requests);
    }

    [Fact]
    public async Task Never_retries_an_unresolved_idempotency_key_on_sms_or_otp_send()
    {
        // Résultat inconnu (un SMS a pu partir) : jamais relancé, même avec la même clé.
        using var sms = new Harness(o => o.MaxRetries = 4);
        sms.Http.Error(409, "IDEMPOTENCY_KEY_UNRESOLVED", headers: ("X-Request-Id", "req-unresolved"));
        var smsError = await Assert.ThrowsAsync<IdempotencyKeyUnresolvedException>(
            () => sms.Client.Sms.SendAsync(new SendSmsRequest { To = "0812345678", Body = "x", IdempotencyKey = "key-1" }));
        Assert.Single(sms.Requests);
        Assert.Empty(sms.Sleeps);
        Assert.Equal(409, smsError.StatusCode);
        Assert.Equal("IDEMPOTENCY_KEY_UNRESOLVED", smsError.Code);
        Assert.Equal("req-unresolved", smsError.RequestId);

        using var otp = new Harness(o => o.MaxRetries = 4);
        otp.Http.Error(409, "IDEMPOTENCY_KEY_UNRESOLVED");
        await Assert.ThrowsAsync<IdempotencyKeyUnresolvedException>(() => otp.Client.Otp.SendAsync("0812345678"));
        Assert.Single(otp.Requests);
        Assert.Empty(otp.Sleeps);
    }

    [Fact]
    public async Task Times_out_each_attempt_and_retries_safe_requests()
    {
        using var h = new Harness(o => o.Timeout = TimeSpan.FromMilliseconds(50));
        h.Http.Hang().Json(200, Fixtures.Balance);
        Assert.Equal(5, (await h.Client.Balance.GetAsync()).Available);
        Assert.Equal(2, h.Requests.Count);
    }

    [Fact]
    public async Task Raises_a_timeout_after_the_last_attempt()
    {
        using var h = new Harness(o =>
        {
            o.Timeout = TimeSpan.FromMilliseconds(50);
            o.MaxRetries = 1;
        });
        h.Http.Hang().Hang();
        var error = await Assert.ThrowsAsync<ApiTimeoutException>(() => h.Client.Balance.GetAsync());
        Assert.Equal("TIMEOUT", error.Code);
        Assert.Null(error.StatusCode);
        Assert.IsAssignableFrom<ApiConnectionException>(error);
        Assert.Equal(2, h.Requests.Count);
    }

    [Fact]
    public async Task An_otp_verify_timeout_is_not_retried_but_an_otp_send_timeout_is()
    {
        using var h = new Harness(o => o.Timeout = TimeSpan.FromMilliseconds(50));
        h.Http.Hang().Hang().Json(201, Fixtures.OtpSent);
        await Assert.ThrowsAsync<ApiTimeoutException>(() => h.Client.Otp.VerifyAsync("otp-1", "123456"));
        Assert.Single(h.Requests);

        await h.Client.Otp.SendAsync("0812345678");
        Assert.Equal(3, h.Requests.Count);
        Assert.Equal(h.Requests[1].Header("Idempotency-Key"), h.Requests[2].Header("Idempotency-Key"));
    }

    [Fact]
    public async Task Cancellation_is_not_a_timeout_and_is_never_retried()
    {
        using var h = new Harness();
        h.Http.Hang().Json(200, Fixtures.Balance);
        using var cancellation = new CancellationTokenSource(TimeSpan.FromMilliseconds(50));
        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => h.Client.Balance.GetAsync(cancellation.Token));
        Assert.Single(h.Requests);
    }

    [Fact]
    public async Task An_already_cancelled_token_sends_nothing()
    {
        using var h = new Harness();
        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => h.Client.Sms.SendAsync("0812345678", "x", new CancellationToken(true)));
        Assert.Empty(h.Requests);
    }

    [Fact]
    public async Task Cancellation_interrupts_the_wait_between_retries()
    {
        var mock = new MockHttp().Error(503, "HTTP_ERROR").Json(200, Fixtures.Balance);
        using var http = new System.Net.Http.HttpClient(mock);
        using var client = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_x", BaseUrl = "https://api.test", MaxRetryWait = TimeSpan.FromMinutes(1) }, http);
        client.Transport.Jitter = () => 1.0;
        using var cancellation = new CancellationTokenSource(TimeSpan.FromMilliseconds(100));
        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => client.Balance.GetAsync(cancellation.Token));
        Assert.Single(mock.Requests);
    }

    [Theory]
    [InlineData("FEATURE_DISABLED")]
    [InlineData("OTP_SERVICE_DISABLED")]
    public async Task Never_retries_a_disabled_feature_without_retry_after(string code)
    {
        // Pas de date de retour : aucun backoff aveugle, même pour une requête sûre.
        using var h = new Harness(o => o.MaxRetries = 4);
        h.Http.Error(503, code, """{"feature":"api","blocked_by":"api","message":null,"until":null}""").Error(503, code);
        await Assert.ThrowsAsync<FeatureDisabledException>(() => h.Client.Balance.GetAsync());
        await Assert.ThrowsAsync<FeatureDisabledException>(() => h.Client.Sms.SendAsync("0812345678", "x"));
        Assert.Equal(2, h.Requests.Count);
        Assert.Empty(h.Sleeps);
    }

    [Theory]
    [InlineData("balance")]
    [InlineData("sms")]
    [InlineData("otp-send")]
    [InlineData("otp-verify")]
    public async Task Retries_a_disabled_feature_after_retry_after_in_every_mode(string operation)
    {
        using var h = new Harness(o => o.MaxRetryWait = TimeSpan.FromSeconds(10));
        var (status, body) = operation switch
        {
            "balance" => (200, Fixtures.Balance),
            "sms" => (202, Fixtures.Messages(Fixtures.Message())),
            "otp-send" => (201, Fixtures.OtpSent),
            _ => (200, Fixtures.Verified),
        };
        h.Http.Error(503, "FEATURE_DISABLED", """{"feature":"api","blocked_by":"api","message":null,"until":"2026-11-06T10:00:07+00:00"}""", ("Retry-After", "7"))
            .Json(status, body);

        await (operation switch
        {
            "balance" => h.Client.Balance.GetAsync(),
            "sms" => h.Client.Sms.SendAsync("0812345678", "x"),
            "otp-send" => h.Client.Otp.SendAsync("0812345678"),
            _ => (Task)h.Client.Otp.VerifyAsync("otp-1", "123456"),
        });

        Assert.Equal(2, h.Requests.Count);
        Assert.Equal(TimeSpan.FromSeconds(7), Assert.Single(h.Sleeps));
        Assert.Equal(h.Requests[0].Header("Idempotency-Key"), h.Requests[1].Header("Idempotency-Key"));
    }

    [Fact]
    public async Task Gives_up_on_a_disabled_feature_when_retry_after_exceeds_max_retry_wait_or_retries_run_out()
    {
        using var h = new Harness(o => o.MaxRetryWait = TimeSpan.FromSeconds(10));
        h.Http.Error(503, "FEATURE_DISABLED", headers: ("Retry-After", "11"));
        var error = await Assert.ThrowsAsync<FeatureDisabledException>(() => h.Client.Otp.VerifyAsync("otp-1", "123456"));
        Assert.Equal(TimeSpan.FromSeconds(11), error.RetryAfter);
        Assert.Single(h.Requests);
        Assert.Empty(h.Sleeps);

        using var limited = new Harness(o => o.MaxRetries = 1);
        limited.Http.Repeat(2, m => m.Error(503, "OTP_SERVICE_DISABLED", headers: ("Retry-After", "2")));
        await Assert.ThrowsAsync<FeatureDisabledException>(() => limited.Client.Otp.SendAsync("0812345678"));
        Assert.Equal(2, limited.Requests.Count);
        Assert.Equal(TimeSpan.FromSeconds(2), Assert.Single(limited.Sleeps));
    }

    [Fact]
    public async Task Otp_verify_still_never_retries_another_503()
    {
        using var h = new Harness();
        h.Http.Error(503, "HTTP_ERROR", headers: ("Retry-After", "1"));
        await Assert.ThrowsAsync<ServiceUnavailableException>(() => h.Client.Otp.VerifyAsync("otp-1", "123456"));
        Assert.Single(h.Requests);
    }

    private static Task Otp(Harness h, string operation) =>
        operation == "send" ? h.Client.Otp.SendAsync("0812345678") : h.Client.Otp.VerifyAsync("otp-1", "123456");
}

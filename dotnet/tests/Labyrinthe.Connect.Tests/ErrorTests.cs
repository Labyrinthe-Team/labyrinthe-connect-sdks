using System;
using System.Linq;
using System.Threading.Tasks;
using Labyrinthe.Connect.Tests.Support;
using Xunit;

namespace Labyrinthe.Connect.Tests;

public class ErrorTests
{
    [Theory]
    [InlineData(401, "UNAUTHENTICATED", typeof(AuthenticationException))]
    [InlineData(403, "FORBIDDEN", typeof(PermissionException))]
    [InlineData(403, "ACCOUNT_SUSPENDED", typeof(AccountSuspendedException))]
    [InlineData(409, "IDEMPOTENCY_REQUEST_IN_PROGRESS", typeof(IdempotencyRequestInProgressException))]
    [InlineData(404, "NOT_FOUND", typeof(NotFoundException))]
    [InlineData(422, "VALIDATION_ERROR", typeof(ValidationException))]
    [InlineData(422, "RECIPIENT_NOT_SENDABLE", typeof(RecipientNotSendableException))]
    [InlineData(422, "SENDER_ID_NOT_APPROVED", typeof(SenderIdNotApprovedException))]
    [InlineData(422, "INVALID_PHONE", typeof(InvalidPhoneException))]
    [InlineData(409, "INSUFFICIENT_CREDITS", typeof(InsufficientCreditsException))]
    [InlineData(409, "IDEMPOTENCY_KEY_REUSED", typeof(IdempotencyKeyReusedException))]
    [InlineData(409, "IDEMPOTENCY_KEY_UNRESOLVED", typeof(IdempotencyKeyUnresolvedException))]
    [InlineData(429, "TOO_MANY_REQUESTS", typeof(RateLimitException))]
    [InlineData(429, "OTP_RESEND_TOO_SOON", typeof(OtpResendTooSoonException))]
    [InlineData(429, "OTP_RATE_LIMITED", typeof(OtpRateLimitedException))]
    [InlineData(503, "OTP_SERVICE_DISABLED", typeof(ServiceUnavailableException))]
    [InlineData(418, "BRAND_NEW_CODE", typeof(ApiException))]
    [InlineData(401, "BRAND_NEW_CODE", typeof(AuthenticationException))]
    [InlineData(403, "BRAND_NEW_CODE", typeof(PermissionException))]
    [InlineData(404, "BRAND_NEW_CODE", typeof(NotFoundException))]
    [InlineData(429, "BRAND_NEW_CODE", typeof(RateLimitException))]
    [InlineData(500, "BRAND_NEW_CODE", typeof(ServiceUnavailableException))]
    public async Task Maps_each_error_code_then_status(int status, string code, Type expected)
    {
        using var h = new Harness(o => o.MaxRetries = 0);
        h.Http.Error(status, code, headers: ("X-Request-Id", "req-123"));

        var error = await Assert.ThrowsAnyAsync<ApiException>(() => h.Client.Sms.GetAsync("m1"));

        Assert.Equal(expected, error.GetType());
        Assert.Equal(code, error.Code);
        Assert.Equal(status, error.StatusCode);
        Assert.Equal($"Erreur {code}.", error.Message);
        Assert.Equal("req-123", error.RequestId);
        Assert.IsAssignableFrom<LabyrintheException>(error);
    }

    [Fact]
    public async Task Exposes_the_request_id_and_api_version_and_never_retries_a_suspension()
    {
        using var h = new Harness();
        h.Http.Error(403, "ACCOUNT_SUSPENDED", headers: new[] { ("X-Request-Id", "req-s"), ("X-Api-Version", "1") }).Error(404, "NOT_FOUND");
        var suspended = await Assert.ThrowsAsync<AccountSuspendedException>(() => h.Client.Sms.SendAsync("0812345678", "x"));
        Assert.Equal(("req-s", "1"), (suspended.RequestId, suspended.ApiVersion));
        Assert.Single(h.Requests);
        Assert.Contains("[request req-s]", h.Logs.All, StringComparison.Ordinal);

        // Sans en-têtes (ancien backend) : null.
        var missing = await Assert.ThrowsAsync<NotFoundException>(() => h.Client.Sms.GetAsync("m1"));
        Assert.Null(missing.RequestId);
        Assert.Null(missing.ApiVersion);
    }

    [Fact]
    public async Task Exposes_field_errors()
    {
        using var h = new Harness();
        h.Http.Error(422, "VALIDATION_ERROR", """{"body":["Le champ body est obligatoire."],"recipient":"Format invalide."}""");
        var error = await Assert.ThrowsAsync<ValidationException>(() => h.Client.Sms.SendAsync("0812345678", "x"));
        Assert.Equal(new[] { "Le champ body est obligatoire." }, error.FieldErrors["body"]);
        Assert.Equal(new[] { "Format invalide." }, error.FieldErrors["recipient"]);
    }

    [Fact]
    public async Task Exposes_every_rejected_recipient()
    {
        using var h = new Harness();
        h.Http.Error(422, "RECIPIENT_NOT_SENDABLE", """
            {"reason":"blacklisted","rejected":[{"recipient":"243812345678","reason":"blacklisted"},{"recipient":"243899999999","reason":"opted_out"},{"bad":1}]}
            """);
        var error = await Assert.ThrowsAsync<RecipientNotSendableException>(() =>
            h.Client.Sms.SendManyAsync(new SendManySmsRequest { Recipients = new[] { "0812345678", "0899999999" }, Body = "x" }));
        Assert.Equal(RejectionReason.Blacklisted, error.Reason);
        Assert.Equal(
            new[] { ("243812345678", RejectionReason.Blacklisted), ("243899999999", RejectionReason.OptedOut) },
            error.Rejected.Select(r => (r.Recipient, r.Reason)));
    }

    [Fact]
    public async Task Exposes_the_credits_of_an_insufficient_balance()
    {
        using var h = new Harness();
        h.Http.Error(409, "INSUFFICIENT_CREDITS", """{"available":3,"required":"10"}""");
        var error = await Assert.ThrowsAsync<InsufficientCreditsException>(() => h.Client.Sms.SendAsync("0812345678", "x"));
        Assert.Equal(3, error.Available);
        Assert.Equal(10, error.Required);
        Assert.Empty(h.Sleeps);
    }

    [Fact]
    public async Task Reads_retry_after_from_the_header_then_the_details()
    {
        using var h = new Harness(o => o.MaxRetries = 0);
        h.Http.Error(429, "TOO_MANY_REQUESTS", """{"retry_after_seconds":30}""", ("Retry-After", "12")).Error(429, "TOO_MANY_REQUESTS", """{"retry_after_seconds":30}""");
        Assert.Equal(TimeSpan.FromSeconds(12), (await Assert.ThrowsAsync<RateLimitException>(() => h.Client.Balance.GetAsync())).RetryAfter);
        Assert.Equal(TimeSpan.FromSeconds(30), (await Assert.ThrowsAsync<RateLimitException>(() => h.Client.Balance.GetAsync())).RetryAfter);
    }

    [Theory]
    [InlineData("")]
    [InlineData("<html>Bad gateway</html>")]
    [InlineData("""{"error":"nope"}""")]
    [InlineData("""{"error":{"code":42,"message":null,"details":[]}}""")]
    public async Task Survives_unusual_error_bodies(string body)
    {
        using var h = new Harness(o => o.MaxRetries = 0);
        h.Http.Json(502, body).Json(429, body).Json(400, body);
        var server = await Assert.ThrowsAsync<ServiceUnavailableException>(() => h.Client.Balance.GetAsync());
        Assert.Equal("HTTP_ERROR", server.Code);
        Assert.Equal("Labyrinthe Connect API returned HTTP 502.", server.Message);
        Assert.Empty(server.Details);
        Assert.Equal("TOO_MANY_REQUESTS", (await Assert.ThrowsAsync<RateLimitException>(() => h.Client.Balance.GetAsync())).Code);
        Assert.Equal(typeof(ApiException), (await Assert.ThrowsAnyAsync<ApiException>(() => h.Client.Balance.GetAsync())).GetType());
    }

    [Fact]
    public async Task A_redirect_is_not_followed()
    {
        using var h = new Harness();
        h.Http.Json(302, "", ("Location", "https://elsewhere.example/steal"));
        var error = await Assert.ThrowsAsync<ApiException>(() => h.Client.Balance.GetAsync());
        Assert.Equal(302, error.StatusCode);
        Assert.Single(h.Requests);
    }
}

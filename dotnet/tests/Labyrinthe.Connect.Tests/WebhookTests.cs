using System;
using System.Text;
using System.Text.Json;
using Xunit;

namespace Labyrinthe.Connect.Tests;

public class WebhookTests
{
    private const string Secret = "whsec_test_secret";

    public static string Envelope(string eventName, string data, long? timestamp = null, string id = "1f0c7e52-8d3a-4b6e-9c2f-5a7d1e3b9f40") =>
        $$"""{"id":"{{id}}","event":"{{eventName}}","data":{{data}},"timestamp":{{timestamp ?? DateTimeOffset.UtcNow.ToUnixTimeSeconds()}}}""";

    [Fact]
    public void Parses_a_message_status_changed_event()
    {
        var body = Envelope("message.status_changed", """{"message_id":"m1","recipient":"243812345678","status":"delivered","provider_message_id":"DD-88231","campaign_id":null}""");

        var evt = Webhook.ConstructEvent(body, Webhook.Sign(body, Secret), Secret);

        var changed = Assert.IsType<MessageStatusChanged>(evt);
        Assert.Equal("1f0c7e52-8d3a-4b6e-9c2f-5a7d1e3b9f40", changed.Id);
        Assert.Equal("message.status_changed", changed.Type);
        Assert.Equal(WebhookEventType.MessageStatusChanged, changed.EventType);
        Assert.Equal("m1", changed.Data.MessageId);
        Assert.Equal(MessageStatus.Delivered, changed.Data.Status);
        Assert.Equal("DD-88231", changed.Data.ProviderMessageId);
        Assert.Null(changed.Data.CampaignId);
        Assert.Equal(JsonValueKind.Object, changed.Raw.ValueKind);
        Assert.Equal(TimeSpan.Zero, changed.Timestamp.Offset);
        Assert.DoesNotContain("243812345678", changed.ToString(), StringComparison.Ordinal);
    }

    [Fact]
    public void Parses_campaign_balance_and_ping_events()
    {
        var campaign = Envelope("campaign.completed", """{"campaign_id":"c1","name":"Promo","recipients_count":1200,"messages_by_status":{"delivered":1150,"failed":"30"},"credits_charged":1200,"completed_at":"2026-11-06T10:15:00+00:00"}""");
        var completed = Assert.IsType<CampaignCompleted>(Webhook.ConstructEvent(campaign, Webhook.Sign(campaign, Secret), Secret));
        Assert.Equal(1150, completed.Data.MessagesByStatus["delivered"]);
        Assert.Equal(30, completed.Data.MessagesByStatus["failed"]);
        Assert.Equal(new DateTimeOffset(2026, 11, 6, 10, 15, 0, TimeSpan.Zero), completed.Data.CompletedAt);

        var low = Envelope("balance.low", """{"balance":950,"threshold":1000}""");
        var balance = Assert.IsType<BalanceLow>(Webhook.ConstructEvent(low, Webhook.Sign(low, Secret), Secret));
        Assert.Equal(new BalanceLowData { Balance = 950, Threshold = 1000 }, balance.Data);

        var ping = Envelope("test.ping", "[]");
        var testPing = Assert.IsType<TestPing>(Webhook.ConstructEvent(ping, Webhook.Sign(ping, Secret), Secret));
        Assert.Equal(JsonValueKind.Object, testPing.Data.ValueKind);
    }

    [Fact]
    public void Returns_an_unknown_event_for_a_future_type()
    {
        var body = Envelope("otp.verified", """{"otp_id":"o1","phone":"243812345678"}""");
        var unknown = Assert.IsType<UnknownEvent>(Webhook.ConstructEvent(body, Webhook.Sign(body, Secret), Secret));
        Assert.Equal("otp.verified", unknown.Type);
        Assert.Equal(WebhookEventType.Unknown, unknown.EventType);
        Assert.Equal("o1", unknown.Data.GetProperty("otp_id").GetString());
        Assert.DoesNotContain("243812345678", unknown.ToString(), StringComparison.Ordinal);
    }

    [Fact]
    public void Accepts_bytes_and_an_upper_case_signature()
    {
        var bytes = Encoding.UTF8.GetBytes(Envelope("test.ping", "{}"));
        var header = "SHA256=" + Webhook.Sign(bytes, Secret)["sha256=".Length..].ToUpperInvariant();
        Assert.IsType<TestPing>(Webhook.ConstructEvent(bytes, " " + header + " ", Secret));
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("sha256=")]
    [InlineData("deadbeef")]
    [InlineData("sha256=zz00000000000000000000000000000000000000000000000000000000000000")]
    [InlineData("sha256=0000000000000000000000000000000000000000000000000000000000000000")]
    public void Rejects_a_missing_or_wrong_signature(string? header)
    {
        var body = Envelope("test.ping", "{}");
        var error = Assert.Throws<SignatureVerificationException>(() => Webhook.ConstructEvent(body, header, Secret));
        Assert.Equal("SIGNATURE_VERIFICATION_FAILED", error.Code);
    }

    [Fact]
    public void Rejects_a_body_signed_with_another_secret_or_modified()
    {
        var body = Envelope("test.ping", "{}");
        Assert.Throws<SignatureVerificationException>(() => Webhook.ConstructEvent(body, Webhook.Sign(body, "other"), Secret));
        Assert.Throws<SignatureVerificationException>(() => Webhook.ConstructEvent(body + " ", Webhook.Sign(body, Secret), Secret));
    }

    [Fact]
    public void Rejects_an_expired_or_future_timestamp()
    {
        var old = Envelope("test.ping", "{}", DateTimeOffset.UtcNow.AddMinutes(-6).ToUnixTimeSeconds());
        var error = Assert.Throws<SignatureVerificationException>(() => Webhook.ConstructEvent(old, Webhook.Sign(old, Secret), Secret));
        Assert.Contains("tolerance", error.Message, StringComparison.Ordinal);

        var future = Envelope("test.ping", "{}", DateTimeOffset.UtcNow.AddMinutes(6).ToUnixTimeSeconds());
        Assert.Throws<SignatureVerificationException>(() => Webhook.ConstructEvent(future, Webhook.Sign(future, Secret), Secret));

        Assert.IsType<TestPing>(Webhook.ConstructEvent(old, Webhook.Sign(old, Secret), Secret, TimeSpan.FromMinutes(10)));
        Assert.IsType<TestPing>(Webhook.ConstructEvent(old, Webhook.Sign(old, Secret), Secret, TimeSpan.Zero));
        Assert.Throws<ArgumentOutOfRangeException>(() => Webhook.ConstructEvent(old, Webhook.Sign(old, Secret), Secret, TimeSpan.FromSeconds(-1)));
    }

    [Fact]
    public void Uses_the_given_clock()
    {
        var body = Encoding.UTF8.GetBytes(Envelope("test.ping", "{}", 1793952000));
        var at = DateTimeOffset.FromUnixTimeSeconds(1793952000 + 60);
        Assert.Equal(DateTimeOffset.FromUnixTimeSeconds(1793952000), Webhook.ConstructEvent(body, Webhook.Sign(body, Secret), Secret, null, at).Timestamp);
    }

    [Theory]
    [InlineData("not json")]
    [InlineData("[1,2]")]
    [InlineData("""{"event":"test.ping","data":{},"timestamp":1}""")]
    [InlineData("""{"id":"x","event":"test.ping","data":{},"timestamp":"soon"}""")]
    [InlineData("""{"id":"x","event":"message.status_changed","data":{"status":"sent"},"timestamp":TS}""")]
    public void Rejects_a_signed_body_that_is_not_an_envelope(string template)
    {
        var body = template.Replace("TS", DateTimeOffset.UtcNow.ToUnixTimeSeconds().ToString(System.Globalization.CultureInfo.InvariantCulture), StringComparison.Ordinal);
        var error = Assert.Throws<InvalidWebhookPayloadException>(() => Webhook.ConstructEvent(body, Webhook.Sign(body, Secret), Secret, TimeSpan.Zero));
        Assert.Equal("INVALID_WEBHOOK_PAYLOAD", error.Code);
    }

    [Fact]
    public void Requires_a_secret()
    {
        Assert.Throws<ArgumentException>(() => Webhook.Sign("{}", ""));
        Assert.Throws<ArgumentException>(() => Webhook.ConstructEvent("{}", "sha256=00", ""));
    }

    [Fact]
    public void Signature_matches_a_known_vector()
    {
        // HMAC-SHA256("key", "The quick brown fox jumps over the lazy dog")
        Assert.Equal("sha256=f7bc83f430538424b13298e6aa6fb143ef4d59a14946175997479dbc2d1a3cd8", Webhook.Sign("The quick brown fox jumps over the lazy dog", "key"));
        Assert.True(Webhook.VerifySignature(Encoding.UTF8.GetBytes("The quick brown fox jumps over the lazy dog"), "sha256=f7bc83f430538424b13298e6aa6fb143ef4d59a14946175997479dbc2d1a3cd8", "key"));
    }
}

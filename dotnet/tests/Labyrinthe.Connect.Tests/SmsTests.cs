using System;
using System.Linq;
using System.Net.Http;
using System.Text.Json;
using System.Threading.Tasks;
using Labyrinthe.Connect.Tests.Support;
using Xunit;

namespace Labyrinthe.Connect.Tests;

public class SmsTests
{
    [Fact]
    public async Task Sends_one_sms_with_a_normalized_number_and_a_generated_idempotency_key()
    {
        using var h = new Harness();
        h.Http.Json(202, Fixtures.Messages(Fixtures.Message()));

        var message = await h.Client.Sms.SendAsync("+243 81 234 5678", "Hello");

        var request = Assert.Single(h.Requests);
        Assert.Equal(HttpMethod.Post, request.Method);
        Assert.Equal("/api/public/v1/sms", request.Path);
        Assert.Equal("""{"recipient":"+243812345678","body":"Hello"}""", request.Body);
        Assert.StartsWith("application/json", request.Header("Content-Type"), StringComparison.Ordinal);
        Assert.True(Guid.TryParse(request.Header("Idempotency-Key"), out _));

        Assert.Equal(Fixtures.MessageId, message.Id);
        Assert.Equal("243812345678", message.Recipient);
        Assert.Equal(MessageStatus.Queued, message.Status);
        Assert.Equal(SmsEncoding.Gsm7, message.Encoding);
        Assert.Equal(1, message.Segments);
        Assert.Equal(1, message.CreditsCharged);
        Assert.Equal(new DateTimeOffset(2026, 11, 5, 9, 39, 11, TimeSpan.Zero), message.CreatedAt);
        Assert.Null(message.DeliveredAt);
    }

    // Sans "+", l'API lit un numéro de moins de 11 chiffres comme local RDC
    // ("2481234567" partirait en 2432481234567). Le SDK envoie donc toujours le format E.164 avec "+".
    [Fact]
    public async Task Sends_numbers_in_e164_with_plus_so_a_short_foreign_number_keeps_its_country_code()
    {
        using var h = new Harness();
        h.Http.Json(202, Fixtures.Messages(Fixtures.Message(recipient: "2481234567")))
            .Json(202, Fixtures.Messages(Fixtures.Message()))
            .Json(202, Fixtures.Messages(Fixtures.Message(recipient: "2481234567"), Fixtures.Message(id: "m2")))
            .Json(200, Fixtures.Estimate);

        await h.Client.Sms.SendAsync("+2481234567", "Hello");
        await h.Client.Sms.SendAsync("0812345678", "Hello");
        await h.Client.Sms.SendManyAsync(new SendManySmsRequest { Recipients = new[] { "+2481234567", "0812345678" }, Body = "Hello" });
        await h.Client.Sms.EstimateAsync(new EstimateSmsRequest { Body = "Hello", Recipients = new[] { "+2481234567", "0812345678" } });

        Assert.Equal("+2481234567", h.Requests[0].Json.GetProperty("recipient").GetString());
        Assert.Equal("+243812345678", h.Requests[1].Json.GetProperty("recipient").GetString());
        Assert.Equal("""{"recipients":["+2481234567","+243812345678"],"body":"Hello"}""", h.Requests[2].Body);
        Assert.Equal("""{"body":"Hello","recipients":["+2481234567","+243812345678"]}""", h.Requests[3].Body);
    }

    [Fact]
    public async Task Sends_the_sender_reference_and_the_given_idempotency_key()
    {
        using var h = new Harness();
        h.Http.Json(202, Fixtures.Messages(Fixtures.Message(reference: "order-1042")));

        var message = await h.Client.Sms.SendAsync(new SendSmsRequest
        {
            To = "0812345678",
            Body = "Réunion à 9h",
            Sender = "MABOUTIQUE",
            Reference = "order-1042",
            IdempotencyKey = "order-1042-ready",
        });

        var body = h.Requests[0].Json;
        Assert.Equal("+243812345678", body.GetProperty("recipient").GetString());
        Assert.Equal("Réunion à 9h", body.GetProperty("body").GetString());
        Assert.Equal("MABOUTIQUE", body.GetProperty("sender").GetString());
        Assert.Equal("order-1042", body.GetProperty("reference").GetString());
        Assert.Equal("order-1042-ready", h.Requests[0].Header("Idempotency-Key"));
        Assert.Equal("order-1042", message.Reference);
    }

    [Fact]
    public async Task Uses_the_default_sender_and_country_code()
    {
        using var h = new Harness(o =>
        {
            o.DefaultSender = "MYSHOP";
            o.DefaultCountryCode = "33";
        });
        h.Http.Json(202, Fixtures.Messages(Fixtures.Message(recipient: "33612345678")));
        await h.Client.Sms.SendAsync("0612345678", "Bonjour");
        var body = h.Requests[0].Json;
        Assert.Equal("+33612345678", body.GetProperty("recipient").GetString());
        Assert.Equal("MYSHOP", body.GetProperty("sender").GetString());
    }

    [Fact]
    public async Task Sends_many_and_reports_a_replay()
    {
        using var h = new Harness();
        h.Http.Json(202, Fixtures.Messages(Fixtures.Message(), Fixtures.Message(id: "m2", recipient: "243899999999")), ("Idempotent-Replayed", "true"));

        var batch = await h.Client.Sms.SendManyAsync(new SendManySmsRequest
        {
            Recipients = new[] { "0812345678", "+243899999999" },
            Body = "Meeting tomorrow",
            IdempotencyKey = "k-1",
        });

        Assert.Equal("""{"recipients":["+243812345678","+243899999999"],"body":"Meeting tomorrow"}""", h.Requests[0].Body);
        Assert.True(batch.Replayed);
        Assert.Equal("k-1", batch.IdempotencyKey);
        Assert.Equal(new[] { Fixtures.MessageId, "m2" }, batch.Messages.Select(m => m.Id));
    }

    [Fact]
    public async Task A_fresh_send_is_not_a_replay_and_exposes_the_generated_key()
    {
        using var h = new Harness();
        h.Http.Json(202, Fixtures.Messages(Fixtures.Message()));
        var batch = await h.Client.Sms.SendManyAsync(new SendManySmsRequest { Recipients = new[] { "0812345678" }, Body = "Hi" });
        Assert.False(batch.Replayed);
        Assert.Equal(h.Requests[0].Header("Idempotency-Key"), batch.IdempotencyKey);
    }

    [Fact]
    public async Task Refuses_invalid_numbers_locally_by_index_only()
    {
        using var h = new Harness();
        var error = await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Sms.SendManyAsync(new SendManySmsRequest
        {
            Recipients = new[] { "0812345678", "12", "abc" },
            Body = "Hi",
        }));
        Assert.Contains("index 1, 2", error.Message, StringComparison.Ordinal);
        Assert.Contains("Nothing was sent", error.Message, StringComparison.Ordinal);
        Assert.DoesNotContain("abc", error.Message, StringComparison.Ordinal);
        Assert.Empty(h.Requests);

        var single = await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Sms.SendAsync("12", "Hi"));
        Assert.DoesNotContain("12\"", single.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task Validates_the_input_before_any_call()
    {
        using var h = new Harness();
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Sms.SendAsync("0812345678", ""));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Sms.SendAsync("0812345678", new string('a', 1601)));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Sms.SendAsync(new SendSmsRequest { To = "0812345678", Body = "x", Sender = "TWELVECHARSX" }));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Sms.SendAsync(new SendSmsRequest { To = "0812345678", Body = "x", Sender = "" }));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Sms.SendAsync(new SendSmsRequest { To = "0812345678", Body = "x", Reference = new string('r', 65) }));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Sms.SendAsync(new SendSmsRequest { To = "0812345678", Body = "x", IdempotencyKey = "" }));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Sms.SendAsync(new SendSmsRequest { To = "0812345678", Body = "x", IdempotencyKey = new string('k', 256) }));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Sms.SendManyAsync(new SendManySmsRequest { Recipients = Array.Empty<string>(), Body = "x" }));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Sms.SendManyAsync(new SendManySmsRequest { Recipients = Enumerable.Repeat("0812345678", 1001).ToArray(), Body = "x" }));
        await Assert.ThrowsAsync<ArgumentNullException>(() => h.Client.Sms.SendAsync(null!));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Sms.GetAsync(" "));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Sms.EstimateAsync(new EstimateSmsRequest { Body = "x" }));
        Assert.Empty(h.Requests);
    }

    [Fact]
    public async Task Estimates_with_normalized_numbers_and_keeps_invalid_ones()
    {
        using var h = new Harness();
        h.Http.Json(200, Fixtures.Estimate);

        var estimate = await h.Client.Sms.EstimateAsync(new EstimateSmsRequest { Body = "Hello", Recipients = new[] { "0812345678", "oops" } });

        Assert.Equal("""{"body":"Hello","recipients":["+243812345678","oops"]}""", h.Requests[0].Body);
        Assert.Equal(SmsEncoding.Gsm7, estimate.Encoding);
        Assert.Equal(1, estimate.TotalCredits);
        Assert.Equal(9, estimate.BalanceAfter);
        Assert.True(estimate.IsAffordable);
        Assert.False(new Estimate { BalanceAfter = -1 }.IsAffordable);
        Assert.False(new Estimate { BalanceAfter = null }.IsAffordable);
    }

    [Fact]
    public async Task Gets_a_message_by_escaped_id()
    {
        using var h = new Harness();
        h.Http.Json(200, Fixtures.Data(Fixtures.Message(status: "delivered")));
        var message = await h.Client.Sms.GetAsync("a/b c");
        Assert.Equal("/api/public/v1/sms/a%2Fb%20c", h.Requests[0].Uri.AbsolutePath);
        Assert.True(message.Status.IsFinal());
        Assert.False(message.Status.IsFailed());
    }

    [Fact]
    public async Task Tolerates_a_null_encoding()
    {
        using var h = new Harness();
        h.Http.Json(200, Fixtures.Data(Fixtures.Message().Replace("\"gsm7\"", "null", StringComparison.Ordinal)));
        Assert.Null((await h.Client.Sms.GetAsync("m1")).Encoding);
    }

    [Theory]
    [InlineData(MessageStatus.Queued, false, false, true)]
    [InlineData(MessageStatus.Sent, false, false, true)]
    [InlineData(MessageStatus.Delivered, true, false, false)]
    [InlineData(MessageStatus.Undeliverable, true, true, false)]
    [InlineData(MessageStatus.Unknown, false, false, false)]
    public void Classifies_statuses(MessageStatus status, bool final, bool failed, bool pending)
    {
        Assert.Equal(final, status.IsFinal());
        Assert.Equal(failed, status.IsFailed());
        Assert.Equal(pending, status.IsPending());
    }

    [Fact]
    public void Message_to_string_masks_the_recipient()
    {
        var message = new Message { Id = "m1", Recipient = "243812345678" };
        Assert.Contains("24381*****78", message.ToString(), StringComparison.Ordinal);
        Assert.DoesNotContain("243812345678", message.ToString(), StringComparison.Ordinal);
        Assert.DoesNotContain("243812345678", new RejectedRecipient { Recipient = "243812345678" }.ToString(), StringComparison.Ordinal);
    }

    [Fact]
    public async Task Request_bodies_are_valid_json()
    {
        using var h = new Harness();
        h.Http.Json(202, Fixtures.Messages(Fixtures.Message()));
        await h.Client.Sms.SendAsync("0812345678", "Quote \" and \\ and 🎉");
        Assert.Equal("Quote \" and \\ and 🎉", h.Requests[0].Json.GetProperty("body").GetString());
        Assert.Equal(JsonValueKind.Object, h.Requests[0].Json.ValueKind);
    }
}

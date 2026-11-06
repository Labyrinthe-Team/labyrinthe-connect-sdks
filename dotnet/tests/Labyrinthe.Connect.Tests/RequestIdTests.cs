using System;
using System.Linq;
using System.Threading.Tasks;
using Labyrinthe.Connect.Tests.Support;
using Xunit;

namespace Labyrinthe.Connect.Tests;

/// <summary>Nouveautés 1.2.0 de l'envoi : X-Request-Id (requête et réponse) et strip_accents.</summary>
public class RequestIdTests
{
    [Fact]
    public async Task Results_expose_the_request_id_of_their_response()
    {
        using var h = new Harness();
        h.Http
            .Json(202, Fixtures.Messages(Fixtures.Message()), ("X-Request-Id", "r-send"))
            .Json(200, Fixtures.Estimate, ("X-Request-Id", "r-estimate"))
            .Json(200, Fixtures.Page(1, 1, 1, 15, Fixtures.Message()), ("X-Request-Id", "r-list"))
            .Json(200, Fixtures.Balance, ("X-Request-Id", "r-balance"))
            .Json(200, Fixtures.Balance);

        Assert.Equal("r-send", (await h.Client.Sms.SendManyAsync(new SendManySmsRequest { Recipients = new[] { "0812345678" }, Body = "x" })).RequestId);
        Assert.Equal("r-estimate", (await h.Client.Sms.EstimateAsync(new EstimateSmsRequest { Body = "x", Recipients = new[] { "0812345678" } })).RequestId);
        Assert.Equal("r-list", (await h.Client.Sms.ListAsync()).RequestId);
        Assert.Equal("r-balance", (await h.Client.Balance.GetAsync()).RequestId);
        Assert.Null((await h.Client.Balance.GetAsync()).RequestId);
        Assert.Contains("[request r-balance]", h.Logs.All, StringComparison.Ordinal);
    }

    [Fact]
    public async Task With_request_id_sends_it_on_every_attempt_without_changing_the_parent()
    {
        using var h = new Harness();
        h.Http.NetworkError().Json(202, Fixtures.Messages(Fixtures.Message()), ("X-Request-Id", "order-1042")).Json(200, Fixtures.Balance);

        var scoped = h.Client.WithRequestId("order-1042");
        Assert.Equal("order-1042", scoped.RequestId);
        Assert.Null(h.Client.RequestId);
        Assert.Equal(h.Client.BaseUrl, scoped.BaseUrl);
        Assert.True(scoped.IsTestMode);

        var message = await scoped.Sms.SendAsync("0812345678", "x");
        Assert.Equal(2, h.Requests.Count);
        Assert.All(h.Requests, r => Assert.Equal("order-1042", r.Header("X-Request-Id")));
        Assert.Equal(h.Requests[0].Header("Idempotency-Key"), h.Requests[1].Header("Idempotency-Key"));
        Assert.Equal(1, message.Segments);

        // La vue ne ferme pas les connexions partagées.
        scoped.Dispose();
        await h.Client.Balance.GetAsync();
        Assert.Null(h.Requests[2].Header("X-Request-Id"));
        Assert.DoesNotContain("lsk_test_unit_secret_123", scoped.ToString(), StringComparison.Ordinal);
    }

    [Fact]
    public async Task Strip_accents_is_sent_only_when_asked()
    {
        using var h = new Harness();
        h.Http.Repeat(3, m => m.Json(202, Fixtures.Messages(Fixtures.Message()))).Repeat(2, m => m.Json(200, Fixtures.Estimate));

        await h.Client.Sms.SendAsync(new SendSmsRequest { To = "0812345678", Body = "Réunion à 9h", StripAccents = true });
        await h.Client.Sms.SendManyAsync(new SendManySmsRequest { Recipients = new[] { "0812345678" }, Body = "Réunion", StripAccents = true });
        await h.Client.Sms.SendAsync("0812345678", "Réunion");
        await h.Client.Sms.EstimateAsync(new EstimateSmsRequest { Body = "Réunion", Recipients = new[] { "0812345678" }, StripAccents = true });
        await h.Client.Sms.EstimateAsync(new EstimateSmsRequest { Body = "Réunion", Recipients = new[] { "0812345678" } });

        var flags = h.Requests.Select(r => r.Json.TryGetProperty("strip_accents", out var v) ? v.GetBoolean() : (bool?)null).ToArray();
        Assert.Equal(new bool?[] { true, true, null, true, null }, flags);
    }
}

using System;
using System.Net.Http;
using System.Reflection;
using System.Threading.Tasks;
using Labyrinthe.Connect.Tests.Support;
using Xunit;

namespace Labyrinthe.Connect.Tests;

[Collection(EnvironmentGroup.Name)]
public class ClientTests
{
    [Fact]
    public void Detects_the_mode_from_the_key_prefix()
    {
        using var test = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_abc" });
        using var live = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_live_abc" });
        Assert.True(test.IsTestMode);
        Assert.False(test.IsLiveMode);
        Assert.True(live.IsLiveMode);
        Assert.False(live.IsTestMode);
    }

    [Fact]
    public void Requires_an_api_key()
    {
        using var _ = new EnvironmentScope(("LABYRINTHE_API_KEY", null));
        var error = Assert.Throws<ArgumentException>(() => new LabyrintheClient(new LabyrintheClientOptions()));
        Assert.Contains("LABYRINTHE_API_KEY", error.Message, StringComparison.Ordinal);
        Assert.Throws<ArgumentException>(() => new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "   " }));
    }

    [Fact]
    public void Reads_the_key_and_base_url_from_the_environment()
    {
        using var _ = new EnvironmentScope(("LABYRINTHE_API_KEY", "lsk_test_from_env"), ("LABYRINTHE_BASE_URL", "http://localhost:8000"));
        using var client = new LabyrintheClient();
        Assert.True(client.IsTestMode);
        Assert.Equal("http://localhost:8000/api/public/v1", client.BaseUrl);
    }

    [Fact]
    public void Defaults_to_production()
    {
        using var _ = new EnvironmentScope(("LABYRINTHE_BASE_URL", null));
        using var client = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_x" });
        Assert.Equal("https://api.labyrinthe-rdc.com/api/public/v1", client.BaseUrl);
    }

    [Theory]
    [InlineData("http://localhost:8000", "http://localhost:8000/api/public/v1")]
    [InlineData("http://localhost:8000/", "http://localhost:8000/api/public/v1")]
    [InlineData("https://api.example.com/api/public/v1/", "https://api.example.com/api/public/v1")]
    public void Normalizes_the_base_url(string input, string expected)
    {
        using var client = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_x", BaseUrl = input });
        Assert.Equal(expected, client.BaseUrl);
    }

    [Theory]
    [InlineData("ftp://example.com")]
    [InlineData("not a url")]
    [InlineData("/relative")]
    public void Rejects_an_invalid_base_url(string url)
    {
        Assert.Throws<ArgumentException>(() => new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_x", BaseUrl = url }));
    }

    [Fact]
    public void Validates_the_options()
    {
        Assert.Throws<ArgumentOutOfRangeException>(() => new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_x", Timeout = TimeSpan.Zero }));
        Assert.Throws<ArgumentOutOfRangeException>(() => new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_x", MaxRetries = -1 }));
        Assert.Throws<ArgumentOutOfRangeException>(() => new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_x", MaxRetries = 11 }));
        Assert.Throws<ArgumentOutOfRangeException>(() => new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_x", MaxRetryWait = TimeSpan.FromSeconds(-1) }));
        Assert.Throws<ArgumentException>(() => new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_x", DefaultCountryCode = "+243" }));
        Assert.Throws<ArgumentException>(() => new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_x", DefaultCountryCode = "0243" }));
    }

    [Fact]
    public async Task Sends_the_standard_headers()
    {
        using var h = new Harness();
        h.Http.Json(200, Fixtures.Balance);
        await h.Client.Balance.GetAsync();

        var request = Assert.Single(h.Requests);
        Assert.Equal(HttpMethod.Get, request.Method);
        Assert.Equal("https://api.test/api/public/v1/balance", request.Uri.ToString());
        Assert.Equal("Bearer lsk_test_unit_secret_123", request.Header("Authorization"));
        Assert.Equal("application/json", request.Header("Accept"));
        Assert.Matches(@"^labyrinthe-dotnet/1\.1\.1 \.NET/\d+\.\d+", request.Header("User-Agent"));
        Assert.Null(request.Body);
    }

    [Fact]
    public void Version_matches_the_assembly_version()
    {
        var informational = typeof(LabyrintheClient).Assembly.GetCustomAttribute<AssemblyInformationalVersionAttribute>()!.InformationalVersion;
        Assert.StartsWith(LabyrintheClient.Version, informational, StringComparison.Ordinal);
    }

    [Fact]
    public async Task Does_not_dispose_a_client_it_does_not_own()
    {
        var mock = new MockHttp().Json(200, Fixtures.Balance).Json(200, Fixtures.Balance);
        using var http = new HttpClient(mock);
        using (var client = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_x", BaseUrl = "https://api.test" }, http))
        {
            await client.Balance.GetAsync();
        }

        using var again = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_x", BaseUrl = "https://api.test" }, http);
        Assert.Equal(5, (await again.Balance.GetAsync()).Available);
    }

    [Fact]
    public async Task Reads_the_balance_and_sender_ids()
    {
        using var h = new Harness();
        h.Http.Json(200, Fixtures.Balance).Json(200, Fixtures.SenderIds);

        var balance = await h.Client.Balance.GetAsync();
        Assert.Equal(new Balance { Available = 5, Held = 2 }, balance);

        var senderId = Assert.Single(await h.Client.SenderIds.ListAsync());
        Assert.Equal("MABOUTIQUE", senderId.Value);
        Assert.Equal(SenderIdStatus.Approved, senderId.Status);
        Assert.True(senderId.IsDefault);
        Assert.Equal(new DateTimeOffset(2026, 11, 1, 8, 0, 0, TimeSpan.Zero), senderId.CreatedAt);
        Assert.Equal("/api/public/v1/sender-ids", h.Requests[1].Path);
    }

    [Fact]
    public async Task Tolerates_unknown_enum_values()
    {
        using var h = new Harness();
        h.Http.Json(200, Fixtures.Data(Fixtures.Message(status: "teleported")));
        var message = await h.Client.Sms.GetAsync("m1");
        Assert.Equal(MessageStatus.Unknown, message.Status);
        Assert.Equal("unknown", message.Status.ToApiString());
    }

    [Fact]
    public async Task Rejects_an_unreadable_success_response()
    {
        using var h = new Harness();
        h.Http.Json(200, "<html>").Json(200, "[1]").Json(200, """{"data":{"balance":"lots","held":0}}""");
        var notJson = await Assert.ThrowsAsync<UnexpectedResponseException>(() => h.Client.Balance.GetAsync());
        Assert.Equal("UNEXPECTED_RESPONSE", notJson.Code);
        await Assert.ThrowsAsync<UnexpectedResponseException>(() => h.Client.Balance.GetAsync());
        var field = await Assert.ThrowsAsync<UnexpectedResponseException>(() => h.Client.Balance.GetAsync());
        Assert.Contains("\"balance\"", field.Message, StringComparison.Ordinal);
    }
}

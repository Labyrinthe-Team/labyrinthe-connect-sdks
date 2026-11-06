using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using Labyrinthe.Connect.Tests.Support;
using Xunit;

namespace Labyrinthe.Connect.Tests;

public class PaginationTests
{
    [Fact]
    public async Task Lists_one_page_with_every_filter()
    {
        using var h = new Harness();
        h.Http.Json(200, Fixtures.Page(1, 3, 5, 2, Fixtures.Message(), Fixtures.Message(id: "m2")));

        var page = await h.Client.Sms.ListAsync(new ListSmsOptions
        {
            Status = MessageStatus.Failed,
            Recipient = "0812345678",
            Reference = "order 1042",
            From = DateFilter.Day(2026, 11, 1),
            To = new DateTimeOffset(2026, 11, 30, 10, 0, 0, TimeSpan.FromHours(1)),
            PerPage = 2,
        });

        var query = h.Requests[0].Query;
        Assert.Equal("failed", query["status"]);
        Assert.Equal("+243812345678", query["recipient"]);
        Assert.Contains("recipient=%2B243812345678", h.Requests[0].PathAndQuery, StringComparison.Ordinal);
        Assert.Equal("order 1042", query["reference"]);
        Assert.Equal("2026-11-01", query["from"]);
        Assert.Equal("2026-11-30T09:00:00Z", query["to"]);
        Assert.Equal("2", query["per_page"]);
        Assert.Equal("1", query["page"]);

        Assert.Equal(2, page.Data.Count);
        Assert.Equal(1, page.CurrentPage);
        Assert.Equal(3, page.LastPage);
        Assert.Equal(5, page.Total);
        Assert.True(page.HasMore);
    }

    [Fact]
    public async Task Walks_every_page_lazily()
    {
        using var h = new Harness();
        h.Http
            .Json(200, Fixtures.Page(1, 3, 5, 2, Fixtures.Message(id: "a"), Fixtures.Message(id: "b")))
            .Json(200, Fixtures.Page(2, 3, 5, 2, Fixtures.Message(id: "c"), Fixtures.Message(id: "d")))
            .Json(200, Fixtures.Page(3, 3, 5, 2, Fixtures.Message(id: "e")));

        var ids = new List<string>();
        await foreach (var message in h.Client.Sms.ListAutoPagingAsync(new ListSmsOptions { Reference = "r", PerPage = 2 }))
        {
            ids.Add(message.Id);
            if (ids.Count == 1)
            {
                Assert.Single(h.Requests);
            }
        }

        Assert.Equal(new[] { "a", "b", "c", "d", "e" }, ids);
        Assert.Equal(new[] { "1", "2", "3" }, h.Requests.Select(r => r.Query["page"]));
        Assert.All(h.Requests, r => Assert.Equal("r", r.Query["reference"]));
    }

    [Fact]
    public async Task Next_page_is_null_on_the_last_page()
    {
        using var h = new Harness();
        h.Http.Json(200, Fixtures.Page(2, 2, 3, 2, Fixtures.Message()));
        var page = await h.Client.Sms.ListAsync(new ListSmsOptions { Page = 2 });
        Assert.False(page.HasMore);
        Assert.Null(await page.GetNextPageAsync());
        Assert.Single(h.Requests);
    }

    [Fact]
    public async Task Tolerates_a_missing_meta()
    {
        using var h = new Harness();
        h.Http.Json(200, Fixtures.Messages(Fixtures.Message()));
        var page = await h.Client.Sms.ListAsync();
        Assert.Equal(1, page.Total);
        Assert.False(page.HasMore);
    }

    [Fact]
    public async Task Auto_paging_can_be_cancelled()
    {
        using var h = new Harness();
        h.Http.Json(200, Fixtures.Page(1, 9, 18, 2, Fixtures.Message(id: "a"), Fixtures.Message(id: "b")));
        using var cancellation = new CancellationTokenSource();
        var seen = 0;
        await Assert.ThrowsAnyAsync<OperationCanceledException>(async () =>
        {
            await foreach (var _ in h.Client.Sms.ListAutoPagingAsync(cancellationToken: cancellation.Token))
            {
                seen++;
                await cancellation.CancelAsync();
            }
        });
        Assert.Equal(2, seen);
        Assert.Single(h.Requests);
    }

    [Fact]
    public async Task Validates_the_list_options()
    {
        using var h = new Harness();
        await Assert.ThrowsAsync<ArgumentOutOfRangeException>(() => h.Client.Sms.ListAsync(new ListSmsOptions { Page = 0 }));
        await Assert.ThrowsAsync<ArgumentOutOfRangeException>(() => h.Client.Sms.ListAsync(new ListSmsOptions { PerPage = 101 }));
        await Assert.ThrowsAsync<ArgumentException>(() => h.Client.Sms.ListAsync(new ListSmsOptions { Status = MessageStatus.Unknown }));
        Assert.Empty(h.Requests);
    }

    [Fact]
    public void Date_filters_format_days_and_instants()
    {
        Assert.Equal("2026-11-01", DateFilter.Day(2026, 11, 1).Value);
        Assert.Equal("2026-11-01", ((DateFilter)new DateOnly(2026, 11, 1)).Value);
        Assert.Equal("2026-11-01T07:00:00Z", DateFilter.At(new DateTimeOffset(2026, 11, 1, 8, 0, 0, TimeSpan.FromHours(1))).Value);
        Assert.Equal(DateFilter.Day(2026, 1, 2), DateFilter.Day(2026, 1, 2));
    }
}

using System;
using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;

namespace Labyrinthe.Connect;

/// <summary>One page of a list (<see cref="Resources.SmsResource.ListAsync"/>).</summary>
/// <typeparam name="T">Item type.</typeparam>
public sealed class Page<T>
{
    private readonly Func<int, CancellationToken, Task<Page<T>>> fetchPage;

    internal Page(IReadOnlyList<T> data, int currentPage, int lastPage, int perPage, int total, Func<int, CancellationToken, Task<Page<T>>> fetchPage, string? requestId = null)
    {
        Data = data;
        CurrentPage = currentPage;
        LastPage = lastPage;
        PerPage = perPage;
        Total = total;
        this.fetchPage = fetchPage;
        RequestId = requestId;
    }

    /// <summary>Items of this page only.</summary>
    public IReadOnlyList<T> Data { get; }

    /// <summary>Number of this page, from 1.</summary>
    public int CurrentPage { get; }

    /// <summary>Number of the last page.</summary>
    public int LastPage { get; }

    /// <summary>Items per page.</summary>
    public int PerPage { get; }

    /// <summary>Items of every page.</summary>
    public int Total { get; }

    /// <summary>Identifier of the API request that returned this page (<c>X-Request-Id</c>), for support.</summary>
    public string? RequestId { get; }

    /// <summary>True when a next page exists.</summary>
    public bool HasMore => CurrentPage < LastPage;

    /// <summary>The next page, or <see langword="null"/> on the last one.</summary>
    /// <param name="cancellationToken">Cancels the request.</param>
    public async Task<Page<T>?> GetNextPageAsync(CancellationToken cancellationToken = default) =>
        HasMore ? await fetchPage(CurrentPage + 1, cancellationToken).ConfigureAwait(false) : null;

    /// <inheritdoc/>
    public override string ToString() =>
        $"Page {{ CurrentPage = {CurrentPage}, LastPage = {LastPage}, PerPage = {PerPage}, Total = {Total}, Items = {Data.Count} }}";
}

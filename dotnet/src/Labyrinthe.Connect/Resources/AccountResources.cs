using System.Collections.Generic;
using System.Linq;
using System.Net.Http;
using System.Threading;
using System.Threading.Tasks;
using Labyrinthe.Connect.Internal;

namespace Labyrinthe.Connect.Resources;

/// <summary>Credit balance (<c>client.Balance</c>).</summary>
public sealed class BalanceResource
{
    private readonly Transport transport;

    internal BalanceResource(Transport transport) => this.transport = transport;

    /// <summary>Available and held credits.</summary>
    /// <param name="cancellationToken">Cancels the call.</param>
    public async Task<Balance> GetAsync(CancellationToken cancellationToken = default)
    {
        var response = await transport.SendAsync(HttpMethod.Get, "/balance", RetryMode.Safe, null, null, null, cancellationToken).ConfigureAwait(false);
        return Mapping.ToBalance(Json.Api.Obj(response.Body, "data")) with { RequestId = response.RequestId };
    }

    /// <inheritdoc/>
    public override string ToString() => $"BalanceResource {{ {transport} }}";
}

/// <summary>Approved Sender IDs (<c>client.SenderIds</c>).</summary>
public sealed class SenderIdsResource
{
    private readonly Transport transport;

    internal SenderIdsResource(Transport transport) => this.transport = transport;

    /// <summary>Sender IDs with the <c>approved</c> status, usable as <c>Sender</c>.</summary>
    /// <param name="cancellationToken">Cancels the call.</param>
    public async Task<IReadOnlyList<SenderId>> ListAsync(CancellationToken cancellationToken = default)
    {
        var response = await transport.SendAsync(HttpMethod.Get, "/sender-ids", RetryMode.Safe, null, null, null, cancellationToken).ConfigureAwait(false);
        return Json.Api.Objects(response.Body, "data").Select(Mapping.ToSenderId).ToArray();
    }

    /// <inheritdoc/>
    public override string ToString() => $"SenderIdsResource {{ {transport} }}";
}

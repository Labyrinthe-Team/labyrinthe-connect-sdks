using System;
using System.Collections.Generic;
using System.Text;
using System.Text.Json;
using Labyrinthe.Connect.Internal;

namespace Labyrinthe.Connect;

/// <summary>A verified webhook event. Use pattern matching (<c>switch (evt) { case MessageStatusChanged e: … }</c>) to get the typed data.</summary>
public abstract record WebhookEvent
{
    /// <summary>Event identifier, identical across delivery attempts: deduplicate on it.</summary>
    public string Id { get; init; } = string.Empty;

    /// <summary>Event name exactly as sent by the API, e.g. <c>message.status_changed</c> (also for unknown events).</summary>
    public string Type { get; init; } = string.Empty;

    /// <summary>The known event type, or <see cref="WebhookEventType.Unknown"/>.</summary>
    public abstract WebhookEventType EventType { get; }

    /// <summary>Time of this delivery attempt (signed), in UTC.</summary>
    public DateTimeOffset Timestamp { get; init; }

    /// <summary>Decoded envelope <c>{"id", "event", "data", "timestamp"}</c>, as sent by the API.</summary>
    public JsonElement Raw { get; init; }

    /// <summary>Prints the identity of the event, never its raw content.</summary>
    /// <param name="builder">Target.</param>
    protected virtual bool PrintMembers(StringBuilder builder)
    {
        Guard.NotNull(builder, nameof(builder));

        builder.Append("Id = ").Append(Id).Append(", Type = ").Append(Type).Append(", Timestamp = ").Append(Timestamp);
        return true;
    }
}

/// <summary>Data of <c>message.status_changed</c>.</summary>
public sealed record MessageStatusChangedData
{
    /// <summary>Message identifier.</summary>
    public string MessageId { get; init; } = string.Empty;

    /// <summary>International format without "+". Personal data: <see cref="object.ToString"/> masks it.</summary>
    public string Recipient { get; init; } = string.Empty;

    /// <summary>New status.</summary>
    public MessageStatus Status { get; init; }

    /// <summary>Identifier given by the SMS provider.</summary>
    public string? ProviderMessageId { get; init; }

    /// <summary>Campaign of origin.</summary>
    public string? CampaignId { get; init; }

    private bool PrintMembers(StringBuilder builder)
    {
        builder.Append("MessageId = ").Append(MessageId)
            .Append(", Recipient = ").Append(PhoneNumber.Mask(Recipient))
            .Append(", Status = ").Append(Status)
            .Append(", ProviderMessageId = ").Append(ProviderMessageId)
            .Append(", CampaignId = ").Append(CampaignId);
        return true;
    }
}

/// <summary><c>message.status_changed</c>: the status of a message changed.</summary>
public sealed record MessageStatusChanged : WebhookEvent
{
    /// <inheritdoc/>
    public override WebhookEventType EventType => WebhookEventType.MessageStatusChanged;

    /// <summary>Typed data.</summary>
    public MessageStatusChangedData Data { get; init; } = new();
}

/// <summary>Data of <c>campaign.completed</c>.</summary>
public sealed record CampaignCompletedData
{
    /// <summary>Campaign identifier.</summary>
    public string CampaignId { get; init; } = string.Empty;

    /// <summary>Campaign name.</summary>
    public string Name { get; init; } = string.Empty;

    /// <summary>Number of recipients.</summary>
    public int RecipientsCount { get; init; }

    /// <summary>Number of messages per final status, e.g. <c>{"delivered": 1150, "failed": 30}</c>.</summary>
    public IReadOnlyDictionary<string, int> MessagesByStatus { get; init; } = new Dictionary<string, int>();

    /// <summary>Credits charged.</summary>
    public int CreditsCharged { get; init; }

    /// <summary>Completion time.</summary>
    public DateTimeOffset? CompletedAt { get; init; }
}

/// <summary><c>campaign.completed</c>: every message of a campaign reached a final status.</summary>
public sealed record CampaignCompleted : WebhookEvent
{
    /// <inheritdoc/>
    public override WebhookEventType EventType => WebhookEventType.CampaignCompleted;

    /// <summary>Typed data.</summary>
    public CampaignCompletedData Data { get; init; } = new();
}

/// <summary>Data of <c>balance.low</c>.</summary>
public sealed record BalanceLowData
{
    /// <summary>Balance after the operation that crossed the threshold.</summary>
    public int Balance { get; init; }

    /// <summary>Low-balance threshold of the account.</summary>
    public int Threshold { get; init; }
}

/// <summary><c>balance.low</c>: the balance went below the account's low-balance threshold.</summary>
public sealed record BalanceLow : WebhookEvent
{
    /// <inheritdoc/>
    public override WebhookEventType EventType => WebhookEventType.BalanceLow;

    /// <summary>Typed data.</summary>
    public BalanceLowData Data { get; init; } = new();
}

/// <summary><c>test.ping</c>: sent by the <i>Test</i> button of the client area.</summary>
public sealed record TestPing : WebhookEvent
{
    /// <inheritdoc/>
    public override WebhookEventType EventType => WebhookEventType.TestPing;

    /// <summary>Data as sent (usually <c>{}</c>).</summary>
    public JsonElement Data { get; init; }
}

/// <summary>An event type added to the API after this SDK version. Its signature was verified: acknowledge it with a 2xx.</summary>
public sealed record UnknownEvent : WebhookEvent
{
    /// <inheritdoc/>
    public override WebhookEventType EventType => WebhookEventType.Unknown;

    /// <summary>Data as sent (snake_case).</summary>
    public JsonElement Data { get; init; }

    /// <inheritdoc/>
    protected override bool PrintMembers(StringBuilder builder) => base.PrintMembers(builder);
}

using System;
using Microsoft.Extensions.Logging;

namespace Labyrinthe.Connect.AspNetCore;

/// <summary>Sends the SDK log records (already redacted) to a <c>Microsoft.Extensions.Logging</c> <see cref="ILogger"/>.</summary>
public sealed class MicrosoftLoggerAdapter : ILabyrintheLogger
{
    private static readonly Action<ILogger, string, Exception?> LogDebug =
        LoggerMessage.Define<string>(LogLevel.Debug, new EventId(1, "LabyrintheDebug"), "{Message}");

    private static readonly Action<ILogger, string, Exception?> LogInformation =
        LoggerMessage.Define<string>(LogLevel.Information, new EventId(2, "LabyrintheApiError"), "{Message}");

    private static readonly Action<ILogger, string, Exception?> LogWarning =
        LoggerMessage.Define<string>(LogLevel.Warning, new EventId(3, "LabyrintheRetry"), "{Message}");

    private static readonly Action<ILogger, string, Exception?> LogError =
        LoggerMessage.Define<string>(LogLevel.Error, new EventId(4, "LabyrintheNetworkError"), "{Message}");

    private readonly ILogger logger;

    /// <summary>Wraps a logger.</summary>
    /// <param name="logger">Target logger, e.g. <c>ILogger&lt;LabyrintheClient&gt;</c>.</param>
    public MicrosoftLoggerAdapter(ILogger logger)
    {
        ArgumentNullException.ThrowIfNull(logger);
        this.logger = logger;
    }

    /// <inheritdoc/>
    public bool IsEnabled(LabyrintheLogLevel level) => logger.IsEnabled(ToLogLevel(level));

    /// <inheritdoc/>
    public void Log(LabyrintheLogLevel level, string message)
    {
        switch (level)
        {
            case LabyrintheLogLevel.Debug:
                LogDebug(logger, message, null);
                break;
            case LabyrintheLogLevel.Information:
                LogInformation(logger, message, null);
                break;
            case LabyrintheLogLevel.Warning:
                LogWarning(logger, message, null);
                break;
            default:
                LogError(logger, message, null);
                break;
        }
    }

    private static LogLevel ToLogLevel(LabyrintheLogLevel level) => level switch
    {
        LabyrintheLogLevel.Debug => LogLevel.Debug,
        LabyrintheLogLevel.Information => LogLevel.Information,
        LabyrintheLogLevel.Warning => LogLevel.Warning,
        _ => LogLevel.Error,
    };
}

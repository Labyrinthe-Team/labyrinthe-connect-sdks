using System;
using Labyrinthe.Connect.Internal;

namespace Labyrinthe.Connect;

/// <summary>Severity of an SDK log record.</summary>
public enum LabyrintheLogLevel
{
    /// <summary>Each attempt and response.</summary>
    Debug,

    /// <summary>API errors returned to your code.</summary>
    Information,

    /// <summary>Automatic retries.</summary>
    Warning,

    /// <summary>Network errors and timeouts returned to your code.</summary>
    Error,
}

/// <summary>Receives the SDK log records.</summary>
/// <remarks>
/// Records never contain the API key, an OTP code, a message body or a full phone number (<c>24381*****78</c>).
/// <c>Labyrinthe.Connect.AspNetCore</c> plugs <c>Microsoft.Extensions.Logging</c> in automatically
/// (category <c>Labyrinthe.Connect.LabyrintheClient</c>); elsewhere, use <see cref="LabyrintheLogger.Create"/>.
/// </remarks>
public interface ILabyrintheLogger
{
    /// <summary>True when records of this level are wanted.</summary>
    /// <param name="level">Severity.</param>
    bool IsEnabled(LabyrintheLogLevel level);

    /// <summary>Writes one record.</summary>
    /// <param name="level">Severity.</param>
    /// <param name="message">Record text, already redacted.</param>
    void Log(LabyrintheLogLevel level, string message);
}

/// <summary>Ready-made <see cref="ILabyrintheLogger"/> implementations.</summary>
public static class LabyrintheLogger
{
    /// <summary>A logger that forwards every record at or above <paramref name="minimumLevel"/> to a delegate.</summary>
    /// <param name="write">Receives the level and the (redacted) message, e.g. <c>(level, message) =&gt; Console.Error.WriteLine(message)</c>.</param>
    /// <param name="minimumLevel">Lowest level forwarded.</param>
    public static ILabyrintheLogger Create(Action<LabyrintheLogLevel, string> write, LabyrintheLogLevel minimumLevel = LabyrintheLogLevel.Information)
    {
        Guard.NotNull(write, nameof(write));

        return new DelegateLogger(write, minimumLevel);
    }

    private sealed class DelegateLogger : ILabyrintheLogger
    {
        private readonly Action<LabyrintheLogLevel, string> write;
        private readonly LabyrintheLogLevel minimumLevel;

        public DelegateLogger(Action<LabyrintheLogLevel, string> write, LabyrintheLogLevel minimumLevel)
        {
            this.write = write;
            this.minimumLevel = minimumLevel;
        }

        public bool IsEnabled(LabyrintheLogLevel level) => level >= minimumLevel;

        public void Log(LabyrintheLogLevel level, string message)
        {
            if (IsEnabled(level))
            {
                write(level, message);
            }
        }
    }
}

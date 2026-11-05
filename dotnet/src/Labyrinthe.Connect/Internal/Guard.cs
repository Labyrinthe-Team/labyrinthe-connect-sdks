using System;

namespace Labyrinthe.Connect.Internal;

/// <summary>Vérifications d'arguments communes aux deux cibles (netstandard2.0 n'a pas <c>ThrowIfNull</c>).</summary>
internal static class Guard
{
    public static void NotNull(object? value, string paramName)
    {
#if NET8_0_OR_GREATER
        ArgumentNullException.ThrowIfNull(value, paramName);
#else
        if (value is null)
        {
            throw new ArgumentNullException(paramName);
        }
#endif
    }
}

using System;
using System.Collections.Generic;
using System.Text;
using System.Text.RegularExpressions;

namespace Labyrinthe.Connect.Internal;

/// <summary>Masque les secrets et les données personnelles avant un log ou un ToString. Usage interne.</summary>
internal static class Redact
{
    /// <summary>Paramètres de requête dont la valeur est un numéro de téléphone.</summary>
    private static readonly HashSet<string> PhoneParams = new(StringComparer.Ordinal) { "recipient", "phone" };

    private static readonly Regex QueryString = new("\\?[^\\s\"']*", RegexOptions.CultureInvariant, TimeSpan.FromSeconds(1));

    /// <summary><c>"lsk_test_abc…"</c> → <c>"lsk_test_****"</c>. Ne révèle jamais un caractère de la partie secrète.</summary>
    public static string ApiKey(string apiKey)
    {
        if (apiKey.StartsWith("lsk_live_", StringComparison.Ordinal))
        {
            return "lsk_live_****";
        }

        return apiKey.StartsWith("lsk_test_", StringComparison.Ordinal) ? "lsk_test_****" : "****";
    }

    /// <summary>Chemin + requête, numéros de téléphone masqués.</summary>
    public static string Path(string path, IReadOnlyList<KeyValuePair<string, string>> query)
    {
        if (query.Count == 0)
        {
            return path;
        }

        var builder = new StringBuilder(path).Append('?');
        for (var i = 0; i < query.Count; i++)
        {
            if (i > 0)
            {
                builder.Append('&');
            }

            var (name, value) = (query[i].Key, query[i].Value);
            builder.Append(Uri.EscapeDataString(name)).Append('=')
                .Append(PhoneParams.Contains(name) ? PhoneNumber.Mask(value) : Uri.EscapeDataString(value));
        }

        return builder.ToString();
    }

    /// <summary>Retire la requête d'une URL citée dans un message d'erreur (elle peut contenir un numéro).</summary>
    public static string StripQueryStrings(string text) => QueryString.Replace(text, "?…");
}

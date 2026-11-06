using System;
using System.Text;

namespace Labyrinthe.Connect;

/// <summary>
/// Phone number helpers, consistent with the API's own normalisation.
/// </summary>
/// <remarks>
/// <para><see cref="Normalize"/> returns international digits without "+" (e.g. <c>243812345678</c>), the format of
/// the API responses; the SDK sends numbers to the API in E.164 with "+" (<c>+243812345678</c>), so a short foreign
/// number keeps its country code. Accepted input formats
/// (with the default country code 243, DR Congo):</para>
/// <list type="bullet">
/// <item><c>+243 81 234 5678</c>, <c>00243812345678</c> → <c>243812345678</c> (explicit international prefix kept)</item>
/// <item><c>243812345678</c> → <c>243812345678</c></item>
/// <item><c>33612345678</c> → <c>33612345678</c>: without "+", 11 digits or more not starting with 0 are already
/// international (API 1.2.0)</item>
/// <item><c>0812345678</c>, <c>812345678</c> → <c>243812345678</c> (local format, default country code added)</item>
/// <item><c>+33 6 12 34 56 78</c> → <c>33612345678</c> (other countries are never blocked)</item>
/// </list>
/// <para>Validation is deliberately light (8 to 15 digits, E.164): the API remains the authority on whether a
/// number can receive SMS. For a short foreign number (10 digits or fewer), always use "+".</para>
/// </remarks>
public static class PhoneNumber
{
    /// <summary>Default country code added to local numbers (DR Congo).</summary>
    public const string DefaultCountryCode = "243";

    /// <summary>Normalises a number, or returns <see langword="null"/> when it cannot be a valid phone number.</summary>
    /// <param name="raw">The number as typed (any format).</param>
    /// <param name="defaultCountryCode">Country code added to local numbers, 1 to 3 digits without "+".</param>
    /// <exception cref="ArgumentException"><paramref name="defaultCountryCode"/> is not 1 to 3 digits.</exception>
    public static string? Normalize(string? raw, string defaultCountryCode = DefaultCountryCode)
    {
        AssertCountryCode(defaultCountryCode, nameof(defaultCountryCode));
        var value = raw?.Trim() ?? string.Empty;
        if (value.Length == 0)
        {
            return null;
        }

        var explicitInternational = value[0] == '+' || value.StartsWith("00", StringComparison.Ordinal);
        var digits = DigitsOf(value);

        if (explicitInternational)
        {
            if (value.StartsWith("00", StringComparison.Ordinal))
            {
                digits = digits.Substring(2);
            }
        }
        else if (!IsInternationalWithoutPlus(digits, defaultCountryCode))
        {
            // Format local : on retire les « 0 » de tête et on ajoute l'indicatif par défaut.
            digits = defaultCountryCode + digits.TrimStart('0');
        }

        return digits.Length is >= 8 and <= 15 ? digits : null;
    }

    /// <summary>True when <see cref="Normalize"/> accepts the number.</summary>
    /// <param name="raw">The number as typed (any format).</param>
    /// <param name="defaultCountryCode">Country code added to local numbers.</param>
    public static bool IsValid(string? raw, string defaultCountryCode = DefaultCountryCode) =>
        Normalize(raw, defaultCountryCode) is not null;

    /// <summary>Masks a number for logs: <c>243812345678</c> → <c>24381*****78</c>.</summary>
    /// <param name="phone">Any phone number.</param>
    public static string Mask(string? phone)
    {
        var digits = DigitsOf(phone ?? string.Empty);
        var length = digits.Length;
        if (length <= 4)
        {
            return new string('*', length);
        }

        var visibleStart = Math.Min(5, Math.Max(1, length - 7));
        var chars = digits.ToCharArray();
        for (var i = visibleStart; i < length - 2; i++)
        {
            chars[i] = '*';
        }

        return new string(chars);
    }

    /// <summary>
    /// Normalise au format E.164 avec "+" pour l'envoi à l'API (BACKEND-TODO n° 32) : sans "+", l'API lit un numéro
    /// de moins de 11 chiffres comme local RDC (<c>2481234567</c> partirait en <c>2432481234567</c>).
    /// </summary>
    internal static string? ToE164(string? raw, string defaultCountryCode) =>
        Normalize(raw, defaultCountryCode) is { } digits ? "+" + digits : null;

    /// <summary>Normalises a number to E.164 ("+" and digits) or throws (the number itself is never part of the message).</summary>
    internal static string NormalizeOrThrow(string? raw, string defaultCountryCode, string paramName)
    {
        return ToE164(raw, defaultCountryCode)
            ?? throw new ArgumentException(
                $"Invalid phone number in \"{paramName}\": expected an international number (e.g. 243812345678) or a local number.",
                paramName);
    }

    internal static void AssertCountryCode(string? countryCode, string paramName)
    {
        var valid = countryCode is { Length: >= 1 and <= 3 } && countryCode[0] is >= '1' and <= '9';
        if (valid)
        {
            foreach (var c in countryCode!)
            {
                valid &= c is >= '0' and <= '9';
            }
        }

        if (!valid)
        {
            throw new ArgumentException("The default country code must be 1 to 3 digits without \"+\" (e.g. \"243\").", paramName);
        }
    }

    /// <summary>
    /// Sans "+", déjà international : préfixé de l'indicatif par défaut (243812345678), ou 11 chiffres ou plus ne
    /// commençant pas par 0 (33612345678). Même règle que le backend (<c>App\Domain\Gateway\Support\PhoneNumber</c>) :
    /// aucun numéro local RDC n'a cette longueur (9 chiffres, 10 avec le 0).
    /// </summary>
    private static bool IsInternationalWithoutPlus(string digits, string defaultCountryCode) =>
        (digits.StartsWith(defaultCountryCode, StringComparison.Ordinal) && digits.Length > 10)
        || (digits.Length >= 11 && digits[0] != '0');

    private static string DigitsOf(string value)
    {
        var builder = new StringBuilder(value.Length);
        foreach (var c in value)
        {
            if (c is >= '0' and <= '9')
            {
                builder.Append(c);
            }
        }

        return builder.ToString();
    }
}

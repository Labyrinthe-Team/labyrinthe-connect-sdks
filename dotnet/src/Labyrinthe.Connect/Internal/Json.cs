using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Text.Json;

namespace Labyrinthe.Connect.Internal;

/// <summary>
/// Lecture typée du JSON de l'API : tolérante sur la représentation (entiers en chaîne acceptés),
/// stricte sur la présence des champs obligatoires. <c>error</c> construit l'exception (réponse API ou webhook).
/// </summary>
internal sealed class Json
{
    public static readonly Json Api = new("Unexpected API payload", message => new UnexpectedResponseException(message));

    private readonly string context;
    private readonly Func<string, LabyrintheException> error;

    public Json(string context, Func<string, LabyrintheException> error)
    {
        this.context = context;
        this.error = error;
    }

    public static int? ToInt(JsonElement value)
    {
        switch (value.ValueKind)
        {
            case JsonValueKind.Number:
                if (value.TryGetInt32(out var i))
                {
                    return i;
                }

                return value.TryGetDouble(out var d) && d == Math.Floor(d) && d is >= int.MinValue and <= int.MaxValue ? (int)d : null;
            case JsonValueKind.String:
                return int.TryParse(value.GetString()?.Trim(), NumberStyles.AllowLeadingSign, CultureInfo.InvariantCulture, out var parsed) ? parsed : null;
            default:
                return null;
        }
    }

    public static long? ToLong(JsonElement value) => value.ValueKind switch
    {
        JsonValueKind.Number when value.TryGetInt64(out var l) => l,
        JsonValueKind.String when long.TryParse(value.GetString()?.Trim(), NumberStyles.AllowLeadingSign, CultureInfo.InvariantCulture, out var l) => l,
        _ => null,
    };

    /// <summary>ISO 8601 → <see cref="DateTimeOffset"/> (UTC si aucun fuseau). Accepte <c>Z</c>, <c>+00:00</c> et les microsecondes.</summary>
    public static DateTimeOffset? ParseDate(string? value)
    {
        if (string.IsNullOrWhiteSpace(value))
        {
            return null;
        }

        return DateTimeOffset.TryParse(value!.Trim(), CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal, out var parsed) ? parsed : null;
    }

    /// <summary>Décode un document JSON ; <see langword="null"/> s'il n'est pas valide.</summary>
    public static JsonElement? TryParse(byte[] content)
    {
        try
        {
            using var document = JsonDocument.Parse(content);
            return document.RootElement.Clone();
        }
        catch (JsonException)
        {
            return null;
        }
    }

    /// <summary>Copie un objet JSON en dictionnaire (éléments clonés, indépendants du document).</summary>
    public static IReadOnlyDictionary<string, JsonElement> ToDictionary(JsonElement value)
    {
        var result = new Dictionary<string, JsonElement>(StringComparer.Ordinal);
        if (value.ValueKind == JsonValueKind.Object)
        {
            foreach (var property in value.EnumerateObject())
            {
                result[property.Name] = property.Value.Clone();
            }
        }

        return result;
    }

    public LabyrintheException Invalid(string key, string expected) => error($"{context}: field \"{key}\" should be {expected}.");

    public LabyrintheException Fail(string message) => error(message);

    public string String(JsonElement data, string key)
    {
        if (data.TryGetProperty(key, out var value))
        {
            if (value.ValueKind == JsonValueKind.String)
            {
                return value.GetString()!;
            }

            if (value.ValueKind == JsonValueKind.Number && value.TryGetInt64(out var number))
            {
                return number.ToString(CultureInfo.InvariantCulture);
            }
        }

        throw Invalid(key, "a string");
    }

    public string? NullableString(JsonElement data, string key) => IsNull(data, key) ? null : String(data, key);

    public int Int(JsonElement data, string key) =>
        (data.TryGetProperty(key, out var value) ? ToInt(value) : null) ?? throw Invalid(key, "an integer");

    public int? NullableInt(JsonElement data, string key) => IsNull(data, key) ? null : Int(data, key);

    public long Long(JsonElement data, string key) =>
        (data.TryGetProperty(key, out var value) ? ToLong(value) : null) ?? throw Invalid(key, "an integer");

    public bool Bool(JsonElement data, string key)
    {
        if (data.TryGetProperty(key, out var value))
        {
            switch (value.ValueKind)
            {
                case JsonValueKind.True:
                    return true;
                case JsonValueKind.False:
                    return false;
                case JsonValueKind.Number when value.TryGetInt32(out var i) && i is 0 or 1:
                    return i == 1;
            }
        }

        throw Invalid(key, "a boolean");
    }

    public JsonElement Obj(JsonElement data, string key) =>
        data.TryGetProperty(key, out var value) && value.ValueKind == JsonValueKind.Object ? value : throw Invalid(key, "an object");

    /// <summary>Objet facultatif : absent, nul ou <c>[]</c> (PHP) donnent un objet vide.</summary>
    public JsonElement OptionalObj(JsonElement data, string key)
    {
        if (!data.TryGetProperty(key, out var value)
            || value.ValueKind == JsonValueKind.Null
            || (value.ValueKind == JsonValueKind.Array && value.GetArrayLength() == 0))
        {
            return EmptyObject;
        }

        return Obj(data, key);
    }

    public IReadOnlyList<JsonElement> Objects(JsonElement data, string key)
    {
        if (!data.TryGetProperty(key, out var value) || value.ValueKind != JsonValueKind.Array)
        {
            throw Invalid(key, "an array");
        }

        var items = new List<JsonElement>(value.GetArrayLength());
        foreach (var item in value.EnumerateArray())
        {
            items.Add(item.ValueKind == JsonValueKind.Object ? item : throw Invalid(key + "[]", "an object"));
        }

        return items;
    }

    public DateTimeOffset Date(JsonElement data, string key) =>
        (data.TryGetProperty(key, out var value) && value.ValueKind == JsonValueKind.String ? ParseDate(value.GetString()) : null)
        ?? throw Invalid(key, "an ISO 8601 date-time");

    public DateTimeOffset? NullableDate(JsonElement data, string key) => IsNull(data, key) ? null : Date(data, key);

    private static bool IsNull(JsonElement data, string key) =>
        !data.TryGetProperty(key, out var value) || value.ValueKind == JsonValueKind.Null;

    private static readonly JsonElement EmptyObject = ParseConstant("{}");

    private static JsonElement ParseConstant(string json)
    {
        using var document = JsonDocument.Parse(json);
        return document.RootElement.Clone();
    }

    /// <summary>Corps de requête JSON écrit sans réflexion (compatible trimming / AOT).</summary>
    public static byte[] Write(IEnumerable<KeyValuePair<string, object?>> fields)
    {
        using var stream = new MemoryStream();
        using (var writer = new Utf8JsonWriter(stream))
        {
            writer.WriteStartObject();
            foreach (var field in fields)
            {
                switch (field.Value)
                {
                    case null:
                        break;
                    case string s:
                        writer.WriteString(field.Key, s);
                        break;
                    case int n:
                        writer.WriteNumber(field.Key, n);
                        break;
                    case IEnumerable<string> list:
                        writer.WriteStartArray(field.Key);
                        foreach (var item in list)
                        {
                            writer.WriteStringValue(item);
                        }

                        writer.WriteEndArray();
                        break;
                    default:
                        throw new ArgumentException("Unsupported JSON value.", nameof(fields));
                }
            }

            writer.WriteEndObject();
        }

        return stream.ToArray();
    }
}

using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Text.Json;
using System.Text.Json.Nodes;
using YamlDotNet.RepresentationModel;

namespace Labyrinthe.Connect.Tests.Support;

/// <summary>
/// openapi/openapi.yaml chargé en JSON, et un validateur JSON Schema réduit au sous-ensemble utilisé par la spec
/// ($ref, allOf, anyOf, oneOf, type, enum, const, required, properties, additionalProperties, items, min/max).
/// </summary>
public sealed class Spec
{
    private static readonly Lazy<Spec?> Instance = new(Load);

    private Spec(JsonNode root) => Root = root;

    public static Spec? Current => Instance.Value;

    public static string? SpecPath
    {
        get
        {
            var directory = new DirectoryInfo(AppContext.BaseDirectory);
            while (directory is not null)
            {
                var candidate = Path.Combine(directory.FullName, "openapi", "openapi.yaml");
                if (File.Exists(candidate))
                {
                    return candidate;
                }

                directory = directory.Parent;
            }

            return null;
        }
    }

    public JsonNode Root { get; }

    public JsonNode At(params string[] path)
    {
        JsonNode? node = Root;
        foreach (var segment in path)
        {
            node = node?[segment];
        }

        return node ?? throw new KeyNotFoundException(string.Join(" > ", path));
    }

    public JsonNode Schema(string name) => At("components", "schemas", name);

    public IReadOnlyList<string> Enum(string schema) => Schema(schema)["enum"]!.AsArray().Select(v => v!.GetValue<string>()).ToArray();

    public JsonNode ResponseSchema(string path, string method, string status) =>
        At("paths", path, method, "responses", status, "content", "application/json", "schema");

    public JsonNode RequestSchema(string path) => At("paths", path, "post", "requestBody", "content", "application/json", "schema");

    /// <summary>Les erreurs de validation de <paramref name="value"/> contre <paramref name="schema"/> (vide si valide).</summary>
    public List<string> Validate(JsonNode schema, JsonNode? value)
    {
        var errors = new List<string>();
        Check(schema, value, "$", errors);
        return errors;
    }

    public List<string> Validate(JsonNode schema, string json) => Validate(schema, JsonNode.Parse(json));

    private static Spec? Load()
    {
        var path = SpecPath;
        if (path is null)
        {
            return null;
        }

        var stream = new YamlStream();
        using (var reader = new StreamReader(path))
        {
            stream.Load(reader);
        }

        return new Spec(ToJson(stream.Documents[0].RootNode)!);
    }

    private static JsonNode? ToJson(YamlNode node)
    {
        switch (node)
        {
            case YamlMappingNode map:
                var obj = new JsonObject();
                foreach (var (key, value) in map.Children)
                {
                    obj[((YamlScalarNode)key).Value!] = ToJson(value);
                }

                return obj;
            case YamlSequenceNode sequence:
                var array = new JsonArray();
                foreach (var item in sequence.Children)
                {
                    array.Add(ToJson(item));
                }

                return array;
            case YamlScalarNode scalar:
                var text = scalar.Value ?? string.Empty;
                if (scalar.Style != YamlDotNet.Core.ScalarStyle.Plain)
                {
                    return JsonValue.Create(text);
                }

                // Scalaire YAML nu : null, booléen, entier, sinon chaîne.
                if (text is "null" or "~" or "")
                {
                    return null;
                }

                if (text is "true" or "false")
                {
                    return JsonValue.Create(text == "true");
                }

                if (long.TryParse(text, NumberStyles.AllowLeadingSign, CultureInfo.InvariantCulture, out var number))
                {
                    return JsonValue.Create(number);
                }

                return JsonValue.Create(text);
            default:
                throw new InvalidOperationException("Unsupported YAML node.");
        }
    }

    private JsonNode Resolve(JsonNode schema)
    {
        while (schema is JsonObject obj && obj["$ref"] is JsonValue reference)
        {
            var parts = reference.GetValue<string>().TrimStart('#', '/').Split('/');
            schema = At(parts);
        }

        return schema;
    }

    private void Check(JsonNode schemaNode, JsonNode? value, string path, List<string> errors)
    {
        var schema = Resolve(schemaNode).AsObject();

        if (schema["allOf"] is JsonArray all)
        {
            foreach (var sub in all)
            {
                Check(sub!, value, path, errors);
            }
        }

        if (schema["anyOf"] is JsonArray any && !any.Any(sub => Validate(sub!, value).Count == 0))
        {
            errors.Add($"{path}: matches none of anyOf");
        }

        if (schema["oneOf"] is JsonArray one && one.Count(sub => Validate(sub!, value).Count == 0) != 1)
        {
            errors.Add($"{path}: does not match exactly one of oneOf");
        }

        if (schema["const"] is { } constant && !JsonNode.DeepEquals(constant, value))
        {
            errors.Add($"{path}: expected const {constant.ToJsonString()}");
        }

        if (schema["enum"] is JsonArray values && !values.Any(v => JsonNode.DeepEquals(v, value)))
        {
            errors.Add($"{path}: {value?.ToJsonString() ?? "null"} is not in enum");
        }

        if (schema["type"] is { } type)
        {
            var allowed = type is JsonArray list ? list.Select(t => t!.GetValue<string>()).ToArray() : new[] { type.GetValue<string>() };
            if (!allowed.Any(t => HasType(value, t)))
            {
                errors.Add($"{path}: expected {string.Join("|", allowed)}, got {Kind(value)}");
                return;
            }
        }

        if (value is JsonObject obj)
        {
            if (schema["required"] is JsonArray required)
            {
                foreach (var name in required.Select(r => r!.GetValue<string>()))
                {
                    if (!obj.ContainsKey(name))
                    {
                        errors.Add($"{path}: missing required \"{name}\"");
                    }
                }
            }

            var properties = schema["properties"] as JsonObject;
            foreach (var (name, child) in obj)
            {
                if (properties?[name] is { } propertySchema)
                {
                    Check(propertySchema, child, $"{path}.{name}", errors);
                }
                else if (schema["additionalProperties"] is JsonObject additional)
                {
                    Check(additional, child, $"{path}.{name}", errors);
                }
            }
        }

        if (value is JsonArray array)
        {
            if (schema["items"] is { } items)
            {
                for (var i = 0; i < array.Count; i++)
                {
                    Check(items, array[i], $"{path}[{i}]", errors);
                }
            }

            if (schema["minItems"] is { } minItems && array.Count < minItems.GetValue<long>())
            {
                errors.Add($"{path}: fewer than {minItems} items");
            }

            if (schema["maxItems"] is { } maxItems && array.Count > maxItems.GetValue<long>())
            {
                errors.Add($"{path}: more than {maxItems} items");
            }
        }

        if (value is JsonValue scalar && scalar.TryGetValue<string>(out var text))
        {
            if (schema["minLength"] is { } minLength && text.Length < minLength.GetValue<long>())
            {
                errors.Add($"{path}: shorter than {minLength}");
            }

            if (schema["maxLength"] is { } maxLength && text.Length > maxLength.GetValue<long>())
            {
                errors.Add($"{path}: longer than {maxLength}");
            }

            if (schema["format"]?.GetValue<string>() == "date-time" && !DateTimeOffset.TryParse(text, CultureInfo.InvariantCulture, DateTimeStyles.None, out _))
            {
                errors.Add($"{path}: not a date-time");
            }
        }

        if (value is JsonValue numeric && numeric.GetValueKind() == JsonValueKind.Number && schema["minimum"] is { } minimum
            && Number(numeric) < Number(minimum))
        {
            errors.Add($"{path}: below minimum {minimum}");
        }
    }

    private static bool HasType(JsonNode? value, string type) => type switch
    {
        "null" => value is null,
        "object" => value is JsonObject,
        "array" => value is JsonArray,
        "string" => value is JsonValue v && v.GetValueKind() == JsonValueKind.String,
        "boolean" => value is JsonValue v && v.GetValueKind() is JsonValueKind.True or JsonValueKind.False,
        "integer" => value is JsonValue v && v.GetValueKind() == JsonValueKind.Number && Math.Abs(Number(v) % 1) < double.Epsilon,
        "number" => value is JsonValue v && v.GetValueKind() == JsonValueKind.Number,
        _ => false,
    };

    private static double Number(JsonNode node) => double.Parse(node.ToJsonString(), CultureInfo.InvariantCulture);

    private static string Kind(JsonNode? value) => value switch
    {
        null => "null",
        JsonObject => "object",
        JsonArray => "array",
        JsonValue v => v.GetValueKind().ToString(),
        _ => "?",
    };
}

package cd.labyrinthe.connect;

import java.util.ArrayList;
import java.util.Collection;
import java.util.List;
import java.util.Map;
import java.util.regex.Pattern;

/**
 * Validateur JSON Schema minimal (sous-ensemble utilisé par openapi.yaml) : $ref, allOf, anyOf,
 * oneOf, type, enum, const, required, properties, additionalProperties, items, min/maxItems,
 * min/maxLength, minimum, maximum, pattern. Suffisant pour les tests de contrat.
 */
final class SchemaValidator {

  private final Map<String, Object> spec;

  SchemaValidator(Map<String, Object> spec) {
    this.spec = spec;
  }

  List<String> validate(Object schema, Object value) {
    List<String> problems = new ArrayList<>();
    check(schema, value, "$", problems);
    return problems;
  }

  @SuppressWarnings("unchecked")
  Object resolve(String ref) {
    Object node = spec;
    for (String part : ref.substring(2).split("/")) {
      node = ((Map<String, Object>) node).get(part.replace("~1", "/").replace("~0", "~"));
    }
    return node;
  }

  @SuppressWarnings("unchecked")
  private void check(Object rawSchema, Object value, String path, List<String> problems) {
    if (!(rawSchema instanceof Map)) {
      return;
    }
    Map<String, Object> schema = (Map<String, Object>) rawSchema;
    if (schema.containsKey("$ref")) {
      check(resolve((String) schema.get("$ref")), value, path, problems);
    }
    if (schema.containsKey("allOf")) {
      for (Object sub : (List<Object>) schema.get("allOf")) {
        check(sub, value, path, problems);
      }
    }
    if (schema.containsKey("anyOf")) {
      int matches = countMatches((List<Object>) schema.get("anyOf"), value, path);
      if (matches == 0) {
        problems.add(path + ": matches no anyOf branch");
      }
    }
    if (schema.containsKey("oneOf")) {
      int matches = countMatches((List<Object>) schema.get("oneOf"), value, path);
      if (matches != 1) {
        problems.add(path + ": matches " + matches + " oneOf branches");
      }
    }
    if (schema.containsKey("type") && !typeMatches(schema.get("type"), value)) {
      problems.add(path + ": " + describe(value) + " is not " + schema.get("type"));
      return;
    }
    if (schema.containsKey("const") && !same(schema.get("const"), value)) {
      problems.add(path + ": expected const " + schema.get("const") + ", got " + value);
    }
    if (schema.containsKey("enum")) {
      boolean found = false;
      for (Object candidate : (List<Object>) schema.get("enum")) {
        found |= same(candidate, value);
      }
      if (!found) {
        problems.add(path + ": " + value + " not in " + schema.get("enum"));
      }
    }
    if (value instanceof String) {
      String s = (String) value;
      if (schema.get("maxLength") instanceof Number
          && s.length() > ((Number) schema.get("maxLength")).intValue()) {
        problems.add(path + ": longer than maxLength");
      }
      if (schema.get("minLength") instanceof Number
          && s.length() < ((Number) schema.get("minLength")).intValue()) {
        problems.add(path + ": shorter than minLength");
      }
      if (schema.get("pattern") instanceof String
          && !Pattern.compile((String) schema.get("pattern")).matcher(s).find()) {
        problems.add(path + ": does not match " + schema.get("pattern"));
      }
    }
    if (value instanceof Number && !(value instanceof Double)) {
      long n = ((Number) value).longValue();
      if (schema.get("minimum") instanceof Number
          && n < ((Number) schema.get("minimum")).longValue()) {
        problems.add(path + ": below minimum");
      }
      if (schema.get("maximum") instanceof Number
          && n > ((Number) schema.get("maximum")).longValue()) {
        problems.add(path + ": above maximum");
      }
    }
    if (value instanceof List) {
      List<Object> list = (List<Object>) value;
      if (schema.get("minItems") instanceof Number
          && list.size() < ((Number) schema.get("minItems")).intValue()) {
        problems.add(path + ": fewer than minItems");
      }
      if (schema.get("maxItems") instanceof Number
          && list.size() > ((Number) schema.get("maxItems")).intValue()) {
        problems.add(path + ": more than maxItems");
      }
      if (schema.containsKey("items")) {
        for (int i = 0; i < list.size(); i++) {
          check(schema.get("items"), list.get(i), path + "[" + i + "]", problems);
        }
      }
    }
    if (value instanceof Map) {
      Map<String, Object> object = (Map<String, Object>) value;
      if (schema.containsKey("required")) {
        for (Object key : (Collection<Object>) schema.get("required")) {
          if (!object.containsKey(key)) {
            problems.add(path + ": missing required " + key);
          }
        }
      }
      Map<String, Object> properties =
          schema.get("properties") instanceof Map
              ? (Map<String, Object>) schema.get("properties")
              : Map.of();
      for (Map.Entry<String, Object> entry : object.entrySet()) {
        if (properties.containsKey(entry.getKey())) {
          check(
              properties.get(entry.getKey()),
              entry.getValue(),
              path + "." + entry.getKey(),
              problems);
        } else if (schema.get("additionalProperties") instanceof Map) {
          check(
              schema.get("additionalProperties"),
              entry.getValue(),
              path + "." + entry.getKey(),
              problems);
        } else if (Boolean.FALSE.equals(schema.get("additionalProperties"))) {
          problems.add(path + ": unexpected property " + entry.getKey());
        }
      }
    }
  }

  private int countMatches(List<Object> branches, Object value, String path) {
    int matches = 0;
    for (Object branch : branches) {
      List<String> sub = new ArrayList<>();
      check(branch, value, path, sub);
      if (sub.isEmpty()) {
        matches++;
      }
    }
    return matches;
  }

  @SuppressWarnings("unchecked")
  private static boolean typeMatches(Object type, Object value) {
    if (type instanceof List) {
      for (Object t : (List<Object>) type) {
        if (typeMatches(t, value)) {
          return true;
        }
      }
      return false;
    }
    switch (String.valueOf(type)) {
      case "null":
        return value == null;
      case "string":
        return value instanceof String;
      case "integer":
        return value instanceof Long
            || value instanceof Integer
            || value instanceof java.math.BigInteger;
      case "number":
        return value instanceof Number;
      case "boolean":
        return value instanceof Boolean;
      case "array":
        return value instanceof List;
      case "object":
        return value instanceof Map;
      default:
        return false;
    }
  }

  private static boolean same(Object expected, Object actual) {
    if (expected instanceof Number && actual instanceof Number) {
      return ((Number) expected).doubleValue() == ((Number) actual).doubleValue();
    }
    return expected == null ? actual == null : expected.equals(actual);
  }

  private static String describe(Object value) {
    return value == null ? "null" : value.getClass().getSimpleName() + " " + value;
  }
}

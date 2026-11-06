namespace Labyrinthe.Connect.Tests.Support;

/// <summary>Réponses types de l'API, conformes à openapi/openapi.yaml (vérifiées par ContractTests).</summary>
public static class Fixtures
{
    public const string MessageId = "9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10";

    public static string Message(string id = MessageId, string recipient = "243812345678", string status = "queued", string? reference = null) => $$"""
        {"id":"{{id}}","reference":{{(reference is null ? "null" : $"\"{reference}\"")}},"recipient":"{{recipient}}","sender":"LABYRINTHE",
         "encoding":"gsm7","status":"{{status}}","segments":1,"credits_charged":1,"campaign_id":null,"provider_message_id":null,
         "error_code":null,"error_message":null,"created_at":"2026-11-05T09:39:11+00:00","submitted_at":null,"delivered_at":null}
        """;

    public static string Data(string item) => $"{{\"data\":{item}}}";

    public static string Messages(params string[] items) => $"{{\"data\":[{string.Join(",", items)}]}}";

    public static string Page(int current, int last, int total, int perPage, params string[] items) =>
        "{\"data\":[" + string.Join(",", items) + "],\"links\":{\"first\":null,\"last\":null,\"prev\":null,\"next\":null}," +
        $"\"meta\":{{\"current_page\":{current},\"from\":1,\"last_page\":{last},\"path\":\"https://api.test/api/public/v1/sms\",\"per_page\":{perPage},\"to\":1,\"total\":{total}}}}}";

    public const string Balance = """{"data":{"balance":5,"held":2}}""";

    public const string Estimate = """
        {"data":{"encoding":"gsm7","characters":5,"segments_per_message":1,"recipients_count":1,"total_credits":1,"balance_after":9}}
        """;

    public const string SenderIds = """
        {"data":[{"id":"3c9a1f7e-2b4d-4e6a-8f0c-9d1b5e7a3c20","value":"MABOUTIQUE","status":"approved","purpose":"Commandes","rejection_reason":null,"is_default":true,"created_at":"2026-11-01T08:00:00+00:00"}]}
        """;

    public const string OtpSent = """
        {"data":{"otp_id":"7b2e4c10-3f5a-4d8e-a1b9-0c6d2e8f4a71","expires_at":"2026-11-05T10:05:00+00:00","max_attempts":3,"code_length":6,"code_type":"numeric","resend_after_seconds":60}}
        """;

    public const string OtpSentWithCode = """
        {"data":{"otp_id":"7b2e4c10-3f5a-4d8e-a1b9-0c6d2e8f4a71","expires_at":"2026-11-05T10:05:00+00:00","max_attempts":3,"code_length":6,"code_type":"numeric","resend_after_seconds":60,"test_code":"482913"}}
        """;

    public const string Verified = """{"data":{"verified":true,"reason":null,"attempts_remaining":null}}""";

    public const string WrongCode = """{"data":{"verified":false,"reason":"invalid_code","attempts_remaining":2}}""";
}

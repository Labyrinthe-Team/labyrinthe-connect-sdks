using System;
using System.Collections.Generic;
using System.Text.Json;

namespace Labyrinthe.Connect.Internal;

/// <summary>Conversion des objets JSON de l'API en modèles du SDK. Usage interne.</summary>
internal static class Mapping
{
    private static readonly Json Api = Json.Api;

    public static Message ToMessage(JsonElement data)
    {
        var encoding = Api.NullableString(data, "encoding");
        return new Message
        {
            Id = Api.String(data, "id"),
            Reference = Api.NullableString(data, "reference"),
            Recipient = Api.String(data, "recipient"),
            Sender = Api.NullableString(data, "sender"),
            // Jamais null depuis la spec 1.2.0 ; null toléré (anciens backends).
            Encoding = encoding is null ? null : ApiValues.ParseEncoding(encoding),
            Status = ApiValues.ParseMessageStatus(Api.String(data, "status")),
            Segments = Api.NullableInt(data, "segments") ?? 0,
            CreditsCharged = Api.NullableInt(data, "credits_charged") ?? 0,
            CampaignId = Api.NullableString(data, "campaign_id"),
            ProviderMessageId = Api.NullableString(data, "provider_message_id"),
            ErrorCode = Api.NullableString(data, "error_code"),
            ErrorMessage = Api.NullableString(data, "error_message"),
            CreatedAt = Api.NullableDate(data, "created_at"),
            SubmittedAt = Api.NullableDate(data, "submitted_at"),
            DeliveredAt = Api.NullableDate(data, "delivered_at"),
        };
    }

    public static Estimate ToEstimate(JsonElement data) => new()
    {
        Encoding = ApiValues.ParseEncoding(Api.String(data, "encoding")),
        Characters = Api.Int(data, "characters"),
        SegmentsPerMessage = Api.Int(data, "segments_per_message"),
        RecipientsCount = Api.Int(data, "recipients_count"),
        TotalCredits = Api.Int(data, "total_credits"),
        BalanceAfter = Api.NullableInt(data, "balance_after"),
    };

    public static Balance ToBalance(JsonElement data) => new()
    {
        Available = Api.Int(data, "balance"),
        Held = Api.Int(data, "held"),
    };

    public static SenderId ToSenderId(JsonElement data) => new()
    {
        Id = Api.String(data, "id"),
        Value = Api.String(data, "value"),
        Status = ApiValues.ParseSenderIdStatus(Api.String(data, "status")),
        Purpose = Api.NullableString(data, "purpose"),
        RejectionReason = Api.NullableString(data, "rejection_reason"),
        IsDefault = data.TryGetProperty("is_default", out var d) && d.ValueKind != JsonValueKind.Null && Api.Bool(data, "is_default"),
        CreatedAt = Api.NullableDate(data, "created_at"),
    };

    public static OtpSendResult ToOtpSendResult(JsonElement data)
    {
        // code_length, code_type et resend_after_seconds : obligatoires depuis la spec 1.2.0, tolérés absents.
        var codeType = Api.NullableString(data, "code_type");
        return new OtpSendResult
        {
            OtpId = Api.String(data, "otp_id"),
            ExpiresAt = Api.Date(data, "expires_at"),
            MaxAttempts = Api.Int(data, "max_attempts"),
            CodeLength = Api.NullableInt(data, "code_length") ?? 0,
            CodeType = ApiValues.ParseOtpCodeType(codeType),
            ResendAfterSeconds = Api.NullableInt(data, "resend_after_seconds") ?? 0,
            TestCode = Api.NullableString(data, "test_code"),
        };
    }

    public static OtpVerifyResult ToOtpVerifyResult(JsonElement data) =>
        Api.Bool(data, "verified")
            ? new OtpVerifyResult { Verified = true, Reason = null, AttemptsRemaining = Api.NullableInt(data, "attempts_remaining") }
            : new OtpVerifyResult
            {
                Verified = false,
                Reason = ApiValues.ParseOtpFailureReason(Api.NullableString(data, "reason")),
                AttemptsRemaining = Api.NullableInt(data, "attempts_remaining"),
            };

    public static IReadOnlyList<Message> ToMessages(JsonElement body)
    {
        var items = Api.Objects(body, "data");
        var messages = new Message[items.Count];
        for (var i = 0; i < items.Count; i++)
        {
            messages[i] = ToMessage(items[i]);
        }

        return messages;
    }

    /// <summary>Erreur typée d'une réponse en erreur : par code d'abord, puis par statut HTTP, sinon <see cref="ApiException"/>.</summary>
    public static ApiException ToError(int status, byte[] content, IReadOnlyDictionary<string, string> headers)
    {
        string? code = null;
        string? message = null;
        IReadOnlyDictionary<string, JsonElement>? details = null;

        if (content.Length > 0 && Json.TryParse(content) is { ValueKind: JsonValueKind.Object } root
            && root.TryGetProperty("error", out var error) && error.ValueKind == JsonValueKind.Object)
        {
            code = error.TryGetProperty("code", out var c) && c.ValueKind == JsonValueKind.String ? c.GetString() : null;
            message = error.TryGetProperty("message", out var m) && m.ValueKind == JsonValueKind.String ? m.GetString() : null;
            // Toujours un objet depuis la spec 1.2.0 ; [] (anciens backends PHP) toléré : dictionnaire vide.
            details = error.TryGetProperty("details", out var d) ? Json.ToDictionary(d) : null;
        }

        code ??= status == 429 ? "TOO_MANY_REQUESTS" : "HTTP_ERROR";
        message ??= $"Labyrinthe Connect API returned HTTP {status}.";
        return Create(code, message, status, details, headers);
    }

    /// <summary>Codes d'erreur ayant une classe dédiée (vérifiés contre la spec par les tests de contrat).</summary>
    internal static readonly IReadOnlyDictionary<string, Type> ErrorTypesByCode = new Dictionary<string, Type>(StringComparer.Ordinal)
    {
        ["UNAUTHENTICATED"] = typeof(AuthenticationException),
        ["FORBIDDEN"] = typeof(PermissionException),
        ["ACCOUNT_SUSPENDED"] = typeof(AccountSuspendedException),
        ["OTP_DISABLED_FOR_ACCOUNT"] = typeof(PermissionException),
        ["NOT_FOUND"] = typeof(NotFoundException),
        ["VALIDATION_ERROR"] = typeof(ValidationException),
        ["RECIPIENT_NOT_SENDABLE"] = typeof(RecipientNotSendableException),
        ["SENDER_ID_NOT_APPROVED"] = typeof(SenderIdNotApprovedException),
        ["INVALID_PHONE"] = typeof(InvalidPhoneException),
        ["INSUFFICIENT_CREDITS"] = typeof(InsufficientCreditsException),
        ["IDEMPOTENCY_KEY_REUSED"] = typeof(IdempotencyKeyReusedException),
        ["IDEMPOTENCY_KEY_UNRESOLVED"] = typeof(IdempotencyKeyUnresolvedException),
        ["IDEMPOTENCY_REQUEST_IN_PROGRESS"] = typeof(IdempotencyRequestInProgressException),
        ["TOO_MANY_REQUESTS"] = typeof(RateLimitException),
        ["OTP_RESEND_TOO_SOON"] = typeof(OtpResendTooSoonException),
        ["OTP_RATE_LIMITED"] = typeof(OtpRateLimitedException),
        ["OTP_SERVICE_DISABLED"] = typeof(FeatureDisabledException),
        ["FEATURE_DISABLED"] = typeof(FeatureDisabledException),
    };

    private static ApiException Create(string code, string message, int status, IReadOnlyDictionary<string, JsonElement>? details, IReadOnlyDictionary<string, string> headers)
    {
        switch (code)
        {
            case "UNAUTHENTICATED": return new AuthenticationException(message, code, status, details, headers);
            case "FORBIDDEN":
            case "OTP_DISABLED_FOR_ACCOUNT": return new PermissionException(message, code, status, details, headers);
            case "ACCOUNT_SUSPENDED": return new AccountSuspendedException(message, code, status, details, headers);
            case "NOT_FOUND": return new NotFoundException(message, code, status, details, headers);
            case "VALIDATION_ERROR": return new ValidationException(message, code, status, details, headers);
            case "RECIPIENT_NOT_SENDABLE": return new RecipientNotSendableException(message, code, status, details, headers);
            case "SENDER_ID_NOT_APPROVED": return new SenderIdNotApprovedException(message, code, status, details, headers);
            case "INVALID_PHONE": return new InvalidPhoneException(message, code, status, details, headers);
            case "INSUFFICIENT_CREDITS": return new InsufficientCreditsException(message, code, status, details, headers);
            case "IDEMPOTENCY_KEY_REUSED": return new IdempotencyKeyReusedException(message, code, status, details, headers);
            case "IDEMPOTENCY_KEY_UNRESOLVED": return new IdempotencyKeyUnresolvedException(message, code, status, details, headers);
            case "IDEMPOTENCY_REQUEST_IN_PROGRESS": return new IdempotencyRequestInProgressException(message, code, status, details, headers);
            case "TOO_MANY_REQUESTS": return new RateLimitException(message, code, status, details, headers);
            case "OTP_RESEND_TOO_SOON": return new OtpResendTooSoonException(message, code, status, details, headers);
            case "OTP_RATE_LIMITED": return new OtpRateLimitedException(message, code, status, details, headers);
            // Fonctionnalité coupée par la plateforme (spec 1.3.0) : mêmes details pour les deux codes.
            case "OTP_SERVICE_DISABLED":
            case "FEATURE_DISABLED": return new FeatureDisabledException(message, code, status, details, headers);
        }

        return status switch
        {
            401 => new AuthenticationException(message, code, status, details, headers),
            403 => new PermissionException(message, code, status, details, headers),
            404 => new NotFoundException(message, code, status, details, headers),
            429 => new RateLimitException(message, code, status, details, headers),
            >= 500 => new ServiceUnavailableException(message, code, status, details, headers),
            _ => new ApiException(message, code, status, details, headers),
        };
    }
}

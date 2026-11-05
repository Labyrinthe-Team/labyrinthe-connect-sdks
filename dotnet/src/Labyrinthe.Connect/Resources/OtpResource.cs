using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Threading;
using System.Threading.Tasks;
using Labyrinthe.Connect.Internal;

namespace Labyrinthe.Connect.Resources;

/// <summary>One-time passwords by SMS (<c>client.Otp</c>).</summary>
/// <remarks>
/// <list type="number">
/// <item><see cref="SendAsync(string, CancellationToken)"/> and keep <see cref="OtpSendResult.OtpId"/> server-side (session);</item>
/// <item>the user types the code received by SMS;</item>
/// <item><see cref="VerifyAsync"/>: log the user in when <see cref="OtpVerifyResult.Verified"/> is true.</item>
/// </list>
/// Code length, validity, attempts, sender and template come from the account's OTP settings.
/// </remarks>
public sealed class OtpResource
{
    /// <summary>Longest template.</summary>
    public const int MaxTemplateLength = 1600;

    /// <summary>Longest code accepted by <see cref="VerifyAsync"/>.</summary>
    public const int MaxCodeLength = 20;

    private readonly Transport transport;
    private readonly string defaultCountryCode;

    internal OtpResource(Transport transport, string defaultCountryCode)
    {
        this.transport = transport;
        this.defaultCountryCode = defaultCountryCode;
    }

    /// <summary>Generates a code and sends it by SMS (billed like any SMS).</summary>
    /// <remarks>A new send to the same number invalidates the previous code. Never retried automatically, except on
    /// 429 <c>TOO_MANY_REQUESTS</c> (refused before processing): a second success would send a second, billed SMS.
    /// With a test key, <see cref="OtpSendResult.TestCode"/> holds the code.</remarks>
    /// <param name="request">Phone number, optional template and locale.</param>
    /// <param name="cancellationToken">Cancels the call.</param>
    /// <exception cref="OtpResendTooSoonException">A code was sent to this number too recently.</exception>
    /// <exception cref="OtpRateLimitedException">Hourly cap reached.</exception>
    public async Task<OtpSendResult> SendAsync(SendOtpRequest request, CancellationToken cancellationToken = default)
    {
        Guard.NotNull(request, nameof(request));

        var phone = PhoneNumber.NormalizeOrThrow(request.Phone, defaultCountryCode, nameof(request.Phone));
        if (request.Template is not null)
        {
            if (request.Template.IndexOf("{code}", StringComparison.Ordinal) < 0)
            {
                throw new ArgumentException("The OTP template must contain the {code} token.", nameof(request));
            }

            if (request.Template.Length > MaxTemplateLength)
            {
                throw new ArgumentException($"The OTP template must be at most {MaxTemplateLength} characters.", nameof(request));
            }
        }

        if (request.Locale is { } locale && locale is not (OtpLocale.Fr or OtpLocale.En))
        {
            throw new ArgumentException("Locale must be Fr or En.", nameof(request));
        }

        var body = Json.Write(new[]
        {
            new KeyValuePair<string, object?>("phone", phone),
            new KeyValuePair<string, object?>("template", request.Template),
            new KeyValuePair<string, object?>("locale", request.Locale?.ToApiString()),
        });
        var response = await transport.SendAsync(HttpMethod.Post, "/otp/send", RetryMode.ThrottledOnly, null, body, null, cancellationToken).ConfigureAwait(false);
        return Mapping.ToOtpSendResult(Json.Api.Obj(response.Body, "data"));
    }

    /// <summary>Generates a code and sends it by SMS, with the account's template and language.</summary>
    /// <param name="phone">Phone number, in any format.</param>
    /// <param name="cancellationToken">Cancels the call.</param>
    public Task<OtpSendResult> SendAsync(string phone, CancellationToken cancellationToken = default) =>
        SendAsync(new SendOtpRequest { Phone = phone }, cancellationToken);

    /// <summary>Checks a code. A wrong code is NOT an exception: <see cref="OtpVerifyResult.Verified"/> is false with a reason.</summary>
    /// <remarks>One attempt is consumed per wrong code. Free. Never retried after a network error or a 5xx.</remarks>
    /// <param name="otpId">The <see cref="OtpSendResult.OtpId"/> kept server-side.</param>
    /// <param name="code">The code typed by the user (spaces and case are ignored by the API).</param>
    /// <param name="cancellationToken">Cancels the call.</param>
    /// <exception cref="NotFoundException">Unknown <paramref name="otpId"/>.</exception>
    public async Task<OtpVerifyResult> VerifyAsync(string otpId, string code, CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(otpId))
        {
            throw new ArgumentException("otpId cannot be empty.", nameof(otpId));
        }

        if (string.IsNullOrWhiteSpace(code) || code.Length > MaxCodeLength)
        {
            // Le code saisi n'apparaît jamais dans le message.
            throw new ArgumentException($"The code must be 1 to {MaxCodeLength} characters.", nameof(code));
        }

        var body = Json.Write(new[]
        {
            new KeyValuePair<string, object?>("otp_id", otpId),
            new KeyValuePair<string, object?>("code", code),
        });
        var response = await transport.SendAsync(HttpMethod.Post, "/otp/verify", RetryMode.ThrottledOnly, null, body, null, cancellationToken).ConfigureAwait(false);
        return Mapping.ToOtpVerifyResult(Json.Api.Obj(response.Body, "data"));
    }

    /// <inheritdoc/>
    public override string ToString() => $"OtpResource {{ {transport} }}";
}

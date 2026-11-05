// Phone verification by OTP, as in a login or sign-up flow.
//
//   LABYRINTHE_API_KEY=lsk_test_… dotnet run --project examples/OtpLogin -- 0812345678
//
// In a web application, keep OtpId SERVER-SIDE (session, cache, database), never in the browser.
using System;
using Labyrinthe.Connect;

var phone = args.Length > 0 ? args[0] : "0812345678";
using var client = new LabyrintheClient();

OtpSendResult otp;
try
{
    // 1. Send the code.
    otp = await client.Otp.SendAsync(new SendOtpRequest { Phone = phone, Locale = OtpLocale.En });
}
catch (OtpResendTooSoonException error)
{
    Console.Error.WriteLine($"A code was just sent; try again in {error.RetryAfter?.TotalSeconds} s.");
    return 1;
}
catch (OtpRateLimitedException error)
{
    Console.Error.WriteLine($"Too many codes for this {error.Scope}; try again later.");
    return 1;
}

Console.WriteLine($"Code sent (expires at {otp.ExpiresAt:HH:mm:ss} UTC, {otp.MaxAttempts} attempts).");

// 2. The user types the code. In test mode the API returns it, so the demo can run unattended.
string? code;
if (client.IsTestMode && otp.TestCode is not null)
{
    Console.WriteLine("Test mode: using the code returned by the API.");
    code = otp.TestCode;
}
else
{
    Console.Write("Code received by SMS: ");
    code = Console.ReadLine();
}

// 3. Verify it. A wrong code is not an exception.
var result = await client.Otp.VerifyAsync(otp.OtpId, code ?? string.Empty);
if (result.Verified)
{
    Console.WriteLine("Phone number verified: log the user in.");
    return 0;
}

Console.WriteLine(result.Reason switch
{
    OtpFailureReason.InvalidCode => "Wrong code: one attempt was used, let the user try again.",
    OtpFailureReason.Expired => "The code expired: send a new one.",
    OtpFailureReason.AlreadyUsed => "This code was already used.",
    OtpFailureReason.MaxAttemptsReached => "Too many attempts: send a new code.",
    _ => "Verification failed.",
});
return 1;

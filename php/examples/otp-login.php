<?php

/**
 * OTP login flow, as two HTTP endpoints of your own backend.
 *
 *     LABYRINTHE_API_KEY=lsk_test_… php -S localhost:8080 examples/otp-login.php
 *     curl -X POST localhost:8080/otp/start  -d phone=0812345678 -c jar
 *     curl -X POST localhost:8080/otp/check  -d code=123456     -b jar
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Labyrinthe\Connect\Client;
use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\Exception\OtpRateLimitedException;
use Labyrinthe\Connect\Exception\OtpResendTooSoonException;

session_start();
header('Content-Type: application/json');

$client = Client::fromEnv();
$path = parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', \PHP_URL_PATH);
$input = static fn (string $name): string => is_string($_POST[$name] ?? null) ? $_POST[$name] : '';

/**
 * @param array<string, mixed> $body
 */
$respond = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, \JSON_THROW_ON_ERROR);
};

if ($path === '/otp/start') {
    try {
        // 1. Send the code and keep otp_id SERVER-SIDE (here: the PHP session).
        $otp = $client->otp->send($input('phone'), locale: 'en');
        $_SESSION['otp_id'] = $otp->otpId;
        $respond(200, ['sent' => true, 'expires_at' => $otp->expiresAt->format(\DATE_ATOM)]);
    } catch (InvalidArgumentException) {
        $respond(422, ['error' => 'Invalid phone number.']);
    } catch (OtpResendTooSoonException $e) {
        $respond(429, ['error' => 'Please wait before asking for a new code.', 'retry_after' => $e->getRetryAfter()]);
    } catch (OtpRateLimitedException) {
        $respond(429, ['error' => 'Too many codes requested. Try again later.']);
    }

    return;
}

if ($path === '/otp/check') {
    $otpId = $_SESSION['otp_id'] ?? null;
    if (!is_string($otpId)) {
        $respond(400, ['error' => 'Ask for a code first.']);

        return;
    }

    // 2. The user typed the code; 3. verify it.
    $result = $client->otp->verify($otpId, $input('code'));
    if ($result->isValid()) {
        unset($_SESSION['otp_id']);
        session_regenerate_id(true);
        $_SESSION['phone_verified'] = true;
        $respond(200, ['verified' => true]);
    } else {
        $respond(422, ['verified' => false, 'reason' => $result->reason?->value, 'can_retry' => $result->reason?->canRetry() ?? false]);
    }

    return;
}

$respond(404, ['error' => 'Not found']);

<?php

/**
 * Minimal webhook endpoint in plain PHP.
 *
 *     LABYRINTHE_WEBHOOK_SECRET=… php -S localhost:8080 examples/webhook-receiver.php
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Labyrinthe\Connect\Exception\InvalidWebhookPayloadException;
use Labyrinthe\Connect\Exception\SignatureVerificationException;
use Labyrinthe\Connect\PhoneNumber;
use Labyrinthe\Connect\Webhook\Event\BalanceLow;
use Labyrinthe\Connect\Webhook\Event\CampaignCompleted;
use Labyrinthe\Connect\Webhook\Event\MessageStatusChanged;
use Labyrinthe\Connect\Webhook\Event\OtpFailed;
use Labyrinthe\Connect\Webhook\Event\OtpVerified;
use Labyrinthe\Connect\Webhook\Event\TestPing;
use Labyrinthe\Connect\Webhook\Webhook;

$secret = getenv('LABYRINTHE_WEBHOOK_SECRET');
if (!is_string($secret) || $secret === '') {
    http_response_code(500);

    return;
}

// The RAW body, byte for byte: never re-encode a decoded array.
$rawBody = (string) file_get_contents('php://input');
$signature = is_string($_SERVER['HTTP_X_SIGNATURE'] ?? null) ? $_SERVER['HTTP_X_SIGNATURE'] : '';

try {
    $event = Webhook::constructEvent($rawBody, $signature, $secret);
} catch (SignatureVerificationException|InvalidWebhookPayloadException) {
    http_response_code(400);

    return;
}

// Deliveries can be repeated: skip ids you already processed (store them, e.g. 24 h).
$seenFile = sys_get_temp_dir().'/labyrinthe-webhook-'.sha1($event->id());
if (is_file($seenFile)) {
    http_response_code(200);

    return;
}
touch($seenFile);

match (true) {
    $event instanceof MessageStatusChanged => error_log(sprintf('Message %s is now %s', $event->messageId, $event->status->value)),
    $event instanceof CampaignCompleted => error_log(sprintf('Campaign "%s" completed: %d credits', $event->name, $event->creditsCharged)),
    $event instanceof BalanceLow => error_log(sprintf('Low balance: %d credits left (threshold %d)', $event->balance, $event->threshold)),
    // OTP events never carry the code; mask the number before logging it.
    $event instanceof OtpVerified => error_log(sprintf('OTP %s verified for %s', $event->otpId, PhoneNumber::mask($event->phone))),
    $event instanceof OtpFailed => error_log(sprintf('OTP %s %s (%s) for %s', $event->otpId, $event->status->value, $event->reason->value ?? 'no reason', PhoneNumber::mask($event->phone))),
    $event instanceof TestPing => error_log('Test ping received'),
    default => error_log('Unhandled event '.$event->type()),
};

// Answer 2xx quickly (within 10 s); do slow work in a queue.
http_response_code(200);

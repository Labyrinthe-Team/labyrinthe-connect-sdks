<?php

/**
 * Sends one SMS and prints its status.
 *
 *     LABYRINTHE_API_KEY=lsk_test_… php examples/send-sms.php 0812345678
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Labyrinthe\Connect\Client;
use Labyrinthe\Connect\Exception\ApiException;
use Labyrinthe\Connect\Exception\InsufficientCreditsException;
use Labyrinthe\Connect\Exception\RecipientNotSendableException;

$client = Client::fromEnv();
$to = $argv[1] ?? '0812345678';

try {
    $message = $client->sms->send(
        to: $to,
        body: 'Your order 1042 is ready.',
        reference: 'order-1042',
        idempotencyKey: 'order-1042-ready',
    );

    printf("Message %s: %s (%d credit(s))%s\n", $message->id, $message->status->value, $message->creditsCharged, $client->isTestMode() ? ' [test mode, nothing sent]' : '');
    printf("Status now: %s\n", $client->sms->get($message->id)->status->value);
} catch (RecipientNotSendableException $e) {
    printf("Recipient refused (%s): nothing was sent.\n", $e->getReason()->value);
} catch (InsufficientCreditsException $e) {
    printf("Not enough credits: %d available, %d required.\n", $e->getAvailable() ?? 0, $e->getRequired() ?? 0);
} catch (ApiException $e) {
    printf("API error %s (HTTP %d): %s\n", $e->getErrorCode(), $e->getStatusCode(), $e->getMessage());
}

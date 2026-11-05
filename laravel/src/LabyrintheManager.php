<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel;

use Illuminate\Contracts\Container\Container;
use Labyrinthe\Connect\Client;
use Labyrinthe\Connect\Model\Message;
use Labyrinthe\Connect\Model\MessageBatch;
use Labyrinthe\Connect\Service\BalanceService;
use Labyrinthe\Connect\Service\SenderIdService;
use Labyrinthe\Connect\Service\SmsService;

/**
 * Root of the `Labyrinthe` facade. Every call resolves the Client from the
 * container, so Labyrinthe::fake() also covers code that injected this class.
 */
class LabyrintheManager
{
    public function __construct(protected readonly Container $container)
    {
    }

    /** The underlying SDK client. */
    public function client(): Client
    {
        return $this->container->make(Client::class);
    }

    public function sms(): SmsService
    {
        return $this->client()->sms;
    }

    public function balance(): BalanceService
    {
        return $this->client()->balance;
    }

    public function senderIds(): SenderIdService
    {
        return $this->client()->senderIds;
    }

    /** High-level OTP helper using the `labyrinthe.otp` defaults. */
    public function otp(): OtpGateway
    {
        $config = $this->container->make('config');
        $template = $config->get('labyrinthe.otp.template');
        $locale = $config->get('labyrinthe.otp.locale');

        return new OtpGateway(
            $this->client()->otp,
            \is_string($template) && $template !== '' ? $template : null,
            \is_string($locale) && $locale !== '' ? $locale : null,
        );
    }

    /**
     * Shortcut: one recipient → Message, several → MessageBatch (all or nothing).
     *
     * @param string|array<array-key, string> $to
     */
    public function send(
        string|array $to,
        #[\SensitiveParameter]
        string $body,
        ?string $sender = null,
        ?string $reference = null,
        ?string $idempotencyKey = null,
    ): Message|MessageBatch {
        return \is_string($to)
            ? $this->sms()->send($to, $body, $sender, $reference, $idempotencyKey)
            : $this->sms()->sendMany($to, $body, $sender, $reference, $idempotencyKey);
    }

    public function isTestMode(): bool
    {
        return $this->client()->isTestMode();
    }
}

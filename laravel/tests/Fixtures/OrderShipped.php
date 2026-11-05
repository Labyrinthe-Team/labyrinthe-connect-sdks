<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Tests\Fixtures;

use Illuminate\Notifications\Notification;
use Labyrinthe\Connect\Laravel\Notifications\LabyrintheMessage;

final class OrderShipped extends Notification
{
    public function __construct(
        private readonly string $orderNumber,
        private readonly bool $asString = false,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['labyrinthe'];
    }

    public function toLabyrinthe(mixed $notifiable): LabyrintheMessage|string
    {
        $text = "Your order {$this->orderNumber} has shipped.";

        return $this->asString
            ? $text
            : LabyrintheMessage::create($text)->sender('MABOUTIQUE')->reference('order-'.$this->orderNumber);
    }
}

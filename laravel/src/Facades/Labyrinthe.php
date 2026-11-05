<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Labyrinthe\Connect\Client;
use Labyrinthe\Connect\Laravel\LabyrintheManager;
use Labyrinthe\Connect\Laravel\Testing\LabyrintheFake;

/**
 * @method static Client                                                                   client()
 * @method static \Labyrinthe\Connect\Service\SmsService                                   sms()
 * @method static \Labyrinthe\Connect\Service\BalanceService                               balance()
 * @method static \Labyrinthe\Connect\Service\SenderIdService                              senderIds()
 * @method static \Labyrinthe\Connect\Laravel\OtpGateway                                   otp()
 * @method static \Labyrinthe\Connect\Model\Message|\Labyrinthe\Connect\Model\MessageBatch send(string|array<array-key, string> $to, string $body, ?string $sender = null, ?string $reference = null, ?string $idempotencyKey = null)
 * @method static bool                                                                     isTestMode()
 * @method static void                                                                     assertSmsSent(callable|null $callback = null)
 * @method static void                                                                     assertSmsSentTo(string $phone, callable|null $callback = null)
 * @method static void                                                                     assertSmsNotSentTo(string $phone)
 * @method static void                                                                     assertSmsSentCount(int $count)
 * @method static void                                                                     assertNothingSent()
 * @method static void                                                                     assertOtpSent(string|callable|null $phoneOrCallback = null)
 * @method static void                                                                     assertNoOtpSent()
 *
 * @see LabyrintheManager
 * @see LabyrintheFake
 */
final class Labyrinthe extends Facade
{
    /**
     * Replaces the API with an in-memory fake: nothing leaves the
     * application, and calls can be asserted.
     */
    public static function fake(): LabyrintheFake
    {
        $app = static::getFacadeApplication();
        if ($app === null) {
            throw new \RuntimeException('Labyrinthe::fake() needs a Laravel application.');
        }

        $fake = new LabyrintheFake($app);
        static::swap($fake);
        $app->instance(LabyrintheManager::class, $fake);
        $app->instance(Client::class, $fake->client());

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return 'labyrinthe';
    }
}

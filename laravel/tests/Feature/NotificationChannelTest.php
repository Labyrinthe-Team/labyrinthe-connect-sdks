<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Notification;
use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;
use Labyrinthe\Connect\Laravel\Notifications\LabyrintheChannel;
use Labyrinthe\Connect\Laravel\Testing\SentSms;
use Labyrinthe\Connect\Laravel\Tests\Fixtures\OrderShipped;
use Labyrinthe\Connect\Laravel\Tests\Fixtures\User;
use Labyrinthe\Connect\Laravel\Tests\Fixtures\UserWithRoute;
use Labyrinthe\Connect\Laravel\Tests\TestCase;

final class NotificationChannelTest extends TestCase
{
    public function test_it_sends_to_the_route_method(): void
    {
        $fake = Labyrinthe::fake();

        (new UserWithRoute('+243 81 234 5678'))->notify(new OrderShipped('1042'));

        Labyrinthe::assertSmsSentTo('0812345678', static fn (SentSms $sms): bool => $sms->body === 'Your order 1042 has shipped.'
            && $sms->sender === 'MABOUTIQUE'
            && $sms->reference === 'order-1042');
        Labyrinthe::assertSmsNotSentTo('0800000000');
        $fake->assertSmsSentCount(1);
    }

    public function test_it_falls_back_to_the_phone_attribute(): void
    {
        Labyrinthe::fake();

        (new User('0899999999'))->notify(new OrderShipped('7', asString: true));

        Labyrinthe::assertSmsSentTo('243899999999', static fn (SentSms $sms): bool => $sms->sender === null);
    }

    public function test_on_demand_notifications(): void
    {
        Labyrinthe::fake();

        Notification::route('labyrinthe', '0812345678')->notify(new OrderShipped('9'));

        Labyrinthe::assertSmsSentTo('0812345678');
    }

    public function test_several_numbers_are_sent_all_or_nothing_in_one_request(): void
    {
        $fake = Labyrinthe::fake();

        (new UserWithRoute(['0812345678', '0899999999']))->notify(new OrderShipped('5'));

        $fake->assertSmsSentCount(1);
        self::assertSame(['243812345678', '243899999999'], $fake->sent()[0]->recipients);
    }

    public function test_the_idempotency_key_comes_from_the_notification_id(): void
    {
        $fake = Labyrinthe::fake();
        $notification = new OrderShipped('1');
        $notification->id = '7f1a2b3c-0000-4000-8000-000000000001';

        (new User('0812345678'))->notify($notification);
        // Same notification again (e.g. a retried queued job): replayed, not sent twice.
        (new User('0812345678'))->notify($notification);

        $fake->assertSmsSentCount(1);
        self::assertSame('laravel-notification-7f1a2b3c-0000-4000-8000-000000000001', $fake->sent()[0]->idempotencyKey);
    }

    public function test_the_config_sender_is_the_default(): void
    {
        config()->set('labyrinthe.sender', 'CONFIGSENDR');
        $fake = Labyrinthe::fake();

        (new User('0812345678'))->notify(new OrderShipped('3', asString: true));

        self::assertSame('CONFIGSENDR', $fake->sent()[0]->sender);
    }

    public function test_nothing_is_sent_without_a_route(): void
    {
        Labyrinthe::fake();

        (new User(null))->notify(new OrderShipped('1'));

        Labyrinthe::assertNothingSent();
    }

    public function test_invalid_numbers_are_refused_before_any_call(): void
    {
        Labyrinthe::fake();

        try {
            (new User('not-a-phone'))->notify(new OrderShipped('1'));
            self::fail('InvalidArgumentException expected.');
        } catch (InvalidArgumentException) {
        }

        Labyrinthe::assertNothingSent();
    }

    public function test_the_channel_class_can_be_used_directly_in_via(): void
    {
        $fake = Labyrinthe::fake();
        $channel = app()->make(LabyrintheChannel::class);

        $message = $channel->send(new User('0812345678'), new OrderShipped('11'));

        self::assertInstanceOf(\Labyrinthe\Connect\Model\Message::class, $message);
        $fake->assertSmsSentCount(1);
    }
}

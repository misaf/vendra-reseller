<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Listeners;

use Misaf\VendraReseller\Notifications\SubscriptionExpiringNotification;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Events\SubscriptionExpiringSoon;

final class RemindExpiringSubscriber
{
    public function handle(SubscriptionExpiringSoon $event): void
    {
        $subscriber = $event->subscription->subscriber;

        if (! $subscriber instanceof SubscriptionSubscriber) {
            return;
        }

        $subscriber->notifyContact(new SubscriptionExpiringNotification($event->subscription));
    }
}

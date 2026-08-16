<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Listeners;

use Misaf\VendraReseller\Notifications\SubscriptionExpiringNotification;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Events\SubscriptionExpiringSoon;

/**
 * Reminds a subscriber's owner that their subscription is about to expire. The
 * engine has already marked the subscription reminded; this listener only
 * performs the host-specific notification.
 */
final class RemindExpiringSubscriber
{
    public function handle(SubscriptionExpiringSoon $event): void
    {
        $subscriber = $event->subscription->subscriber;

        if ( ! $subscriber instanceof SubscriptionSubscriber || ! $subscriber->hasOwnerContact()) {
            return;
        }

        $subscriber->notifyOwner(new SubscriptionExpiringNotification($event->subscription));
    }
}

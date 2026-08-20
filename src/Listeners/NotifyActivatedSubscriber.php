<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Listeners;

use Misaf\VendraReseller\Notifications\SubscriptionActivatedNotification;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Events\SubscriptionActivated;

/**
 * Notifies a subscriber's owner that their subscription is now active. The
 * subscription engine has already activated the subscription and reactivated
 * its stores; this listener only performs the host-specific notification.
 */
final class NotifyActivatedSubscriber
{
    public function handle(SubscriptionActivated $event): void
    {
        $subscriber = $event->subscription->subscriber;

        if ( ! $subscriber instanceof SubscriptionSubscriber || ! $subscriber->hasOwnerContact()) {
            return;
        }

        $subscriber->notifyOwner(new SubscriptionActivatedNotification($event->subscription->plan()->firstOrFail()));
    }
}

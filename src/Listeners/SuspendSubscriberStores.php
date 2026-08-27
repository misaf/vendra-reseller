<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Listeners;

use Misaf\VendraReseller\Notifications\StoresSuspendedNotification;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Events\SubscriptionCancelled;
use Misaf\VendraSubscription\Events\SubscriptionGraceExpired;

/**
 * Suspends a subscriber's active stores after explicit cancellation or once
 * its grace period expires, and notifies the owner. Suspending concrete units
 * remains a host-specific reaction.
 */
final class SuspendSubscriberStores
{
    public function handle(SubscriptionCancelled|SubscriptionGraceExpired $event): void
    {
        $subscriber = $event->subscription->subscriber;

        if ( ! $subscriber instanceof SubscriptionSubscriber) {
            return;
        }

        $count = $subscriber->suspendActiveUnits();

        if ($count > 0 && $subscriber->hasOwnerContact()) {
            $subscriber->notifyOwner(new StoresSuspendedNotification($count));
        }
    }
}

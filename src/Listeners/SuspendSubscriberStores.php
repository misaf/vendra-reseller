<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Listeners;

use Misaf\VendraReseller\Notifications\StoresSuspendedNotification;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Events\SubscriptionGraceExpired;

/**
 * Suspends a subscriber's active stores once its subscription is past the
 * plan's grace window, and notifies the owner. The engine only detects the
 * lapse; suspending a subscriber’s stores is a host-specific reaction.
 */
final class SuspendSubscriberStores
{
    public function handle(SubscriptionGraceExpired $event): void
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

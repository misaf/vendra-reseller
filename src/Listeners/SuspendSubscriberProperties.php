<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Listeners;

use Misaf\VendraReseller\Notifications\PropertiesSuspendedNotification;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Events\SubscriptionGraceExpired;

/**
 * Suspends a subscriber's active properties once its subscription is past the
 * plan's grace window, and notifies the owner. The engine only detects the
 * lapse; suspending tenant properties is a host-specific reaction.
 */
final class SuspendSubscriberProperties
{
    public function handle(SubscriptionGraceExpired $event): void
    {
        $subscriber = $event->subscription->subscriber;

        if ( ! $subscriber instanceof SubscriptionSubscriber) {
            return;
        }

        $count = $subscriber->suspendActiveProperties();

        if ($count > 0 && $subscriber->hasOwnerContact()) {
            $subscriber->notifyOwner(new PropertiesSuspendedNotification($count));
        }
    }
}

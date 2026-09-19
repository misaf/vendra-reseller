<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Listeners;

use Misaf\VendraReseller\Notifications\StoresSuspendedNotification;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Events\SubscriptionCancelled;
use Misaf\VendraSubscription\Events\SubscriptionGraceExpired;

final class SuspendSubscriberStores
{
    public function handle(SubscriptionCancelled|SubscriptionGraceExpired $event): void
    {
        $subscriber = $event->subscription->subscriber;

        // Cancelling a pending plan change must not suspend stores the current plan still pays for.
        if (! $subscriber instanceof SubscriptionSubscriber || $subscriber->activeSubscription() !== null) {
            return;
        }

        $count = $subscriber->suspendActiveUnits();

        if ($count > 0) {
            $subscriber->notifyContact(new StoresSuspendedNotification($count));
        }
    }
}

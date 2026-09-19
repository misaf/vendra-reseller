<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Listeners;

use Misaf\VendraReseller\Notifications\SubscriptionActivatedNotification;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Events\SubscriptionActivated;

final class NotifyActivatedSubscriber
{
    public function handle(SubscriptionActivated $event): void
    {
        $subscriber = $event->subscription->subscriber;

        if (! $subscriber instanceof SubscriptionSubscriber) {
            return;
        }

        $subscriber->notifyContact(new SubscriptionActivatedNotification($event->subscription->plan()->firstOrFail()));
    }
}

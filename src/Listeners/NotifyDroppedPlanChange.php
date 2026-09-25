<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Listeners;

use Misaf\VendraReseller\Notifications\ScheduledPlanChangeDroppedNotification;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Events\ScheduledPlanChangeDropped;

final class NotifyDroppedPlanChange
{
    public function handle(ScheduledPlanChangeDropped $event): void
    {
        $subscriber = $event->subscription->subscriber;

        if (! $subscriber instanceof SubscriptionSubscriber) {
            return;
        }

        $subscriber->notifyContact(new ScheduledPlanChangeDroppedNotification($event->droppedPlan, $event->subscription->plan));
    }
}

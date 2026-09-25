<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Listeners;

use Misaf\VendraReseller\Notifications\SubscriptionExpiringNotification;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Events\SubscriptionExpiringSoon;
use Misaf\VendraSubscription\Support\PlanCoverage;

final readonly class RemindExpiringSubscriber
{
    public function __construct(private PlanCoverage $planCoverage) {}

    public function handle(SubscriptionExpiringSoon $event): void
    {
        $subscription = $event->subscription;
        $subscriber = $subscription->subscriber;

        if (! $subscriber instanceof SubscriptionSubscriber) {
            return;
        }

        $subscriber->notifyContact(new SubscriptionExpiringNotification(
            $subscription,
            $this->planCoverage->scheduledPlanOutgrown($subscription) ? $subscription->scheduledPlan : null,
        ));
    }
}

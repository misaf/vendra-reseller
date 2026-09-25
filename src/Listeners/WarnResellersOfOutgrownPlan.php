<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Notifications\PlanOutgrownNotification;
use Misaf\VendraReseller\Support\ResellersOverPlan;
use Misaf\VendraSubscription\Events\PlanEntitlementsChanged;
use Misaf\VendraSubscription\Models\Subscription;

final readonly class WarnResellersOfOutgrownPlan
{
    public function __construct(private ResellersOverPlan $resellersOverPlan) {}

    /**
     * Tell each reseller the changed plan no longer fits, once per subscription
     * period, so it can choose a plan that does before the renewal is refused.
     */
    public function handle(PlanEntitlementsChanged $event): void
    {
        Reseller::query()
            ->whereKey($this->resellersOverPlan->ids($event->plan))
            ->each(function (Reseller $reseller): void {
                $subscription = $reseller->activeSubscription();

                if (! $subscription instanceof Subscription) {
                    return;
                }

                $expiresAt = $subscription->ends_at?->isFuture() === true ? $subscription->ends_at : Date::now()->addMonth();

                if (Cache::add("plan-outgrown-warning:{$reseller->id}:{$subscription->id}", true, $expiresAt)) {
                    $reseller->notifyContact(new PlanOutgrownNotification($subscription));
                }
            });
    }
}

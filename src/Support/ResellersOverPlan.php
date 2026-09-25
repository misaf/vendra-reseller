<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Support;

use Illuminate\Database\Eloquent\Builder;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Support\PlanCoverage;

/**
 * Find the resellers whose stores no longer fit their active plan.
 *
 * Plan changes refuse a plan below usage, so a reseller only ends up here when
 * console staff lower the limits of the plan it is already on. Coverage is
 * checked per store in PHP, so this walks every reseller with an active plan.
 */
final readonly class ResellersOverPlan
{
    public function __construct(private PlanCoverage $planCoverage) {}

    /**
     * @return list<int>
     */
    public function ids(?Plan $plan = null): array
    {
        $ids = [];

        Reseller::query()
            ->whereHas('subscriptions', fn (Builder $query): Builder => $query
                ->active()
                ->when($plan instanceof Plan, fn (Builder $query): Builder => $query->where('plan_id', $plan?->getKey())))
            ->lazyById()
            ->each(function (Reseller $reseller) use (&$ids): void {
                $currentPlan = $reseller->activeSubscription()?->plan;

                if ($currentPlan instanceof Plan && ! $this->planCoverage->covers($reseller, $currentPlan)) {
                    $ids[] = $reseller->id;
                }
            });

        return $ids;
    }
}

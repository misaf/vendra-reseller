<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Support;

use Illuminate\Database\Eloquent\Model;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Models\StoreDomain;
use Misaf\VendraSubscription\Contracts\PlanUsageGuard;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Exceptions\SubscriptionLimitException;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSupport\Enums\PlanFeature;
use Misaf\VendraSupport\Enums\PlanLimit;
use Misaf\VendraSupport\Tenancy\TenantUsageRegistry;

/**
 * Refuse a plan that any of the reseller's stores already outgrows.
 */
final readonly class ResellerPlanUsageGuard implements PlanUsageGuard
{
    public function __construct(private TenantUsageRegistry $usageRegistry) {}

    public function assertPlanCovers(Model&SubscriptionSubscriber $subscriber, Plan $plan): void
    {
        if (! $subscriber instanceof Reseller) {
            return;
        }

        $subscriber->stores()->each(function (Store $store) use ($subscriber, $plan): void {
            $this->assertStoreFits($subscriber, $store, $plan);
        });
    }

    /**
     * @throws SubscriptionLimitException
     */
    private function assertStoreFits(Reseller $reseller, Store $store, Plan $plan): void
    {
        foreach (PlanLimit::cases() as $limit) {
            $allowed = $plan->limit($limit->value);
            $usage = $this->usageRegistry->usage($limit, $store);

            if ($allowed === null || $usage === null || $usage <= $allowed * $limit->unitSize()) {
                continue;
            }

            throw SubscriptionLimitException::planBelowEntitlementUsage(
                $reseller,
                $limit->getLabel(),
                $allowed,
                $limit->toUnits($usage),
            );
        }

        if ($plan->allows(PlanFeature::CustomDomain->value)) {
            return;
        }

        $usesCustomDomain = $store->domains()
            ->where('active', true)
            ->pluck('name')
            ->contains(fn (string $domain): bool => StoreDomain::isCustom($domain));

        if ($usesCustomDomain) {
            throw SubscriptionLimitException::planLacksFeature($reseller, PlanFeature::CustomDomain->getLabel());
        }
    }
}

<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Actions\ReactivateStoreForBillingAction;
use Misaf\VendraStore\Actions\SuspendStoreForBillingAction;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Contracts\SubscriptionUnitSuspender;

/**
 * A reseller's units are its stores. Each one goes through the store billing
 * actions, so its storefront stops or starts with it.
 */
final readonly class ResellerStoreSuspender implements SubscriptionUnitSuspender
{
    public function __construct(
        private SuspendStoreForBillingAction $suspendStore,
        private ReactivateStoreForBillingAction $reactivateStore,
    ) {}

    public function suspendActiveUnits(Model&SubscriptionSubscriber $subscriber): int
    {
        $stores = $this->reseller($subscriber)->stores()->accessible()->get();

        $stores->each(fn (Store $store): Store => $this->suspendStore->execute($store));

        return $stores->count();
    }

    public function reactivateSuspendedUnits(Model&SubscriptionSubscriber $subscriber): int
    {
        $stores = $this->reseller($subscriber)->stores()->billingSuspended()->get();

        $stores->each(fn (Store $store): Store => $this->reactivateStore->execute($store));

        return $stores->count();
    }

    private function reseller(SubscriptionSubscriber $subscriber): Reseller
    {
        throw_unless($subscriber instanceof Reseller, InvalidArgumentException::class, 'Only resellers hold stores as subscription units.');

        return $subscriber;
    }
}

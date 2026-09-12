<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Support;

use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Contracts\StoreResellerResolver;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;

/**
 * Answers the store package's billing port with the concrete reseller.
 *
 * This is the whole of the store→reseller coupling, and it points the right
 * way: the reseller package knows about stores, the store package only knows it
 * has a reseller key.
 */
final class EloquentStoreResellerResolver implements StoreResellerResolver
{
    public function find(int|string $resellerId): ?SubscriptionSubscriber
    {
        return Reseller::query()->find($resellerId);
    }
}

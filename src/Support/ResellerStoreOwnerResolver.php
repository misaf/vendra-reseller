<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Support;

use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Contracts\StoreOwnerResolver;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;

/**
 * Answers the store package's ownership port with the concrete reseller.
 *
 * This is the whole of the store→reseller coupling, and it points the right
 * way: the reseller package knows about stores, the store package only knows it
 * has an owner key.
 */
final class ResellerStoreOwnerResolver implements StoreOwnerResolver
{
    public function find(int|string $ownerId): ?SubscriptionSubscriber
    {
        return Reseller::query()->find($ownerId);
    }
}

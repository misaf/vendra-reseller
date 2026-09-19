<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Support;

use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Contracts\StoreResellerResolver;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;

final class EloquentStoreResellerResolver implements StoreResellerResolver
{
    public function find(int|string $resellerId): ?SubscriptionSubscriber
    {
        return Reseller::query()->find($resellerId);
    }
}

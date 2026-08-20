<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Pages;

use InvalidArgumentException;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Filament\Pages\CreateStorePage;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;

final class CreateStore extends CreateStorePage
{
    protected static string $resource = StoreResource::class;

    /**
     * A reseller only ever creates stores under its own billing account.
     *
     * @param array<string, mixed> $data
     */
    protected function resolveOwner(array $data): ?SubscriptionSubscriber
    {
        $resellerId = StoreResource::currentResellerId();

        if (null === $resellerId) {
            throw new InvalidArgumentException('No billing reseller for the current user.');
        }

        return Reseller::query()->findOrFail($resellerId);
    }
}

<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Widgets;

use Illuminate\Database\Eloquent\Builder;
use Misaf\VendraReseller\Filament\Concerns\InteractsWithCurrentReseller;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraStore\Enums\StorefrontDeploymentStatus;
use Misaf\VendraStore\Enums\StoreStatus;
use Misaf\VendraStore\Filament\Widgets\StoreStatusOverview as BaseStoreStatusOverview;
use Misaf\VendraStore\Models\Store;

/**
 * Counts only the acting reseller's stores; an unresolved reseller counts none.
 */
final class StoreStatusOverview extends BaseStoreStatusOverview
{
    use InteractsWithCurrentReseller;

    protected function stores(): Builder
    {
        return Store::query()->ownedBy(self::currentReseller());
    }

    protected function statusUrl(StoreStatus $status): string
    {
        return StoreResource::getUrl('index', ['filters' => ['status' => ['value' => $status->value]]]);
    }

    protected function failedStorefrontsUrl(): string
    {
        return StoreResource::getUrl('index', ['filters' => ['storefront_status' => ['value' => StorefrontDeploymentStatus::Failed->value]]]);
    }
}

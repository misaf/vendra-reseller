<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Actions;

use Filament\Actions\DeleteAction;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraStore\Actions\OffboardStoreAction;
use Misaf\VendraStore\Models\Store;

/**
 * Offboarding records the prior state, so a restore can reactivate the store.
 */
final class OffboardStoreTableAction extends DeleteAction
{
    public const string OFFBOARDING_REASON = 'Deleted by the reseller.';

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->authorize(fn (): bool => StoreResource::canManageStores())
            ->using(fn (Store $record, OffboardStoreAction $offboardStore): Store => $offboardStore->execute($record, self::OFFBOARDING_REASON));
    }
}

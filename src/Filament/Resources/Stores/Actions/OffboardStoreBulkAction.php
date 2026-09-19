<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Actions;

use Filament\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\Collection;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraStore\Actions\OffboardStoreAction;
use Misaf\VendraStore\Models\Store;

final class OffboardStoreBulkAction extends DeleteBulkAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->authorize(fn (): bool => StoreResource::canManageStores())
            ->using(fn (Collection $records, OffboardStoreAction $offboardStore): Collection => $records
                ->each(fn (Store $record): Store => $offboardStore->execute($record, OffboardStoreTableAction::OFFBOARDING_REASON)));
    }
}

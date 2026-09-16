<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraStore\Support\StoreQuota;

final class ListStores extends ListRecords
{
    protected static string $resource = StoreResource::class;

    public function getSubheading(): ?string
    {
        $remaining = $this->remainingStores();

        return $remaining === null
            ? null
            : __('vendra-reseller::attributes.remaining_stores').': '.$remaining;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->disabled(fn (): bool => (int) $this->remainingStores() <= 0),
        ];
    }

    private function remainingStores(): ?int
    {
        return once(function (): ?int {
            $reseller = StoreResource::currentReseller();

            return $reseller === null ? null : resolve(StoreQuota::class)->remainingStores($reseller);
        });
    }
}

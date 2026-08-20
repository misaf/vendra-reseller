<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Support\StoreQuota;

final class ListStores extends ListRecords
{
    protected static string $resource = StoreResource::class;

    public function getSubheading(): ?string
    {
        $remaining = $this->remainingStores();

        return null === $remaining
            ? null
            : __('console.remaining_stores') . ': ' . $remaining;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->disabled(fn(): bool => (int) $this->remainingStores() <= 0),
        ];
    }

    private function remainingStores(): ?int
    {
        $resellerId = StoreResource::currentResellerId();

        if (null === $resellerId) {
            return null;
        }

        $reseller = Reseller::query()->find($resellerId);

        if (null === $reseller) {
            return null;
        }

        return app(StoreQuota::class)->remainingStores($reseller);
    }
}

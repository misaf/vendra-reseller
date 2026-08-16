<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Properties\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Misaf\VendraProperty\Support\PropertyQuota;
use Misaf\VendraReseller\Filament\Resources\Properties\PropertyResource;
use Misaf\VendraReseller\Models\Reseller;

final class ListProperties extends ListRecords
{
    protected static string $resource = PropertyResource::class;

    public function getSubheading(): ?string
    {
        $remaining = $this->remainingProperties();

        return null === $remaining
            ? null
            : __('console.remaining_properties') . ': ' . $remaining;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->disabled(fn(): bool => (int) $this->remainingProperties() <= 0),
        ];
    }

    private function remainingProperties(): ?int
    {
        $resellerId = PropertyResource::currentResellerId();

        if (null === $resellerId) {
            return null;
        }

        $reseller = Reseller::query()->find($resellerId);

        if (null === $reseller) {
            return null;
        }

        return app(PropertyQuota::class)->remainingProperties($reseller);
    }
}

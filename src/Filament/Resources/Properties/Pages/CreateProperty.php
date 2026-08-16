<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Properties\Pages;

use InvalidArgumentException;
use Misaf\VendraProperty\Filament\Pages\CreatePropertyPage;
use Misaf\VendraReseller\Filament\Resources\Properties\PropertyResource;
use Misaf\VendraReseller\Models\Reseller;

final class CreateProperty extends CreatePropertyPage
{
    protected static string $resource = PropertyResource::class;

    /**
     * Owners only ever create properties under their own billing reseller.
     *
     * @param array<string, mixed> $data
     */
    protected function resolveReseller(array $data): Reseller
    {
        $resellerId = PropertyResource::currentResellerId();

        if (null === $resellerId) {
            throw new InvalidArgumentException('No billing reseller for the current user.');
        }

        return Reseller::query()->findOrFail($resellerId);
    }
}

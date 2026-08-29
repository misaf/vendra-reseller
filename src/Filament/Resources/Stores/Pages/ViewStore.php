<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;

final class ViewStore extends ViewRecord
{
    protected static string $resource = StoreResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}

<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Pages;

use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;

final class EditStore extends EditRecord
{
    protected static string $resource = StoreResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }
}

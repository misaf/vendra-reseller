<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;
use Misaf\VendraReseller\Filament\Resources\Stores\Actions\ReplaceDomainTableAction;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraStore\Filament\Resources\Stores\Tables\StoreTable as BaseStoreTable;
use Misaf\VendraStore\Models\Store;

final class StoreTable
{
    public static function configure(Table $table): Table
    {
        return BaseStoreTable::configure($table)
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    ActionGroup::make([
                        ReplaceDomainTableAction::make(),
                    ])->dropdown(false),
                    ActionGroup::make([
                        DeleteAction::make()
                            ->authorize(fn (): bool => StoreResource::canCreate()),
                    ])->dropdown(false),
                ]),
            ])
            ->recordUrl(fn (Store $record): string => StoreResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => StoreResource::canCreate()),
                ]),
            ]);
    }
}

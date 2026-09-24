<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Misaf\VendraReseller\Filament\Resources\Stores\Actions\AddDomainAliasTableAction;
use Misaf\VendraReseller\Filament\Resources\Stores\Actions\MakeDomainPrimaryTableAction;
use Misaf\VendraReseller\Filament\Resources\Stores\Actions\OffboardStoreBulkAction;
use Misaf\VendraReseller\Filament\Resources\Stores\Actions\OffboardStoreTableAction;
use Misaf\VendraReseller\Filament\Resources\Stores\Actions\RemoveDomainAliasTableAction;
use Misaf\VendraReseller\Filament\Resources\Stores\Actions\ReplaceDomainTableAction;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraStore\Enums\StorefrontDeploymentStatus;
use Misaf\VendraStore\Enums\StoreStatus;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraSupport\Filament\Tables\Columns\CreatedAtColumn;
use Misaf\VendraSupport\Filament\Tables\Columns\IsActiveIconColumn;
use Misaf\VendraSupport\Filament\Tables\Columns\NameColumn;
use Misaf\VendraSupport\Filament\Tables\Columns\RowIndexColumn;
use Misaf\VendraSupport\Filament\Tables\Columns\UpdatedAtColumn;
use Misaf\VendraSupport\Filament\Tables\Filters\IsActiveFilter;

final class StoreTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                RowIndexColumn::make(),

                NameColumn::make()
                    ->icon(null)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('domain')
                    ->label(__('vendra-reseller::attributes.domain'))
                    ->state(fn (Store $record): ?string => $record->primaryDomain?->name)
                    ->placeholder('—'),

                TextColumn::make('storefront_status')
                    ->label(__('vendra-reseller::attributes.storefront_status'))
                    ->badge()
                    ->state(fn (Store $record): ?StorefrontDeploymentStatus => $record->storefrontDeployment?->status)
                    ->placeholder(__('vendra-reseller::attributes.storefront_not_requested')),

                IsActiveIconColumn::make(),

                TextColumn::make('status')
                    ->label(__('vendra-reseller::attributes.operational_status'))
                    ->badge()
                    ->state(fn (Store $record): StoreStatus => $record->status()),

                CreatedAtColumn::make()
                    ->sortable(),

                UpdatedAtColumn::make(),
            ])
            ->description(__('vendra-reseller::tables.description.stores'))
            ->emptyStateHeading(__('vendra-reseller::tables.empty_state.heading.stores'))
            ->emptyStateDescription(__('vendra-reseller::tables.empty_state.description.stores'))
            ->emptyStateIcon(Heroicon::OutlinedGlobeAlt)
            ->filters(
                [
                    IsActiveFilter::make(),

                    SelectFilter::make('status')
                        ->label(__('vendra-reseller::attributes.operational_status'))
                        ->options(StoreStatus::class)
                        ->query(fn (Builder $query, array $data): Builder => self::filterByStatus($query, Arr::get($data, 'value', null))),

                    SelectFilter::make('storefront_status')
                        ->label(__('vendra-reseller::attributes.storefront_status'))
                        ->options(StorefrontDeploymentStatus::class)
                        ->query(fn (Builder $query, array $data): Builder => self::filterByDeploymentStatus($query, Arr::get($data, 'value', null))),
                ],
                layout: FiltersLayout::AboveContentCollapsible,
            )
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    ActionGroup::make([
                        ReplaceDomainTableAction::make(),
                        AddDomainAliasTableAction::make(),
                        MakeDomainPrimaryTableAction::make(),
                        RemoveDomainAliasTableAction::make(),
                    ])->dropdown(false),
                    ActionGroup::make([
                        OffboardStoreTableAction::make(),
                    ])->dropdown(false),
                ]),
            ])
            ->recordUrl(fn (Store $record): string => StoreResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    OffboardStoreBulkAction::make(),
                ]),
            ])
            ->defaultSort(column: 'id', direction: 'desc');
    }

    /**
     * @param  Builder<Store>  $query
     * @return Builder<Store>
     */
    private static function filterByStatus(Builder $query, mixed $value): Builder
    {
        $status = is_string($value) ? StoreStatus::tryFrom($value) : null;

        return $status instanceof StoreStatus ? $query->withStatus($status) : $query;
    }

    /**
     * @param  Builder<Store>  $query
     * @return Builder<Store>
     */
    private static function filterByDeploymentStatus(Builder $query, mixed $value): Builder
    {
        $status = is_string($value) ? StorefrontDeploymentStatus::tryFrom($value) : null;

        return $status instanceof StorefrontDeploymentStatus ? $query->withDeploymentStatus($status) : $query;
    }
}

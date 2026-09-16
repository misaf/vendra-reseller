<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Misaf\VendraReseller\Filament\Resources\Stores\Actions\ReplaceDomainTableAction;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraStore\Enums\StorefrontDeploymentStatus;
use Misaf\VendraStore\Enums\StoreStatus;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Models\StorefrontDeployment;
use Misaf\VendraSupport\Filament\Tables\Columns\CreatedAtColumn;
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
                    ->searchable()
                    ->sortable(),

                TextColumn::make('domain')
                    ->label(__('vendra-reseller::attributes.domain'))
                    ->icon(Heroicon::GlobeAlt)
                    ->state(fn (Store $record): ?string => $record->domains->first()?->name)
                    ->placeholder('—'),

                TextColumn::make('storefront_status')
                    ->label(__('vendra-reseller::attributes.storefront_status'))
                    ->badge()
                    ->state(fn (Store $record): ?string => self::deployment($record)?->status->value)
                    ->placeholder(__('vendra-reseller::attributes.storefront_not_requested')),

                TextColumn::make('admin_url')
                    ->label(__('vendra-reseller::attributes.admin_url'))
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->state(fn (Store $record): string => $record->adminUrl())
                    ->url(fn (Store $record): string => $record->adminUrl())
                    ->openUrlInNewTab()
                    ->copyable()
                    ->copyMessage(__('vendra-reseller::messages.url_copied')),

                TextColumn::make('storefront_url')
                    ->label(__('vendra-reseller::attributes.storefront_url'))
                    ->icon(Heroicon::OutlinedShoppingBag)
                    ->state(fn (Store $record): ?string => self::deployment($record)?->domain)
                    ->placeholder('—')
                    ->url(fn (Store $record): ?string => self::deployment($record)?->url())
                    ->openUrlInNewTab()
                    ->copyable()
                    ->copyMessage(__('vendra-reseller::messages.url_copied')),

                TextColumn::make('status')
                    ->label(__('vendra-reseller::attributes.operational_status'))
                    ->badge()
                    ->state(fn (Store $record): string => $record->status()->value)
                    ->formatStateUsing(fn (string $state): string => __("vendra-reseller::attributes.store_status_{$state}")),

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
                        ->options(self::statusOptions())
                        ->query(function (Builder $query, array $data): Builder {
                            $value = Arr::get($data, 'value', null);
                            $status = is_string($value) ? StoreStatus::tryFrom($value) : null;

                            return $status === null ? $query : $query->withStatus($status);
                        }),

                    SelectFilter::make('storefront_status')
                        ->label(__('vendra-reseller::attributes.storefront_status'))
                        ->options(self::deploymentStatusOptions())
                        ->query(function (Builder $query, array $data): Builder {
                            $value = Arr::get($data, 'value', null);
                            $status = is_string($value) ? StorefrontDeploymentStatus::tryFrom($value) : null;

                            return $status === null
                                ? $query
                                : $query->whereHas(
                                    'storefrontDeployments',
                                    fn (Builder $query): Builder => $query->where('status', $status),
                                );
                        }),
                ],
                layout: FiltersLayout::AboveContentCollapsible,
            )
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
            ])
            ->defaultSort(column: 'id', direction: 'desc');
    }

    private static function deployment(Store $store): ?StorefrontDeployment
    {
        $deployment = $store->storefrontDeployments->first();

        return $deployment instanceof StorefrontDeployment ? $deployment : null;
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(StoreStatus::cases())
            ->mapWithKeys(fn (StoreStatus $status): array => [
                $status->value => __("vendra-reseller::attributes.store_status_{$status->value}"),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function deploymentStatusOptions(): array
    {
        return collect(StorefrontDeploymentStatus::cases())
            ->mapWithKeys(fn (StorefrontDeploymentStatus $status): array => [
                $status->value => __("vendra-reseller::attributes.deployment_status_{$status->value}"),
            ])
            ->all();
    }
}

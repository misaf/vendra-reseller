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
use Misaf\VendraSupport\Contracts\TenantEntitlements;
use Misaf\VendraSupport\Enums\PlanLimit;
use Misaf\VendraSupport\Filament\Tables\Columns\CreatedAtColumn;
use Misaf\VendraSupport\Filament\Tables\Columns\IsActiveIconColumn;
use Misaf\VendraSupport\Filament\Tables\Columns\NameColumn;
use Misaf\VendraSupport\Filament\Tables\Columns\RowIndexColumn;
use Misaf\VendraSupport\Filament\Tables\Columns\UpdatedAtColumn;
use Misaf\VendraSupport\Filament\Tables\Filters\IsActiveFilter;
use Misaf\VendraSupport\Tenancy\TenantUsageRegistry;

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

                ...self::planUsageColumns(),

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
     * Show each store's usage of every per-store plan limit.
     *
     * Limits are read once per reseller, since every store of a reseller shares its plan.
     *
     * @return list<TextColumn>
     */
    private static function planUsageColumns(): array
    {
        /** @var array<string, int|null> $limits */
        $limits = [];

        /** @var array<string, int|null> $usages */
        $usages = [];

        /** @return array{used: int|null, allowed: int|null} */
        $usageOf = function (PlanLimit $limit, Store $store) use (&$limits, &$usages): array {
            $limitKey = $store->reseller_id.':'.$limit->value;
            $usageKey = $store->id.':'.$limit->value;

            if (! array_key_exists($limitKey, $limits)) {
                $limits[$limitKey] = resolve(TenantEntitlements::class)->limit($limit, $store);
            }

            if (! array_key_exists($usageKey, $usages)) {
                $usage = resolve(TenantUsageRegistry::class)->usage($limit, $store);
                $usages[$usageKey] = $usage === null ? null : $limit->toUnits($usage);
            }

            return ['used' => $usages[$usageKey], 'allowed' => $limits[$limitKey]];
        };

        return array_map(fn (PlanLimit $limit): TextColumn => TextColumn::make('usage_'.$limit->value)
            ->label($limit->getLabel())
            ->state(function (Store $record) use ($limit, $usageOf): ?string {
                ['used' => $used, 'allowed' => $allowed] = $usageOf($limit, $record);

                if ($used === null) {
                    return null;
                }

                return $allowed === null ? (string) $used : $used.' / '.$allowed;
            })
            ->color(function (Store $record) use ($limit, $usageOf): ?string {
                ['used' => $used, 'allowed' => $allowed] = $usageOf($limit, $record);

                return $used !== null && $allowed !== null && $used >= $allowed ? 'danger' : null;
            })
            ->placeholder('—')
            ->toggleable(), PlanLimit::cases());
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

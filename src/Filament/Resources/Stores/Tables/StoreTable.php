<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Misaf\VendraReseller\Filament\Resources\Stores\Actions\ReplaceDomainAction;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraStore\Enums\StorefrontDeploymentStatus;
use Misaf\VendraStore\Enums\StoreStatus;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Models\StorefrontDeployment;

final class StoreTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('row')
                    ->label('#')
                    ->rowIndex()
                    ->sortable(['id']),

                TextColumn::make('name')
                    ->label(__('console.name'))
                    ->icon(Heroicon::Tag)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('domain')
                    ->label(__('console.domain'))
                    ->icon(Heroicon::GlobeAlt)
                    ->state(fn(Store $record): ?string => $record->domains->first()?->name)
                    ->placeholder('—'),

                TextColumn::make('storefront_status')
                    ->label(__('console.storefront_status'))
                    ->badge()
                    ->state(fn(Store $record): ?string => self::deployment($record)?->status->value)
                    ->placeholder(__('console.storefront_not_requested')),

                TextColumn::make('admin_access')
                    ->label(__('console.admin_url'))
                    ->state(fn(Store $record): string => 'https://' . $record->slug . '.' . Config::string('vendra-tenant.central_host'))
                    ->description(function (Store $record): ?string {
                        $domain = $record->domains->first()?->name;

                        return null === $domain ? null : 'https://admin.' . $domain;
                    })
                    ->url(fn(Store $record): string => 'https://' . $record->slug . '.' . Config::string('vendra-tenant.central_host'))
                    ->openUrlInNewTab()
                    ->copyable()
                    ->copyMessage(__('console.url_copied'))
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label(__('console.operational_status'))
                    ->badge()
                    ->state(fn(Store $record): string => $record->status()->value)
                    ->formatStateUsing(fn(string $state): string => __("console.store_status_{$state}")),

                TextColumn::make('created_at')
                    ->extraCellAttributes(['dir' => 'ltr'])
                    ->label(__('console.created_at'))
                    ->sinceTooltip()
                    ->sortable()
                    ->when(
                        app()->isLocale('fa'),
                        fn(TextColumn $column) => $column->jalaliDateTime('Y-m-d H:i', latinNumbers: true),
                        fn(TextColumn $column) => $column->dateTime('Y-m-d H:i')
                    ),

                TextColumn::make('updated_at')
                    ->extraCellAttributes(['dir' => 'ltr'])
                    ->label(__('console.updated_at'))
                    ->sinceTooltip()
                    ->when(
                        app()->isLocale('fa'),
                        fn(TextColumn $column) => $column->jalaliDateTime('Y-m-d H:i', latinNumbers: true),
                        fn(TextColumn $column) => $column->dateTime('Y-m-d H:i')
                    ),
            ])
            ->description(__('console.tables.description.stores'))
            ->emptyStateHeading(__('console.tables.empty_state.heading.stores'))
            ->emptyStateDescription(__('console.tables.empty_state.description.stores'))
            ->emptyStateIcon(Heroicon::OutlinedGlobeAlt)
            ->filters(
                [
                    TernaryFilter::make('active')
                        ->label(__('console.active'))
                        ->trueLabel(__('console.active'))
                        ->falseLabel(__('console.inactive'))
                        ->queries(
                            true: fn(Builder $query): Builder => $query->where('active', true),
                            false: fn(Builder $query): Builder => $query->where('active', false),
                            blank: fn(Builder $query): Builder => $query,
                        ),

                    SelectFilter::make('status')
                        ->label(__('console.operational_status'))
                        ->options(self::statusOptions())
                        ->query(function (Builder $query, array $data): Builder {
                            $value = $data['value'] ?? null;
                            $status = is_string($value) ? StoreStatus::tryFrom($value) : null;

                            return null === $status ? $query : $query->withStatus($status);
                        }),

                    SelectFilter::make('storefront_status')
                        ->label(__('console.storefront_status'))
                        ->options(self::deploymentStatusOptions())
                        ->query(function (Builder $query, array $data): Builder {
                            $value = $data['value'] ?? null;
                            $status = is_string($value) ? StorefrontDeploymentStatus::tryFrom($value) : null;

                            return null === $status
                                ? $query
                                : $query->whereHas(
                                    'storefrontDeployments',
                                    fn(Builder $query): Builder => $query->where('status', $status),
                                );
                        }),
                ],
                layout: FiltersLayout::AboveContentCollapsible,
            )
            ->recordActions([
                ActionGroup::make([
                    ReplaceDomainAction::make(),

                    DeleteAction::make()
                        ->authorize(fn(): bool => StoreResource::canCreate()),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn(): bool => StoreResource::canCreate()),
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
            ->mapWithKeys(fn(StoreStatus $status): array => [
                $status->value => __("console.store_status_{$status->value}"),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function deploymentStatusOptions(): array
    {
        return collect(StorefrontDeploymentStatus::cases())
            ->mapWithKeys(fn(StorefrontDeploymentStatus $status): array => [
                $status->value => __("console.deployment_status_{$status->value}"),
            ])
            ->all();
    }
}

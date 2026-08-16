<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Properties\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Misaf\VendraProperty\Models\StorefrontDeployment;
use Misaf\VendraReseller\Filament\Resources\Properties\Actions\ReplaceDomainAction;
use Misaf\VendraReseller\Filament\Resources\Properties\PropertyResource;
use Misaf\VendraTenant\Models\Tenant;

final class PropertyTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query): Builder => $query->where('reseller_id', PropertyResource::currentResellerId()))
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
                    ->state(fn(Tenant $record): ?string => $record->activeDomainName())
                    ->placeholder('—'),

                TextColumn::make('storefront_status')
                    ->label(__('console.storefront_status'))
                    ->badge()
                    ->state(fn(Tenant $record): ?string => self::storefrontStatuses()->get($record->id))
                    ->placeholder(__('console.storefront_not_requested')),

                TextColumn::make('admin_access')
                    ->label(__('console.admin_url'))
                    ->state(fn(Tenant $record): string => 'https://' . $record->slug . '.' . Config::string('vendra-tenant.central_host'))
                    ->description(fn(Tenant $record): ?string => $record->activeDomainName()
                        ? 'https://admin.' . $record->activeDomainName()
                        : null)
                    ->url(fn(Tenant $record): string => 'https://' . $record->slug . '.' . Config::string('vendra-tenant.central_host'))
                    ->openUrlInNewTab()
                    ->copyable()
                    ->copyMessage(__('console.url_copied'))
                    ->placeholder('—'),

                ToggleColumn::make('active')
                    ->label(__('console.active'))
                    ->onIcon(Heroicon::Bolt),

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
            ->description(__('console.tables.description.properties'))
            ->emptyStateHeading(__('console.tables.empty_state.heading.properties'))
            ->emptyStateDescription(__('console.tables.empty_state.description.properties'))
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
                ],
                layout: FiltersLayout::AboveContentCollapsible,
            )
            ->recordActions([
                ActionGroup::make([
                    ReplaceDomainAction::make(),

                    DeleteAction::make()
                        ->authorize(fn(): bool => PropertyResource::canCreate()),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn(): bool => PropertyResource::canCreate()),
                ]),
            ])
            ->defaultSort(column: 'id', direction: 'desc');
    }

    /** @return Collection<int, 'failed'|'pending'|'processing'|'ready'|'requested'> */
    private static function storefrontStatuses(): Collection
    {
        return once(fn(): Collection => StorefrontDeployment::query()
            ->orderBy('id')
            ->get(['tenant_id', 'status'])
            ->mapWithKeys(fn(StorefrontDeployment $deployment): array => [
                $deployment->tenant_id => $deployment->status->value,
            ]));
    }
}

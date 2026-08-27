<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Misaf\VendraReseller\Filament\Concerns\InteractsWithCurrentReseller;
use Misaf\VendraStore\Models\Store;

final class LatestStores extends BaseWidget
{
    use InteractsWithCurrentReseller;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $reseller = (new self())->currentReseller();

        return null !== $reseller && $reseller->stores()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('console.stores'))
            ->query(fn(): Builder => Store::query()
                ->where('reseller_id', $this->currentReseller()?->getKey() ?? 0)
                ->with([
                    'domains' => fn(Relation $relation): Relation => $relation->where('active', true),
                ]))
            ->columns([
                TextColumn::make('name')
                    ->label(__('console.name'))
                    ->icon(Heroicon::Tag)
                    ->searchable(),

                TextColumn::make('domain')
                    ->label(__('console.domain'))
                    ->icon(Heroicon::GlobeAlt)
                    ->state(fn(Store $record): ?string => $record->domains->first()?->name)
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
            ])
            ->defaultSort('id', 'desc')
            ->paginated([5, 10, 25]);
    }
}

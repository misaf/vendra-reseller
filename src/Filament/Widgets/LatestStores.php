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
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraStore\Enums\StoreStatus;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraSupport\Filament\Tables\Columns\CreatedAtColumn;
use Misaf\VendraSupport\Filament\Tables\Columns\NameColumn;

final class LatestStores extends BaseWidget
{
    use InteractsWithCurrentReseller;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = null;

    public static function getHeading(): ?string
    {
        return __('vendra-reseller::navigation.stores');
    }

    public static function canView(): bool
    {
        $reseller = self::currentReseller();

        return $reseller !== null && $reseller->stores()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(self::getHeading())
            ->query(fn (): Builder => Store::query()
                ->where('reseller_id', self::currentReseller()?->getKey() ?? 0)
                ->with([
                    'domains' => fn (Relation $relation): Relation => $relation->where('active', true),
                ]))
            ->columns([
                NameColumn::make()
                    ->searchable(),

                TextColumn::make('domain')
                    ->label(__('vendra-reseller::attributes.domain'))
                    ->icon(Heroicon::GlobeAlt)
                    ->state(fn (Store $record): ?string => $record->domains->first()?->name)
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label(__('vendra-reseller::attributes.operational_status'))
                    ->badge()
                    ->state(fn (Store $record): StoreStatus => $record->status()),

                CreatedAtColumn::make()
                    ->sortable(),
            ])
            ->recordUrl(fn (Store $record): string => StoreResource::getUrl('view', ['record' => $record]))
            ->defaultSort('id', 'desc')
            ->paginated([5, 10, 25]);
    }
}

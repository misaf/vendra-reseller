<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Widgets;

use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Misaf\VendraReseller\Filament\Concerns\InteractsWithCurrentReseller;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraStore\Filament\Tables\Columns\StoreDomainColumn;
use Misaf\VendraStore\Filament\Tables\Columns\StoreStatusColumn;
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
        $reseller = (new self)->currentReseller();

        return $reseller !== null && $reseller->stores()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(self::getHeading())
            ->query(fn (): Builder => Store::query()
                ->where('reseller_id', $this->currentReseller()?->getKey() ?? 0)
                ->with([
                    'domains' => fn (Relation $relation): Relation => $relation->where('active', true),
                ]))
            ->columns([
                NameColumn::make()
                    ->searchable(),

                StoreDomainColumn::make(),

                StoreStatusColumn::make(),

                CreatedAtColumn::make()
                    ->sortable(),
            ])
            ->recordUrl(fn (Store $record): string => StoreResource::getUrl('view', ['record' => $record]))
            ->defaultSort('id', 'desc')
            ->paginated([5, 10, 25]);
    }
}

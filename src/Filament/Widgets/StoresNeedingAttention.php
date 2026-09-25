<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Misaf\VendraReseller\Filament\Concerns\InteractsWithCurrentReseller;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraStore\Enums\StorefrontDeploymentStatus;
use Misaf\VendraStore\Enums\StoreStatus;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraSupport\Enums\PlanLimit;
use Misaf\VendraSupport\Filament\Tables\Columns\NameColumn;
use Misaf\VendraSupport\Tenancy\TenantLimitOverages;

final class StoresNeedingAttention extends TableWidget
{
    use InteractsWithCurrentReseller;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return self::query()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('vendra-reseller::attributes.stores_needing_attention'))
            ->query(fn (): Builder => self::query()->with('storefrontDeployment'))
            ->columns([
                NameColumn::make(),
                TextColumn::make('status')
                    ->label(__('vendra-reseller::attributes.operational_status'))
                    ->badge()
                    ->state(fn (Store $record): StoreStatus => $record->status()),
                TextColumn::make('storefront_status')
                    ->label(__('vendra-reseller::attributes.storefront_status'))
                    ->badge()
                    ->state(fn (Store $record): ?StorefrontDeploymentStatus => $record->storefrontDeployment?->status)
                    ->placeholder(__('vendra-reseller::attributes.storefront_not_requested')),
                TextColumn::make('problem')
                    ->label(__('vendra-reseller::attributes.problem'))
                    ->state(fn (Store $record): ?string => $record->provisioning_error
                        ?? $record->storefrontDeployment->error
                        ?? self::overLimitProblem($record))
                    ->limit(120)
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->recordUrl(fn (Store $record): string => StoreResource::getUrl('view', ['record' => $record]))
            ->defaultSort('id', 'desc')
            ->poll('60s')
            ->paginated([5, 10]);
    }

    /**
     * @return Builder<Store>
     */
    private static function query(): Builder
    {
        return Store::query()
            ->ownedBy(self::currentReseller())
            ->where(fn (Builder $query): Builder => $query
                ->needingAttention()
                ->orWhereKey(array_keys(self::overLimitStores())));
    }

    private static function overLimitProblem(Store $store): ?string
    {
        $limits = self::overLimitStores()[$store->id] ?? [];

        if ($limits === []) {
            return null;
        }

        return __('vendra-reseller::attributes.over_plan_limits', [
            'limits' => implode(', ', array_map(fn (PlanLimit $limit): string => $limit->getLabel(), $limits)),
        ]);
    }

    /**
     * The reseller's stores whose usage exceeds its plan, with the limits they exceed.
     *
     * Usage is counted per store in PHP, so it is memoized for the request.
     *
     * @return array<int, list<PlanLimit>>
     */
    private static function overLimitStores(): array
    {
        return once(function (): array {
            $overages = resolve(TenantLimitOverages::class);
            $overLimit = [];

            foreach (Store::query()->ownedBy(self::currentReseller())->get() as $store) {
                $limits = $overages->exceeded($store);

                if ($limits !== []) {
                    $overLimit[$store->id] = $limits;
                }
            }

            return $overLimit;
        });
    }
}

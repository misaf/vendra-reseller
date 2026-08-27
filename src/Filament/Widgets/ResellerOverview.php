<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Misaf\VendraReseller\Filament\Concerns\InteractsWithCurrentReseller;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraStore\Enums\StoreStatus;
use Misaf\VendraStore\Filament\Concerns\BuildsDailyTrend;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Support\StoreQuota;
use Misaf\VendraSubscription\Models\Subscription;

final class ResellerOverview extends StatsOverviewWidget
{
    use BuildsDailyTrend;
    use InteractsWithCurrentReseller;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $reseller = $this->currentReseller();

        if (null === $reseller) {
            return [];
        }

        $subscription = $reseller->activeSubscription();
        $stores = Store::query()->where('reseller_id', $reseller->getKey());
        $used = $reseller->subscribedUnitCount();
        $remaining = app(StoreQuota::class)->remainingStores($reseller);
        $active = (clone $stores)->withStatus(StoreStatus::Active)->count();
        $provisioning = (clone $stores)->withStatus(StoreStatus::Provisioning)->count();
        $failed = (clone $stores)->withStatus(StoreStatus::Failed)->count();

        $planName = __('console.no_plan');
        $max = null;

        if ($subscription instanceof Subscription && null !== $subscription->plan) {
            $planName = $subscription->plan->name;
            $max = $subscription->plan->max_units;
        }

        return [
            Stat::make(__('console.plan'), $planName)
                ->icon(Heroicon::OutlinedRectangleStack)
                ->color($subscription instanceof Subscription ? 'primary' : 'gray'),
            Stat::make(__('console.stores'), null === $max ? (string) $used : "{$used} / {$max}")
                ->description(__('console.remaining_stores') . ': ' . $remaining)
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->color(null !== $max && $used >= $max ? 'danger' : 'primary')
                ->url(StoreResource::getUrl('index'))
                ->chart($this->dailyTrend(Store::query()->where('reseller_id', $reseller->getKey()))),
            Stat::make(__('console.active_stores'), $active)
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->url(self::storeStatusUrl(StoreStatus::Active)),
            Stat::make(__('console.provisioning'), $provisioning)
                ->icon(Heroicon::OutlinedArrowPath)
                ->color($provisioning > 0 ? 'warning' : 'gray')
                ->url(self::storeStatusUrl(StoreStatus::Provisioning)),
            Stat::make(__('console.failed_stores'), $failed)
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($failed > 0 ? 'danger' : 'gray')
                ->url(self::storeStatusUrl(StoreStatus::Failed)),
        ];
    }

    private static function storeStatusUrl(StoreStatus $status): string
    {
        return StoreResource::getUrl('index', [
            'tableFilters' => ['status' => ['value' => $status->value]],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Misaf\VendraProperty\Filament\Concerns\BuildsDailyTrend;
use Misaf\VendraReseller\Filament\Concerns\InteractsWithCurrentReseller;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraTenant\Models\Tenant;

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
        $used = $reseller->tenants()->count();

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
            Stat::make(__('console.properties'), null === $max ? (string) $used : "{$used} / {$max}")
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->chart($this->dailyTrend(Tenant::query()->where('reseller_id', $reseller->getKey()))),
        ];
    }
}

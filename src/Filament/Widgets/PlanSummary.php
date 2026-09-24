<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Misaf\VendraReseller\Filament\Concerns\InteractsWithCurrentReseller;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Enums\StoreStatus;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Support\StoreQuota;
use Misaf\VendraStore\Support\StoreStatusCounts;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;

final class PlanSummary extends StatsOverviewWidget
{
    use InteractsWithCurrentReseller;

    /**
     * The number of days before a plan's end at which it shows a warning.
     */
    private const int ENDING_SOON_DAYS = 7;

    protected function getStats(): array
    {
        $reseller = self::currentReseller();

        if (! $reseller instanceof Reseller) {
            return [];
        }

        $subscription = $reseller->activeSubscription();
        $stores = Store::query()->ownedBy($reseller);
        $counts = StoreStatusCounts::for($stores);

        return [
            $subscription instanceof Subscription
                ? self::activePlanStat($subscription)
                : self::inactivePlanStat($reseller->latestSubscription()),
            self::capacityStat($subscription, $counts->total(), resolve(StoreQuota::class)->remainingStores($reseller)),
            self::storesStat($counts, (clone $stores)->whereNotNull('billing_suspended_at')->count()),
        ];
    }

    private static function activePlanStat(Subscription $subscription): Stat
    {
        $plan = $subscription->plan;

        $stat = Stat::make(__('vendra-reseller::attributes.subscription_plan'), $plan instanceof Plan ? $plan->name : $subscription->status->getLabel())
            ->icon(Heroicon::OutlinedCheckBadge);

        if ($subscription->isOnTrial()) {
            return $stat
                ->description(__('vendra-reseller::attributes.trial_until', ['date' => $subscription->trial_ends_at?->format('Y-m-d')]))
                ->color('info');
        }

        if ($subscription->ends_at === null) {
            return $stat
                ->description(__('vendra-reseller::attributes.plan_never_ends'))
                ->color('success');
        }

        $daysLeft = (int) now()->diffInDays($subscription->ends_at);

        if ($daysLeft <= self::ENDING_SOON_DAYS) {
            return $stat
                ->description(trans_choice('vendra-reseller::attributes.plan_ends_in_days', $daysLeft, ['days' => $daysLeft]))
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('warning');
        }

        return $stat
            ->description(__('vendra-reseller::attributes.renews_on').': '.$subscription->ends_at->format('Y-m-d'))
            ->color('success');
    }

    private static function inactivePlanStat(?Subscription $latest): Stat
    {
        return Stat::make(
            __('vendra-reseller::attributes.subscription_plan'),
            $latest instanceof Subscription ? $latest->status->getLabel() : __('vendra-reseller::attributes.no_plan'),
        )
            ->description(__('vendra-reseller::attributes.subscribe_to_create_stores'))
            ->icon(Heroicon::OutlinedExclamationCircle)
            ->color(match ($latest?->status) {
                SubscriptionStatus::PastDue, SubscriptionStatus::Expired => 'danger',
                SubscriptionStatus::PendingPayment => 'warning',
                default => 'gray',
            });
    }

    private static function capacityStat(?Subscription $subscription, int $used, int $remaining): Stat
    {
        $stat = Stat::make(__('vendra-reseller::attributes.store_capacity'), (string) $used)
            ->icon(Heroicon::OutlinedRectangleStack)
            ->url(StoreResource::getUrl('index'));

        if (! $subscription instanceof Subscription) {
            return $stat
                ->description(__('vendra-reseller::attributes.capacity_requires_plan'))
                ->color('gray');
        }

        return $stat
            ->value($used.' / '.($subscription->plan instanceof Plan ? $subscription->plan->max_units : 0))
            ->description(__('vendra-reseller::attributes.remaining_stores').': '.$remaining)
            ->color($remaining === 0 ? 'danger' : 'primary');
    }

    private static function storesStat(StoreStatusCounts $counts, int $billingSuspended): Stat
    {
        $stat = Stat::make(__('vendra-reseller::attributes.active_stores'), $counts->count(StoreStatus::Active))
            ->icon(Heroicon::OutlinedBuildingStorefront)
            ->url(StoreResource::getUrl('index', [
                'tableFilters' => ['status' => ['value' => StoreStatus::Active->value]],
            ]));

        if ($billingSuspended > 0) {
            return $stat
                ->description(trans_choice('vendra-reseller::attributes.stores_suspended_for_billing', $billingSuspended, ['count' => $billingSuspended]))
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger');
        }

        return $stat
            ->description(__('vendra-reseller::attributes.stores_total', ['count' => $counts->total()]))
            ->color('success');
    }
}

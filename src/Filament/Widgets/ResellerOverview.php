<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Misaf\VendraReseller\Filament\Concerns\InteractsWithCurrentReseller;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraStore\Enums\StorefrontDeploymentStatus;
use Misaf\VendraStore\Enums\StoreStatus;
use Misaf\VendraStore\Filament\Concerns\BuildsDailyTrend;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Support\StoreQuota;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Models\Subscription;

final class ResellerOverview extends StatsOverviewWidget
{
    use BuildsDailyTrend;
    use InteractsWithCurrentReseller;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $reseller = $this->currentReseller();

        if ($reseller === null) {
            return [];
        }

        $subscription = $reseller->activeSubscription();
        $stores = Store::query()->where('reseller_id', $reseller->getKey());
        $used = $reseller->subscribedUnitCount();
        $remaining = app(StoreQuota::class)->remainingStores($reseller);
        $active = (clone $stores)->withStatus(StoreStatus::Active)->count();
        $provisioning = (clone $stores)->withStatus(StoreStatus::Provisioning)->count()
            + (clone $stores)->withStatus(StoreStatus::Pending)->count();
        $failed = (clone $stores)->withStatus(StoreStatus::Failed)->count();
        $needsAttention = $provisioning + $failed;

        $hasReadyStorefront = (clone $stores)->whereHas(
            'storefrontDeployments',
            fn ($query) => $query->where('status', StorefrontDeploymentStatus::Ready),
        )->exists();

        return [
            Stat::make(__('console.subscription_summary'), self::subscriptionLabel($subscription))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color(self::subscriptionColor($subscription)),

            Stat::make(__('console.store_capacity'), "{$used} / ".($remaining + $used))
                ->description(__('console.remaining_stores').': '.$remaining)
                ->icon(Heroicon::OutlinedRectangleStack)
                ->color($remaining <= 0 ? 'danger' : 'primary')
                ->url(StoreResource::getUrl('index')),

            Stat::make(__('console.active_stores'), $active)
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->url(self::storeStatusUrl(StoreStatus::Active)),

            Stat::make(__('console.stores_needing_attention'), $needsAttention)
                ->description(__('console.stores_provisioning').': '.$provisioning.' · '.__('console.failed_stores').': '.$failed)
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($needsAttention > 0 ? 'danger' : 'gray')
                ->url(StoreResource::getUrl('index', [
                    'tableFilters' => [
                        'status' => ['value' => StoreStatus::Failed->value],
                    ],
                ])),

            Stat::make(__('console.storefronts_ready'), $hasReadyStorefront ? __('console.yes') : __('console.no'))
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->color($hasReadyStorefront ? 'success' : 'gray')
                ->url(StoreResource::getUrl('index', [
                    'tableFilters' => [
                        'storefront_status' => ['value' => StorefrontDeploymentStatus::Ready->value],
                    ],
                ])),
        ];
    }

    private static function subscriptionLabel(?Subscription $subscription): string
    {
        if (! $subscription instanceof Subscription) {
            return __('console.no_active_subscription');
        }

        if ($subscription->status === SubscriptionStatus::Active) {
            if ($subscription->isOnTrial()) {
                return __('console.trial_until', ['date' => $subscription->trial_ends_at?->format('Y-m-d') ?? '']);
            }

            return $subscription->plan?->name ?? __('console.status_active');
        }

        return __("console.status_{$subscription->status->value}");
    }

    private static function subscriptionColor(?Subscription $subscription): string
    {
        if (! $subscription instanceof Subscription) {
            return 'gray';
        }

        return match ($subscription->status) {
            SubscriptionStatus::Active => $subscription->isOnTrial() ? 'info' : 'success',
            SubscriptionStatus::PendingPayment => 'warning',
            SubscriptionStatus::PastDue,
            SubscriptionStatus::Expired => 'danger',
            SubscriptionStatus::Cancelled => 'gray',
        };
    }

    private static function storeStatusUrl(StoreStatus $status): string
    {
        return StoreResource::getUrl('index', [
            'tableFilters' => ['status' => ['value' => $status->value]],
        ]);
    }
}

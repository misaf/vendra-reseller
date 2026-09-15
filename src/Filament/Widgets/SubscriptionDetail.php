<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Misaf\VendraReseller\Filament\Concerns\InteractsWithCurrentReseller;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Models\Subscription;

final class SubscriptionDetail extends StatsOverviewWidget
{
    use InteractsWithCurrentReseller;

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        $reseller = (new self)->currentReseller();

        return $reseller !== null && $reseller->stores()->exists();
    }

    protected function getStats(): array
    {
        $reseller = $this->currentReseller();
        $subscription = $reseller?->activeSubscription();

        if (! $subscription instanceof Subscription) {
            $latestSubscription = $reseller?->subscriptions()->latest('starts_at')->first();

            if ($latestSubscription instanceof Subscription) {
                return [
                    Stat::make(__('vendra-reseller::attributes.subscription_status'), __("vendra-reseller::attributes.status_{$latestSubscription->status->value}"))
                        ->icon(Heroicon::OutlinedCheckBadge)
                        ->color($this->statusColor($latestSubscription->status))
                        ->description(__('vendra-reseller::attributes.ends_at').': '.($latestSubscription->ends_at?->format('Y-m-d') ?? __('vendra-reseller::attributes.never'))),
                ];
            }

            return [
                Stat::make(__('vendra-reseller::attributes.subscription_status'), __('vendra-reseller::attributes.no_active_subscription'))
                    ->icon(Heroicon::OutlinedExclamationCircle)
                    ->color('gray'),
            ];
        }

        return [
            Stat::make(__('vendra-reseller::attributes.subscription_status'), __("vendra-reseller::attributes.status_{$subscription->status->value}"))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color($this->statusColor($subscription->status)),

            Stat::make(__('vendra-reseller::attributes.trial'), $subscription->isOnTrial() && $subscription->trial_ends_at !== null
                ? __('vendra-reseller::attributes.trial_until', ['date' => $subscription->trial_ends_at->format('Y-m-d')])
                : __('vendra-reseller::attributes.no_trial'))
                ->icon(Heroicon::OutlinedClock)
                ->color($subscription->isOnTrial() ? 'info' : 'gray'),

            Stat::make(__('vendra-reseller::attributes.renews_on'), $subscription->ends_at?->format('Y-m-d') ?? __('vendra-reseller::attributes.never'))
                ->icon(Heroicon::OutlinedCalendarDays),
        ];
    }

    private function statusColor(SubscriptionStatus $status): string
    {
        return match ($status) {
            SubscriptionStatus::Active => 'success',
            SubscriptionStatus::PendingPayment => 'warning',
            SubscriptionStatus::PastDue,
            SubscriptionStatus::Expired => 'danger',
            SubscriptionStatus::Cancelled => 'gray',
        };
    }
}

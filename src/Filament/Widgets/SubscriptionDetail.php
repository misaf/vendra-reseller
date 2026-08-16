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
        return (new self())->currentReseller()?->activeSubscription() instanceof Subscription;
    }

    protected function getStats(): array
    {
        $subscription = $this->currentReseller()?->activeSubscription();

        if ( ! $subscription instanceof Subscription) {
            return [];
        }

        return [
            Stat::make(__('console.subscription_status'), __("console.status_{$subscription->status->value}"))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color($this->statusColor($subscription->status)),
            Stat::make(__('console.trial'), $subscription->isOnTrial() && null !== $subscription->trial_ends_at
                ? __('console.trial_until', ['date' => $subscription->trial_ends_at->format('Y-m-d')])
                : __('console.no_trial'))
                ->icon(Heroicon::OutlinedClock)
                ->color($subscription->isOnTrial() ? 'info' : 'gray'),
            Stat::make(__('console.renews_on'), $subscription->ends_at?->format('Y-m-d') ?? __('console.never'))
                ->icon(Heroicon::OutlinedCalendarDays),
        ];
    }

    private function statusColor(SubscriptionStatus $status): string
    {
        return match ($status) {
            SubscriptionStatus::Active         => 'success',
            SubscriptionStatus::PendingPayment => 'warning',
            SubscriptionStatus::PastDue,
            SubscriptionStatus::Expired       => 'danger',
            SubscriptionStatus::Cancelled     => 'gray',
        };
    }
}

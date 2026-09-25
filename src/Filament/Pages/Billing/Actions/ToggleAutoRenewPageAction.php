<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Pages\Billing\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Misaf\VendraReseller\Filament\Pages\Billing\Actions\Concerns\InteractsWithResellerBilling;
use Misaf\VendraSubscription\Actions\SetSubscriptionAutoRenewAction;
use Misaf\VendraSubscription\Models\Subscription;

final class ToggleAutoRenewPageAction extends Action
{
    use InteractsWithResellerBilling;

    public static function getDefaultName(): string
    {
        return 'toggleAutoRenew';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(fn (): string => self::subscription()?->auto_renews === true
                ? __('vendra-reseller::attributes.turn_off_auto_renew')
                : __('vendra-reseller::attributes.turn_on_auto_renew'))
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->color('gray')
            ->visible(fn (): bool => self::subscription()?->ends_at !== null)
            ->requiresConfirmation(fn (): bool => self::subscription()?->auto_renews === true)
            ->modalDescription(__('vendra-reseller::attributes.turn_off_auto_renew_description'))
            ->action(function (): void {
                $subscription = self::subscription();

                if (! $subscription instanceof Subscription) {
                    return;
                }

                resolve(SetSubscriptionAutoRenewAction::class)->execute($subscription, ! $subscription->auto_renews);

                Notification::make()->success()->title($subscription->auto_renews
                    ? __('vendra-reseller::attributes.auto_renew_turned_on')
                    : __('vendra-reseller::attributes.auto_renew_turned_off'))->send();
            });
    }

    private static function subscription(): ?Subscription
    {
        return self::currentReseller()?->activeSubscription();
    }
}

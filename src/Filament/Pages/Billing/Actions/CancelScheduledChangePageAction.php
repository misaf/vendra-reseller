<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Pages\Billing\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Misaf\VendraReseller\Filament\Pages\Billing\Actions\Concerns\InteractsWithResellerBilling;
use Misaf\VendraSubscription\Actions\ChangeSubscriptionPlanAction;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;

final class CancelScheduledChangePageAction extends Action
{
    use InteractsWithResellerBilling;

    public static function getDefaultName(): string
    {
        return 'cancelScheduledChange';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('vendra-reseller::attributes.cancel_scheduled_change'))
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->visible(fn (): bool => self::currentReseller()?->activeSubscription()?->scheduled_plan_id !== null)
            ->requiresConfirmation()
            ->action(function (): void {
                $reseller = self::reseller();
                $subscription = $reseller->activeSubscription();

                if (! $subscription instanceof Subscription || ! $subscription->plan instanceof Plan) {
                    return;
                }

                resolve(ChangeSubscriptionPlanAction::class)->execute($reseller, $subscription->plan);

                Notification::make()->success()->title(__('vendra-reseller::attributes.scheduled_change_cancelled'))->send();
            });
    }
}

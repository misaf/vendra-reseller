<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Pages\Billing\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Misaf\VendraReseller\Filament\Pages\Billing\Actions\Concerns\InteractsWithResellerBilling;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Actions\RenewSubscriptionAction;
use Misaf\VendraSubscription\Exceptions\SubscriptionLimitException;
use Misaf\VendraSubscription\Exceptions\SubscriptionPaymentException;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;

final class RenewPageAction extends Action
{
    use InteractsWithResellerBilling;

    public static function getDefaultName(): string
    {
        return 'renew';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('vendra-reseller::attributes.renew'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (): bool => self::renewable(self::currentReseller()) instanceof Subscription)
            ->requiresConfirmation()
            ->modalDescription(fn (): ?string => self::renewable(self::currentReseller())?->plan?->formattedPrice())
            ->action(function (): void {
                $reseller = self::reseller();
                $subscription = self::renewable($reseller);

                if (! $subscription instanceof Subscription) {
                    return;
                }

                $plan = $subscription->scheduledPlan ?? $subscription->plan;

                if ($plan instanceof Plan) {
                    self::ensureWalletCovers($reseller, $plan->price, $plan->currency_code);
                }

                try {
                    resolve(RenewSubscriptionAction::class)->execute($subscription);
                } catch (SubscriptionLimitException|SubscriptionPaymentException $exception) {
                    Notification::make()->danger()->title(__('vendra-reseller::attributes.renewal_failed'))->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('vendra-reseller::attributes.renewal_requested'))->send();
            });
    }

    /**
     * The period to renew, when the reseller can still hold its stores.
     */
    private static function renewable(?Reseller $reseller): ?Subscription
    {
        if (! $reseller instanceof Reseller || ! $reseller->canHoldUnits()) {
            return null;
        }

        return $reseller->renewableSubscription();
    }
}

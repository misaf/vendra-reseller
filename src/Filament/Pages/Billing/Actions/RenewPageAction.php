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
use Misaf\VendraSubscription\Support\PlanCoverage;

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
            ->modalDescription(fn (): ?string => self::describe(self::renewable(self::currentReseller())))
            ->action(function (): void {
                $reseller = self::reseller();
                $subscription = self::renewable($reseller);

                if (! $subscription instanceof Subscription) {
                    return;
                }

                $plan = resolve(PlanCoverage::class)->renewalPlan($subscription);

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

    private static function describe(?Subscription $subscription): ?string
    {
        if (! $subscription instanceof Subscription) {
            return null;
        }

        $planCoverage = resolve(PlanCoverage::class);
        $price = $planCoverage->renewalPlan($subscription)?->formattedPrice();

        if (! $planCoverage->scheduledPlanOutgrown($subscription)) {
            return $price;
        }

        return implode(' ', array_filter([$price, __('vendra-reseller::attributes.scheduled_plan_outgrown', [
            'plan' => $subscription->scheduledPlan?->name,
            'current' => $subscription->plan?->name,
        ])]));
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

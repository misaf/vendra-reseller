<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Pages\Billing\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Misaf\VendraReseller\Filament\Pages\Billing\Actions\Concerns\InteractsWithResellerBilling;
use Misaf\VendraSubscription\Actions\ChangeSubscriptionPlanAction;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Exceptions\SubscriptionLimitException;
use Misaf\VendraSubscription\Exceptions\SubscriptionPaymentException;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSubscription\Support\MoneyFormatter;
use Misaf\VendraSubscription\Support\PlanChangeQuote;

final class ChangePlanPageAction extends Action
{
    use InteractsWithResellerBilling;

    public static function getDefaultName(): string
    {
        return 'changePlan';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('vendra-reseller::attributes.change_plan'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn (): bool => self::currentReseller()?->canHoldUnits() === true)
            ->schema([
                Select::make('plan_id')
                    ->label(__('vendra-reseller::attributes.subscription_plan'))
                    ->options(fn (): array => self::planOptions(self::reseller()->activeSubscription()))
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data): void {
                $reseller = self::reseller();
                $current = $reseller->activeSubscription();
                $plan = Plan::query()->active()->findOrFail(Arr::integer($data, 'plan_id'));
                $quote = PlanChangeQuote::for($current, $plan);

                if ($quote->appliesNow) {
                    self::ensureWalletCovers($reseller, $quote->amount ?? $plan->price, $plan->currency_code);
                }

                try {
                    $subscription = resolve(ChangeSubscriptionPlanAction::class)->execute($reseller, $plan);
                } catch (SubscriptionLimitException|SubscriptionPaymentException $exception) {
                    Notification::make()->danger()->title(__('vendra-reseller::attributes.plan_change_failed'))->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(self::outcome($subscription, $plan))->send();
            });
    }

    /**
     * @return array<int, string>
     */
    private static function planOptions(?Subscription $current): array
    {
        return Plan::query()
            ->active()
            ->when($current instanceof Subscription, fn ($query) => $query->whereKeyNot($current?->plan_id))
            ->orderBy('price')
            ->get()
            ->mapWithKeys(fn (Plan $plan): array => [$plan->id => self::describe($plan, $current)])
            ->all();
    }

    private static function describe(Plan $plan, ?Subscription $current): string
    {
        $quote = PlanChangeQuote::for($current, $plan);
        $label = "{$plan->name} · {$plan->formattedPrice()}";

        if (! $quote->appliesNow) {
            return $label.' · '.__('vendra-reseller::attributes.plan_change_from', ['date' => $current?->ends_at?->format('Y-m-d')]);
        }

        if ($quote->isProrated()) {
            return $label.' · '.__('vendra-reseller::attributes.plan_change_prorated', ['amount' => MoneyFormatter::format($quote->amount, $plan->currency_code)]);
        }

        return $label;
    }

    private static function outcome(Subscription $subscription, Plan $plan): string
    {
        if ($subscription->scheduled_plan_id === $plan->id) {
            return __('vendra-reseller::attributes.plan_change_scheduled', ['plan' => $plan->name]);
        }

        if ($subscription->status === SubscriptionStatus::PendingPayment) {
            return __('vendra-reseller::attributes.plan_change_pending_payment', ['plan' => $plan->name]);
        }

        return __('vendra-reseller::attributes.plan_changed', ['plan' => $plan->name]);
    }
}

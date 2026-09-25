<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Pages\Billing\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Misaf\VendraReseller\Filament\Pages\Billing\Actions\Concerns\InteractsWithResellerBilling;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Actions\ChangeSubscriptionPlanAction;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Exceptions\SubscriptionLimitException;
use Misaf\VendraSubscription\Exceptions\SubscriptionPaymentException;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSubscription\Support\MoneyFormatter;
use Misaf\VendraSubscription\Support\PlanChangeQuote;
use Misaf\VendraSubscription\Support\PlanCoverage;
use Misaf\VendraSubscription\Support\TaxedAmount;
use Misaf\VendraSupport\Enums\PlanFeature;
use Misaf\VendraSupport\Enums\PlanLimit;

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
                    ->options(fn (): array => self::planOptions(self::reseller()))
                    ->disableOptionWhen(fn (string $value): bool => ! self::planFits(self::reseller(), (int) $value))
                    ->helperText(fn (?string $state): ?string => filled($state) ? self::entitlements(Plan::query()->find((int) $state)) : null)
                    ->live()
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
    private static function planOptions(Reseller $reseller): array
    {
        $current = $reseller->activeSubscription();

        return Plan::query()
            ->active()
            ->when($current instanceof Subscription, fn ($query) => $query->whereKeyNot($current?->plan_id))
            ->orderBy('price')
            ->get()
            ->mapWithKeys(fn (Plan $plan): array => [$plan->id => self::describe($reseller, $plan, $current)])
            ->all();
    }

    private static function planFits(Reseller $reseller, int $planId): bool
    {
        $plan = Plan::query()->find($planId);

        return $plan instanceof Plan && resolve(PlanCoverage::class)->covers($reseller, $plan);
    }

    private static function describe(Reseller $reseller, Plan $plan, ?Subscription $current): string
    {
        $label = "{$plan->name} · {$plan->formattedPrice()}";

        if (! resolve(PlanCoverage::class)->covers($reseller, $plan)) {
            return $label.' · '.__('vendra-reseller::attributes.plan_outgrown');
        }

        $quote = PlanChangeQuote::for($current, $plan);

        if (! $quote->appliesNow) {
            return $label.' · '.__('vendra-reseller::attributes.plan_change_from', ['date' => $current?->ends_at?->format('Y-m-d')]);
        }

        if ($quote->isProrated()) {
            return $label.' · '.__('vendra-reseller::attributes.plan_change_prorated', ['amount' => MoneyFormatter::format(TaxedAmount::withProfileTax($quote->amount ?? 0)->total, $plan->currency_code)]);
        }

        return $label;
    }

    /**
     * Summarise what a plan includes, so resellers can compare plans before choosing.
     */
    private static function entitlements(?Plan $plan): ?string
    {
        if (! $plan instanceof Plan) {
            return null;
        }

        $items = [trans_choice('vendra-reseller::attributes.plan_includes_stores', $plan->max_units, ['count' => $plan->max_units])];

        foreach (PlanLimit::cases() as $limit) {
            $allowed = $plan->limit($limit->value);

            $items[] = $allowed === null
                ? __('vendra-reseller::attributes.plan_limit_unlimited', ['limit' => $limit->getLabel()])
                : __('vendra-reseller::attributes.plan_limit_value', ['limit' => $limit->getLabel(), 'value' => $allowed]);
        }

        foreach (PlanFeature::cases() as $feature) {
            if ($plan->allows($feature->value)) {
                $items[] = $feature->getLabel();
            }
        }

        return __('vendra-reseller::attributes.plan_includes', ['items' => implode(' · ', $items)]);
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

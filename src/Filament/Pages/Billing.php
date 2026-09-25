<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Misaf\VendraReseller\Filament\Concerns\InteractsWithCurrentReseller;
use Misaf\VendraReseller\Filament\Pages\Billing\Actions\CancelScheduledChangePageAction;
use Misaf\VendraReseller\Filament\Pages\Billing\Actions\ChangePlanPageAction;
use Misaf\VendraReseller\Filament\Pages\Billing\Actions\EditBillingDetailsPageAction;
use Misaf\VendraReseller\Filament\Pages\Billing\Actions\RenewPageAction;
use Misaf\VendraReseller\Filament\Pages\Billing\Actions\ToggleAutoRenewPageAction;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSubscription\Models\SubscriptionPayment;
use Misaf\VendraSubscription\Support\MoneyFormatter;
use Misaf\VendraSubscription\Support\PlanCoverage;

final class Billing extends Page
{
    use InteractsWithCurrentReseller;

    private const int RECENT_PAYMENTS = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('vendra-reseller::navigation.billing');
    }

    public function getTitle(): string
    {
        return __('vendra-reseller::navigation.billing');
    }

    public static function canAccess(): bool
    {
        return self::currentReseller() instanceof Reseller;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('vendra-reseller::attributes.subscription'))
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('plan')
                            ->label(__('vendra-reseller::attributes.subscription_plan'))
                            ->state(fn (): ?string => self::displayedSubscription()?->plan?->name)
                            ->placeholder(__('vendra-reseller::attributes.no_plan')),
                        TextEntry::make('status')
                            ->label(__('vendra-reseller::attributes.subscription_status'))
                            ->badge()
                            ->state(fn (): ?SubscriptionStatus => self::displayedSubscription()?->status)
                            ->placeholder('—'),
                        TextEntry::make('ends_at')
                            ->label(__('vendra-reseller::attributes.renews_on'))
                            ->state(fn (): ?string => self::displayedSubscription()?->ends_at?->format('Y-m-d'))
                            ->placeholder('—'),
                        IconEntry::make('auto_renews')
                            ->label(__('vendra-reseller::attributes.auto_renews'))
                            ->boolean()
                            ->state(fn (): ?bool => self::displayedSubscription()?->auto_renews),
                        TextEntry::make('scheduled_plan')
                            ->label(__('vendra-reseller::attributes.scheduled_plan'))
                            ->state(fn (): ?string => self::scheduledChange())
                            ->color(fn (): ?string => self::scheduledPlanOutgrown() ? 'danger' : null)
                            ->placeholder('—'),
                    ]),
                ])
                ->columnSpanFull(),
            Section::make(__('vendra-reseller::attributes.wallet'))
                ->description(__('vendra-reseller::attributes.wallet_top_up_hint'))
                ->schema([
                    TextEntry::make('wallet_balances')
                        ->label(__('vendra-reseller::attributes.wallet_balance'))
                        ->state(fn (): array => self::currentReseller()?->formattedWalletBalances() ?? [])
                        ->listWithLineBreaks()
                        ->placeholder(MoneyFormatter::format(0, self::displayedSubscription()?->currency_code)),
                ])
                ->columnSpanFull(),
            Section::make(__('vendra-reseller::attributes.recent_payments'))
                ->schema([
                    TextEntry::make('recent_payments')
                        ->hiddenLabel()
                        ->state(fn (): array => self::recentPayments())
                        ->listWithLineBreaks()
                        ->placeholder(__('vendra-reseller::attributes.no_payments')),
                ])
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ChangePlanPageAction::make(),
            EditBillingDetailsPageAction::make(),
            RenewPageAction::make(),
            ToggleAutoRenewPageAction::make(),
            CancelScheduledChangePageAction::make(),
        ];
    }

    /**
     * The running period, or the last one that was live when none is.
     */
    private static function displayedSubscription(): ?Subscription
    {
        $reseller = self::currentReseller();

        return $reseller?->activeSubscription() ?? $reseller?->latestActivatedSubscription();
    }

    private static function scheduledChange(): ?string
    {
        $subscription = self::currentReseller()?->activeSubscription();
        $plan = $subscription?->scheduledPlan;

        if (! $subscription instanceof Subscription || $plan === null || $subscription->ends_at === null) {
            return null;
        }

        if (self::scheduledPlanOutgrown()) {
            return __('vendra-reseller::attributes.scheduled_plan_outgrown', [
                'plan' => $plan->name,
                'current' => $subscription->plan?->name,
            ]);
        }

        return __('vendra-reseller::attributes.scheduled_plan_from', [
            'plan' => $plan->name,
            'date' => $subscription->ends_at->format('Y-m-d'),
        ]);
    }

    private static function scheduledPlanOutgrown(): bool
    {
        $subscription = self::currentReseller()?->activeSubscription();

        return $subscription instanceof Subscription && resolve(PlanCoverage::class)->scheduledPlanOutgrown($subscription);
    }

    /**
     * @return list<string>
     */
    private static function recentPayments(): array
    {
        $reseller = self::currentReseller();

        if (! $reseller instanceof Reseller) {
            return [];
        }

        return array_values(SubscriptionPayment::query()
            ->whereIn('subscription_id', $reseller->subscriptions()->select('id'))
            ->with('invoice')
            ->latest()
            ->limit(self::RECENT_PAYMENTS)
            ->get()
            ->map(fn (SubscriptionPayment $payment): string => implode(' · ', array_filter([
                $payment->created_at->format('Y-m-d'),
                MoneyFormatter::format($payment->amount, $payment->currency_code),
                $payment->status->getLabel(),
                $payment->invoice?->number,
            ])))
            ->all());
    }
}

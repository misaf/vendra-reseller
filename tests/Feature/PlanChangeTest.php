<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Misaf\VendraReseller\Actions\CreditResellerWalletAction;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Notifications\ScheduledPlanChangeDroppedNotification;
use Misaf\VendraSubscription\Actions\ChangeSubscriptionPlanAction;
use Misaf\VendraSubscription\Actions\ChargeSubscriptionAction;
use Misaf\VendraSubscription\Actions\EnforceSubscriptionsAction;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Exceptions\SubscriptionLimitException;
use Misaf\VendraSubscription\Jobs\ProcessSubscriptionPayment;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSubscription\Models\SubscriptionPayment;
use Misaf\VendraTransaction\Database\Factories\TransactionGatewayFactory;
use Misaf\VendraTransaction\Facades\WalletResolver;
use Misaf\VendraUser\Models\User;

beforeEach(function (): void {
    Queue::fake();
    TransactionGatewayFactory::new()->active()->internal()->create();
    $this->travelTo(Date::parse('2026-04-11 00:00:00'));
});

function planChangeReseller(): Reseller
{
    $reseller = Reseller::factory()->active()->create();
    $reseller->user()->associate(User::factory()->create(['tenant_id' => null]))->save();

    return $reseller;
}

function subscribePlanChangeReseller(Reseller $reseller, Plan $plan, array $attributes = []): Subscription
{
    return Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
        'price' => $plan->price,
        'currency_code' => $plan->currency_code,
        'starts_at' => Date::parse('2026-04-01 00:00:00'),
        'ends_at' => Date::parse('2026-05-01 00:00:00'),
        'activated_at' => Date::parse('2026-04-01 00:00:00'),
        ...$attributes,
    ]);
}

function collectPlanChangePayment(SubscriptionPayment $payment): void
{
    new ProcessSubscriptionPayment($payment->getKey())->handle(resolve(ChargeSubscriptionAction::class));
}

describe('changing plan', function (): void {
    it('upgrades now on the current billing anchor and collects only the prorated difference', function (): void {
        $reseller = planChangeReseller();
        $current = subscribePlanChangeReseller($reseller, Plan::factory()->active()->priced(3_000)->maxUnits(1)->create());
        resolve(CreditResellerWalletAction::class)->execute($reseller, 5_000, 'USD', 'Bank transfer');

        $upgraded = resolve(ChangeSubscriptionPlanAction::class)->execute($reseller, Plan::factory()->active()->priced(6_000)->maxUnits(5)->create());
        $payment = $upgraded->payments()->sole();

        expect($upgraded->status)->toBe(SubscriptionStatus::PendingPayment)
            ->and($upgraded->ends_at?->equalTo($current->ends_at))->toBeTrue()
            ->and($payment->amount)->toBe(2_000);

        collectPlanChangePayment($payment);

        expect($upgraded->refresh()->status)->toBe(SubscriptionStatus::Active)
            ->and($current->refresh()->status)->toBe(SubscriptionStatus::Cancelled)
            ->and(WalletResolver::firstOrCreateWalletFor($reseller->user, 'USD')->balance)->toBe(3_000);
    });

    it('starts a full period when upgrading from a free plan', function (): void {
        $reseller = planChangeReseller();
        subscribePlanChangeReseller($reseller, Plan::factory()->active()->maxUnits(1)->create());

        $upgraded = resolve(ChangeSubscriptionPlanAction::class)->execute($reseller, Plan::factory()->active()->priced(6_000)->maxUnits(5)->create());

        expect($upgraded->ends_at?->equalTo(Date::parse('2026-05-11 00:00:00')))->toBeTrue()
            ->and($upgraded->payments()->sole()->amount)->toBe(6_000);
    });

    it('schedules a downgrade for the next renewal and leaves the current period alone', function (): void {
        $reseller = planChangeReseller();
        $current = subscribePlanChangeReseller($reseller, Plan::factory()->active()->priced(6_000)->maxUnits(5)->create());
        $cheaper = Plan::factory()->active()->priced(3_000)->maxUnits(1)->create();

        $result = resolve(ChangeSubscriptionPlanAction::class)->execute($reseller, $cheaper);

        expect($result->is($current))->toBeTrue()
            ->and($current->refresh()->scheduled_plan_id)->toBe($cheaper->id)
            ->and($current->status)->toBe(SubscriptionStatus::Active)
            ->and($reseller->subscriptions()->count())->toBe(1);
    });

    it('refuses a downgrade the reseller has outgrown', function (): void {
        $reseller = planChangeReseller();
        $current = subscribePlanChangeReseller($reseller, Plan::factory()->active()->priced(6_000)->maxUnits(5)->create());
        createTestTenant(['reseller_id' => $reseller->getKey()]);
        createTestTenant(['reseller_id' => $reseller->getKey()]);

        expect(fn () => resolve(ChangeSubscriptionPlanAction::class)->execute($reseller, Plan::factory()->active()->priced(3_000)->maxUnits(1)->create()))
            ->toThrow(SubscriptionLimitException::class)
            ->and($current->refresh()->scheduled_plan_id)->toBeNull();
    });

    it('drops a scheduled downgrade when the current plan is chosen again', function (): void {
        $reseller = planChangeReseller();
        $plan = Plan::factory()->active()->priced(6_000)->maxUnits(5)->create();
        $current = subscribePlanChangeReseller($reseller, $plan, [
            'scheduled_plan_id' => Plan::factory()->active()->priced(3_000)->create()->id,
        ]);

        resolve(ChangeSubscriptionPlanAction::class)->execute($reseller, $plan);

        expect($current->refresh()->scheduled_plan_id)->toBeNull();
    });
});

describe('auto-renewal', function (): void {
    it('renews a lapsed period onto the scheduled plan from where it ended', function (): void {
        $reseller = planChangeReseller();
        $scheduled = Plan::factory()->active()->priced(3_000)->maxUnits(1)->create();
        $current = subscribePlanChangeReseller($reseller, Plan::factory()->active()->priced(6_000)->maxUnits(5)->graceDays(3)->create(), [
            'ends_at' => Date::parse('2026-04-10 23:00:00'),
            'scheduled_plan_id' => $scheduled->id,
        ]);
        resolve(CreditResellerWalletAction::class)->execute($reseller, 5_000, 'USD', 'Bank transfer');

        $result = resolve(EnforceSubscriptionsAction::class)->execute();
        $renewal = $reseller->subscriptions()->whereKeyNot($current->id)->sole();
        collectPlanChangePayment($renewal->payments()->sole());

        expect(Arr::get($result, 'renewed'))->toBe(1)
            ->and($renewal->refresh()->plan_id)->toBe($scheduled->id)
            ->and($renewal->status)->toBe(SubscriptionStatus::Active)
            ->and($renewal->starts_at->equalTo($current->ends_at))->toBeTrue()
            ->and(WalletResolver::firstOrCreateWalletFor($reseller->user, 'USD')->balance)->toBe(2_000);
    });

    it('renews on the current plan and tells the reseller when the stores outgrew the scheduled one', function (): void {
        Notification::fake();
        $reseller = planChangeReseller();
        $plan = Plan::factory()->active()->priced(6_000)->maxUnits(5)->graceDays(3)->create();
        $current = subscribePlanChangeReseller($reseller, $plan, [
            'ends_at' => Date::parse('2026-04-10 23:00:00'),
            'scheduled_plan_id' => Plan::factory()->active()->priced(3_000)->maxUnits(1)->create()->id,
        ]);
        createTestTenant(['reseller_id' => $reseller->getKey()]);
        createTestTenant(['reseller_id' => $reseller->getKey()]);
        resolve(CreditResellerWalletAction::class)->execute($reseller, 10_000, 'USD', 'Bank transfer');

        $result = resolve(EnforceSubscriptionsAction::class)->execute();

        expect(Arr::get($result, 'renewed'))->toBe(1)
            ->and($reseller->subscriptions()->whereKeyNot($current->id)->sole()->plan_id)->toBe($plan->id)
            ->and($current->refresh()->scheduled_plan_id)->toBeNull();
        Notification::assertSentTo($reseller->user, ScheduledPlanChangeDroppedNotification::class);
    });

    it('still suspends the stores after grace when the renewal cannot be paid', function (): void {
        $reseller = planChangeReseller();
        subscribePlanChangeReseller($reseller, Plan::factory()->active()->priced(3_000)->graceDays(1)->create(), [
            'ends_at' => Date::parse('2026-04-09 00:00:00'),
        ]);
        $store = createTestTenant(['reseller_id' => $reseller->getKey(), 'active' => true]);

        resolve(EnforceSubscriptionsAction::class)->execute();
        collectPlanChangePayment(SubscriptionPayment::query()->sole());
        resolve(EnforceSubscriptionsAction::class)->execute();

        expect(SubscriptionPayment::query()->sole()->subscription->status)->toBe(SubscriptionStatus::Cancelled)
            ->and($store->refresh()->billing_suspended_at)->not->toBeNull();
    });

    it('renews an expired auto-renewing plan once the wallet is credited', function (): void {
        $reseller = planChangeReseller();
        $expired = subscribePlanChangeReseller($reseller, Plan::factory()->active()->priced(3_000)->graceDays(5)->create(), [
            'status' => SubscriptionStatus::Expired,
            'ends_at' => Date::parse('2026-04-09 00:00:00'),
        ]);

        resolve(CreditResellerWalletAction::class)->execute($reseller, 5_000, 'USD', 'Bank transfer');

        $renewal = $reseller->subscriptions()->whereKeyNot($expired->id)->sole();

        expect($renewal->status)->toBe(SubscriptionStatus::PendingPayment)
            ->and($renewal->starts_at->equalTo($expired->ends_at))->toBeTrue();
    });
});

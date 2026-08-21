<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Models\ResellerUser;
use Misaf\VendraReseller\Support\TransactionSubscriptionCharger;
use Misaf\VendraSubscription\Actions\ChargeSubscriptionAction;
use Misaf\VendraSubscription\Actions\SubscribeAction;
use Misaf\VendraSubscription\Context\SubscriptionContextKeys;
use Misaf\VendraSubscription\Enums\SubscriptionPaymentStatus;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Exceptions\SubscriptionPaymentException;
use Misaf\VendraSubscription\Jobs\ProcessSubscriptionPayment;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSubscription\Models\SubscriptionPayment;
use Misaf\VendraSupport\Context\RequestJobContext;
use Misaf\VendraSupport\Contracts\SubscriptionCharger;
use Misaf\VendraSupport\Data\SubscriptionCharge;
use Misaf\VendraSupport\Data\SubscriptionChargeResult;
use Misaf\VendraSupport\Enums\SubscriptionChargeStatus;
use Misaf\VendraTransaction\Actions\CreateTransactionAction;
use Misaf\VendraTransaction\Database\Factories\TransactionGatewayFactory;
use Misaf\VendraTransaction\Enums\TransactionTypeEnum;
use Misaf\VendraTransaction\Facades\WalletResolver;
use Misaf\VendraTransaction\Models\Transaction;
use Misaf\VendraTransaction\Services\TransactionGatewayRegistry as TransactionGatewayRegistryClass;

function fakeSubscriptionCharger(
    SubscriptionChargeStatus $status = SubscriptionChargeStatus::Paid,
    bool $available = true,
): object {
    $charger = new class ($status, $available) implements SubscriptionCharger {
        /** @var array<int, SubscriptionCharge> */
        public array $charges = [];

        /** @var array<int, int> */
        public array $transactionLevels = [];

        /** @var array<int, RequestJobContext> */
        public array $contexts = [];

        public function __construct(
            private readonly SubscriptionChargeStatus $status,
            private readonly bool $isAvailable,
        ) {}

        public function provider(): string
        {
            return 'testing';
        }

        public function available(): bool
        {
            return $this->isAvailable;
        }

        public function charge(SubscriptionCharge $charge): SubscriptionChargeResult
        {
            $this->charges[] = $charge;
            $this->transactionLevels[] = DB::transactionLevel();
            $this->contexts[] = RequestJobContext::current();

            return new SubscriptionChargeResult(
                $this->status,
                providerReference: 'provider-payment-1',
                errorCode: SubscriptionChargeStatus::Failed === $this->status ? 'declined' : null,
            );
        }

        public function retrieve(SubscriptionCharge $charge): SubscriptionChargeResult
        {
            return $this->charge($charge);
        }
    };

    app()->instance(SubscriptionCharger::class, $charger);

    return $charger;
}

function resellerWithOwner(): Reseller
{
    $reseller = Reseller::factory()->create();
    ResellerUser::factory()->forReseller($reseller)->create();

    return $reseller;
}

function processSubscriptionPayment(SubscriptionPayment $payment): void
{
    (new ProcessSubscriptionPayment($payment->getKey()))->handle(
        app(ChargeSubscriptionAction::class),
    );
}

it('persists and processes a paid subscription without holding a database transaction during collection', function (): void {
    Queue::fake();
    $charger = fakeSubscriptionCharger();
    $reseller = resellerWithOwner();
    $oldSubscription = Subscription::factory()->forSubscriber($reseller)->create();
    $plan = Plan::factory()->priced(1_500, 'USD')->create();
    Context::add(RequestJobContext::TRACE_ID, 'outer-trace');

    $subscription = app(SubscribeAction::class)->execute($reseller, $plan);
    $payment = $subscription->payments()->sole();

    expect($subscription->status)->toBe(SubscriptionStatus::PendingPayment)
        ->and($oldSubscription->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($payment->status)->toBe(SubscriptionPaymentStatus::Pending)
        ->and($payment->provider)->toBe('testing')
        ->and($payment->idempotency_key)->toBeUuid()
        ->and(RequestJobContext::current()->traceId)->toBe('outer-trace')
        ->and(RequestJobContext::current()->metadata)->toBe([])
        ->and(RequestJobContext::current()->idempotencyKey)->toBeNull()
        ->and(Context::allHidden())->toBe([]);
    Queue::assertPushed(
        ProcessSubscriptionPayment::class,
        fn(ProcessSubscriptionPayment $job): bool => $job->paymentId === $payment->getKey(),
    );

    Context::flush();
    processSubscriptionPayment($payment);

    expect($charger->charges)->toHaveCount(1)
        ->and($charger->charges[0]->reference)->toBe($payment->idempotency_key)
        ->and($charger->contexts[0]->metadata[SubscriptionContextKeys::SUBSCRIPTION_ID])->toBe($subscription->getKey())
        ->and($charger->contexts[0]->metadata[SubscriptionContextKeys::PAYMENT_ID])->toBe($payment->getKey())
        ->and($charger->contexts[0]->idempotencyKey)->toBe($payment->idempotency_key)
        ->and($charger->contexts[0]->operation)->toBe('subscription_payment')
        ->and($charger->transactionLevels)->toBe([DB::transactionLevel()])
        ->and($payment->refresh()->status)->toBe(SubscriptionPaymentStatus::Paid)
        ->and($payment->provider_reference)->toBe('provider-payment-1')
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($oldSubscription->refresh()->status)->toBe(SubscriptionStatus::Cancelled);
});

it('collects an internal subscription payment only once and settles it before reporting paid', function (): void {
    makeCurrentTestTenant();
    TransactionGatewayFactory::new()->internal()->create();
    $payer = createTestUser();
    $wallet = WalletResolver::walletFor($payer, 'USD');
    app(CreateTransactionAction::class)->execute(
        TransactionGatewayRegistryClass::INTERNAL_GATEWAY_SLUG,
        $wallet,
        TransactionTypeEnum::Bonus,
        5_000,
    )->approve();
    $charger = app(TransactionSubscriptionCharger::class);
    $charge = new SubscriptionCharge($payer, 1_500, 'USD', 'subscription-payment-idempotency');

    $firstResult = $charger->charge($charge);
    $retriedResult = $charger->charge($charge);

    expect($firstResult->status)->toBe(SubscriptionChargeStatus::Paid)
        ->and($retriedResult->status)->toBe(SubscriptionChargeStatus::Paid)
        ->and($wallet->refresh()->balance)->toBe(3_500)
        ->and(Transaction::query()->where('idempotency_key', 'subscription-payment-idempotency')->count())->toBe(1);
});

it('does not create a payment for a free plan', function (): void {
    Queue::fake();
    fakeSubscriptionCharger();
    $reseller = resellerWithOwner();

    $subscription = app(SubscribeAction::class)->execute($reseller, Plan::factory()->create());

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->payments()->count())->toBe(0);
    Queue::assertNotPushed(ProcessSubscriptionPayment::class);
});

it('persists a paid trial payment but defers collection until the trial ends', function (): void {
    Queue::fake();
    fakeSubscriptionCharger();
    $reseller = resellerWithOwner();
    $subscription = app(SubscribeAction::class)->execute(
        $reseller,
        Plan::factory()->priced(1_500, 'USD')->trialDays(14)->create(),
    );
    $payment = $subscription->payments()->sole();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($payment->status)->toBe(SubscriptionPaymentStatus::Pending)
        ->and($payment->next_retry_at?->equalTo($subscription->trial_ends_at))->toBeTrue();
    Queue::assertNotPushed(ProcessSubscriptionPayment::class);
});

it('rejects a paid subscription when no payment provider is available', function (): void {
    Queue::fake();
    fakeSubscriptionCharger(available: false);
    $reseller = resellerWithOwner();

    expect(fn() => app(SubscribeAction::class)->execute(
        $reseller,
        Plan::factory()->priced(1_000, 'USD')->create(),
    ))->toThrow(SubscriptionPaymentException::class, 'No subscription payment provider');

    expect($reseller->subscriptions()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects a paid subscription when the reseller has no payer', function (): void {
    Queue::fake();
    fakeSubscriptionCharger();
    $reseller = Reseller::factory()->create();

    expect(fn() => app(SubscribeAction::class)->execute(
        $reseller,
        Plan::factory()->priced(1_000, 'USD')->create(),
    ))->toThrow(SubscriptionPaymentException::class, 'no payer');

    expect($reseller->subscriptions()->count())->toBe(0);
});

it('keeps the existing subscription active when payment is declined', function (): void {
    Queue::fake();
    fakeSubscriptionCharger(SubscriptionChargeStatus::Failed);
    $reseller = resellerWithOwner();
    $current = Subscription::factory()->forSubscriber($reseller)->create();
    $replacement = app(SubscribeAction::class)->execute(
        $reseller,
        Plan::factory()->priced(1_500, 'USD')->create(),
    );
    $payment = $replacement->payments()->sole();

    processSubscriptionPayment($payment);

    expect($payment->refresh()->status)->toBe(SubscriptionPaymentStatus::Failed)
        ->and($payment->failure_code)->toBe('declined')
        ->and($replacement->refresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($current->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($reseller->activeSubscription()?->is($current))->toBeTrue();
});

it('marks an active trial past due when its deferred payment is declined', function (): void {
    Queue::fake();
    fakeSubscriptionCharger(SubscriptionChargeStatus::Failed);
    $reseller = resellerWithOwner();
    $subscription = app(SubscribeAction::class)->execute(
        $reseller,
        Plan::factory()->priced(1_500, 'USD')->trialDays(14)->create(),
    );
    $subscription->update(['trial_ends_at' => now()->subMinute()]);
    $subscription->payments()->update(['next_retry_at' => now()->subMinute()]);

    processSubscriptionPayment($subscription->payments()->sole());

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($reseller->activeSubscription())->toBeNull();
});

it('rejects overlapping subscription changes while a payment is unresolved', function (): void {
    Queue::fake();
    fakeSubscriptionCharger();
    $reseller = resellerWithOwner();
    app(SubscribeAction::class)->execute(
        $reseller,
        Plan::factory()->priced(1_500, 'USD')->create(),
    );
    $reseller->subscriptions()->sole()->payments()->sole()->update([
        'status' => SubscriptionPaymentStatus::Processing,
    ]);

    expect(fn() => app(SubscribeAction::class)->execute(
        $reseller,
        Plan::factory()->create(),
    ))->toThrow(SubscriptionPaymentException::class, 'already in progress');

    expect($reseller->subscriptions()->count())->toBe(1);
});

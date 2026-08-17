<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Models\ResellerUser;
use Misaf\VendraSubscription\Actions\ActivateSubscriptionAction;
use Misaf\VendraSubscription\Enums\SubscriptionPaymentStatus;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Jobs\ProcessSubscriptionPayment;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSubscription\Models\SubscriptionPayment;

it('is registered as not tenant aware so it survives dispatch from host-level flows without a current tenant', function (): void {
    expect(config('multitenancy.not_tenant_aware_jobs'))->toContain(ProcessSubscriptionPayment::class);
});

it('requeues stale payment operations and paid subscriptions awaiting activation', function (): void {
    Queue::fake();
    $reseller = Reseller::factory()->create();
    $payer = ResellerUser::factory()->forReseller($reseller)->create();
    $pendingSubscription = Subscription::factory()
        ->forSubscriber($reseller)
        ->create(['status' => SubscriptionStatus::PendingPayment]);
    $pendingPayment = SubscriptionPayment::factory()
        ->for($pendingSubscription)
        ->forPayer($payer)
        ->create();
    $paidSubscription = Subscription::factory()
        ->forSubscriber($reseller)
        ->create(['status' => SubscriptionStatus::PendingPayment]);
    $paidPayment = SubscriptionPayment::factory()
        ->for($paidSubscription)
        ->forPayer($payer)
        ->create(['status' => SubscriptionPaymentStatus::Paid]);
    $deferredPayment = SubscriptionPayment::factory()
        ->for($pendingSubscription)
        ->forPayer($payer)
        ->create(['next_retry_at' => now()->addDay()]);

    $this->artisan('vendra-subscription:recover-payments')
        ->expectsOutput('Requeued 2 subscription payment(s).')
        ->assertSuccessful();

    Queue::assertPushed(ProcessSubscriptionPayment::class, 2);
    Queue::assertPushed(fn(ProcessSubscriptionPayment $job): bool => $job->paymentId === $pendingPayment->getKey());
    Queue::assertPushed(fn(ProcessSubscriptionPayment $job): bool => $job->paymentId === $paidPayment->getKey());
    Queue::assertNotPushed(fn(ProcessSubscriptionPayment $job): bool => $job->paymentId === $deferredPayment->getKey());
});

it('marks an exhausted payment for reconciliation without treating an ambiguous outcome as failed', function (): void {
    $reseller = Reseller::factory()->create();
    $payer = ResellerUser::factory()->forReseller($reseller)->create();
    $subscription = Subscription::factory()
        ->forSubscriber($reseller)
        ->create(['status' => SubscriptionStatus::PendingPayment]);
    $payment = SubscriptionPayment::factory()
        ->for($subscription)
        ->forPayer($payer)
        ->create(['status' => SubscriptionPaymentStatus::Processing]);

    (new ProcessSubscriptionPayment($payment->getKey()))->failed(new RuntimeException('Provider timed out.'));

    expect($payment->refresh()->status)->toBe(SubscriptionPaymentStatus::NeedsReconciliation)
        ->and($payment->failure_code)->toBe('processing_exhausted')
        ->and($payment->failure_message)->toBe('Provider timed out.')
        ->and($payment->next_retry_at)->not->toBeNull();
});

it('activates a paid subscription idempotently', function (): void {
    Notification::fake();
    $reseller = Reseller::factory()->create();
    $payer = ResellerUser::factory()->forReseller($reseller)->create();
    $current = Subscription::factory()->forSubscriber($reseller)->create();
    $replacement = Subscription::factory()
        ->forSubscriber($reseller)
        ->create(['status' => SubscriptionStatus::PendingPayment]);
    $payment = SubscriptionPayment::factory()
        ->for($replacement)
        ->forPayer($payer)
        ->create(['status' => SubscriptionPaymentStatus::Paid]);
    $action = app(ActivateSubscriptionAction::class);

    $action->execute($payment);
    $action->execute($payment->refresh());

    expect($replacement->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($current->refresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($reseller->subscriptions()->active()->count())->toBe(1);
});

it('uses a unique bounded job for each durable payment operation', function (): void {
    $job = new ProcessSubscriptionPayment(123);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('123')
        ->and($job->tries)->toBe(5)
        ->and($job->timeout)->toBeLessThan(config()->integer('queue.connections.database.retry_after'))
        ->and($job->backoff())->toBe([5, 30, 120, 300]);
});

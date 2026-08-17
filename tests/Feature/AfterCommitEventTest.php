<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Misaf\VendraReseller\Actions\CreateResellerAction;
use Misaf\VendraSubscription\Actions\ApplySubscriptionPaymentResultAction;
use Misaf\VendraSubscription\Enums\SubscriptionPaymentStatus;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Events\SubscriptionActivated;
use Misaf\VendraSubscription\Events\SubscriptionPaymentFailed;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSubscription\Models\SubscriptionPayment;
use Misaf\VendraSupport\Data\SubscriptionChargeResult;
use Misaf\VendraSupport\Enums\SubscriptionChargeStatus;

it('dispatches payment state events only after the surrounding transaction commits', function (): void {
    Event::fake([SubscriptionPaymentFailed::class]);
    $subscription = Subscription::factory()->create(['status' => SubscriptionStatus::PendingPayment]);
    $payment = SubscriptionPayment::factory()->for($subscription)->create([
        'status' => SubscriptionPaymentStatus::Processing,
    ]);

    DB::transaction(function () use ($payment): void {
        app(ApplySubscriptionPaymentResultAction::class)->execute(
            $payment,
            new SubscriptionChargeResult(
                SubscriptionChargeStatus::Failed,
                errorCode: 'declined',
            ),
        );

        Event::assertNotDispatched(SubscriptionPaymentFailed::class);
    });

    Event::assertDispatched(
        SubscriptionPaymentFailed::class,
        fn(SubscriptionPaymentFailed $event): bool => $event->payment->is($payment),
    );
});

it('discards payment state events when the surrounding transaction rolls back', function (): void {
    Event::fake([SubscriptionPaymentFailed::class]);
    $subscription = Subscription::factory()->create(['status' => SubscriptionStatus::PendingPayment]);
    $payment = SubscriptionPayment::factory()->for($subscription)->create([
        'status' => SubscriptionPaymentStatus::Processing,
    ]);

    expect(fn() => DB::transaction(function () use ($payment): never {
        app(ApplySubscriptionPaymentResultAction::class)->execute(
            $payment,
            new SubscriptionChargeResult(
                SubscriptionChargeStatus::Failed,
                errorCode: 'declined',
            ),
        );

        throw new RuntimeException('Roll back the outer transaction.');
    }))->toThrow(RuntimeException::class);

    Event::assertNotDispatched(SubscriptionPaymentFailed::class);
    expect($payment->refresh()->status)->toBe(SubscriptionPaymentStatus::Processing);
});

it('delivers activation listeners outside the reseller creation transaction', function (): void {
    Notification::fake();
    $transactionLevels = [];

    Event::listen(
        SubscriptionActivated::class,
        function () use (&$transactionLevels): void {
            $transactionLevels[] = DB::transactionLevel();
        },
    );

    app(CreateResellerAction::class)->execute(
        plan: Plan::factory()->create(),
        username: 'reseller-owner',
        email: 'owner@example.com',
        password: 'secret-password',
    );

    expect($transactionLevels)->toBe([DB::transactionLevel()]);
});

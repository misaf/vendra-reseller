<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Notifications\StoresSuspendedNotification;
use Misaf\VendraReseller\Notifications\SubscriptionActivatedNotification;
use Misaf\VendraReseller\Notifications\SubscriptionExpiringNotification;
use Misaf\VendraReseller\Support\ResellerStoreSuspender;
use Misaf\VendraStore\Enums\StorefrontDesiredState;
use Misaf\VendraStore\Jobs\ReconcileStorefrontJob;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Models\StorefrontDeployment;
use Misaf\VendraSubscription\Actions\CancelSubscriptionAction;
use Misaf\VendraSubscription\Actions\EnforceSubscriptionsAction;
use Misaf\VendraSubscription\Actions\SubscribeAction;
use Misaf\VendraSubscription\Contracts\SubscriptionUnitSuspender;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Spatie\Multitenancy\Jobs\NotTenantAware;

it('notifies the reseller user when a subscription is activated', function (): void {
    Notification::fake();

    $reseller = Reseller::factory()->create();

    resolve(SubscribeAction::class)->execute($reseller, Plan::factory()->create());

    Notification::assertSentTo($reseller->user, SubscriptionActivatedNotification::class);
});

it('reminds the reseller user once about a soon-to-expire subscription', function (): void {
    Notification::fake();

    $reseller = Reseller::factory()->create();
    $subscription = Subscription::factory()->forSubscriber($reseller)->for(Plan::factory())->create([
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDays(20),
        'ends_at' => now()->addDays(3),
    ]);

    resolve(EnforceSubscriptionsAction::class)->execute();
    resolve(EnforceSubscriptionsAction::class)->execute();

    Notification::assertSentToTimes($reseller->user, SubscriptionExpiringNotification::class, 1);
    expect($subscription->refresh()->expiry_reminder_sent_at)->not->toBeNull();
});

it('warns in the reminder when the stores have outgrown the scheduled downgrade', function (): void {
    Notification::fake();

    $reseller = Reseller::factory()->create();
    Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->maxUnits(5)->create(['name' => 'Growth']))->create([
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDays(20),
        'ends_at' => now()->addDays(3),
        'scheduled_plan_id' => Plan::factory()->maxUnits(1)->create(['name' => 'Starter'])->id,
    ]);
    Store::factory()->count(2)->create(['reseller_id' => $reseller->getKey()]);

    resolve(EnforceSubscriptionsAction::class)->execute();

    Notification::assertSentTo(
        $reseller->user,
        SubscriptionExpiringNotification::class,
        fn (SubscriptionExpiringNotification $notification): bool => str_contains(
            implode(' ', $notification->toMail($reseller->user)->introLines),
            'renews on Growth unless you bring usage within Starter',
        ),
    );
});

it('notifies the reseller user when properties are suspended', function (): void {
    Notification::fake();

    $reseller = Reseller::factory()->create();
    Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->graceDays(0))->create([
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(2),
        'ends_at' => now()->subDays(2),
        'auto_renews' => false,
    ]);
    createTestTenant(['reseller_id' => $reseller->getKey(), 'active' => true]);

    resolve(EnforceSubscriptionsAction::class)->execute();
    resolve(EnforceSubscriptionsAction::class)->execute();

    Notification::assertSentToTimes($reseller->user, StoresSuspendedNotification::class, 1);
});

it('suspends stores immediately when the console cancels the subscription', function (): void {
    Notification::fake();

    $reseller = Reseller::factory()->create();
    $subscription = Subscription::factory()->forSubscriber($reseller)->for(Plan::factory())->create();
    $store = createTestTenant(['reseller_id' => $reseller->getKey(), 'active' => true]);

    resolve(CancelSubscriptionAction::class)->execute($subscription);

    expect($store->refresh()->billing_suspended_at)->not->toBeNull();
    Notification::assertSentToTimes($reseller->user, StoresSuspendedNotification::class, 1);
});

it('keeps stores serving when a cancelled subscription leaves another one active', function (): void {
    Notification::fake();

    $reseller = Reseller::factory()->create();
    Subscription::factory()->forSubscriber($reseller)->for(Plan::factory())->create([
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
    ]);
    $pendingChange = Subscription::factory()->forSubscriber($reseller)->for(Plan::factory())->create([
        'status' => SubscriptionStatus::PendingPayment,
    ]);
    $store = createTestTenant(['reseller_id' => $reseller->getKey(), 'active' => true]);

    resolve(CancelSubscriptionAction::class)->execute($pendingChange);

    expect($store->refresh()->billing_suspended_at)->toBeNull();
    Notification::assertNotSentTo($reseller->user, StoresSuspendedNotification::class);
});

it('queues subscription notifications off the request lifecycle', function (string $notification): void {
    expect(new ReflectionClass($notification))
        ->implementsInterface(ShouldQueueAfterCommit::class)->toBeTrue()
        ->and(new ReflectionClass($notification)->implementsInterface(NotTenantAware::class))->toBeTrue();
})->with([
    SubscriptionActivatedNotification::class,
    SubscriptionExpiringNotification::class,
    StoresSuspendedNotification::class,
]);

it('sends subscription notifications on the transactional-email queue', function (): void {
    Notification::fake();

    $reseller = Reseller::factory()->create();

    resolve(SubscribeAction::class)->execute($reseller, Plan::factory()->create());

    Notification::assertSentTo($reseller->user,
        SubscriptionActivatedNotification::class,
        fn (SubscriptionActivatedNotification $notification): bool => $notification->queue === 'transactional-email',
    );
});

it('stops and restarts storefronts with billing suspension and reactivation', function (): void {
    Queue::fake();

    $reseller = Reseller::factory()->create();
    $store = Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);
    $deployment = StorefrontDeployment::factory()->for($store)->create(['desired_state' => StorefrontDesiredState::Running]);

    $storeSuspender = resolve(SubscriptionUnitSuspender::class);

    expect($storeSuspender)->toBeInstanceOf(ResellerStoreSuspender::class)
        ->and($storeSuspender->suspendActiveUnits($reseller))->toBe(1)
        ->and($deployment->refresh()->desired_state)->toBe(StorefrontDesiredState::Stopped)
        ->and($storeSuspender->reactivateSuspendedUnits($reseller))->toBe(1)
        ->and($store->refresh()->billing_suspended_at)->toBeNull()
        ->and($deployment->refresh()->desired_state)->toBe(StorefrontDesiredState::Running);

    Queue::assertPushed(ReconcileStorefrontJob::class, fn (ReconcileStorefrontJob $job): bool => $job->deploymentId === $deployment->id);
});

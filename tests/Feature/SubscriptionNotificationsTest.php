<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Notification;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Notifications\StoresSuspendedNotification;
use Misaf\VendraReseller\Notifications\SubscriptionActivatedNotification;
use Misaf\VendraReseller\Notifications\SubscriptionExpiringNotification;
use Misaf\VendraSubscription\Actions\EnforceSubscriptionsAction;
use Misaf\VendraSubscription\Actions\SubscribeAction;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Spatie\Multitenancy\Jobs\NotTenantAware;

it('notifies the owner when a subscription is activated', function (): void {
    Notification::fake();

    $reseller = Reseller::factory()->create();

    app(SubscribeAction::class)->execute($reseller, Plan::factory()->create());

    Notification::assertSentTo($reseller, SubscriptionActivatedNotification::class);
});

it('does not notify a reseller without an owner contact', function (): void {
    Notification::fake();

    $reseller = Reseller::factory()->withoutOwner()->create();

    app(SubscribeAction::class)->execute($reseller, Plan::factory()->create());

    Notification::assertNothingSent();
});

it('reminds the owner once about a soon-to-expire subscription', function (): void {
    Notification::fake();

    $reseller = Reseller::factory()->create();
    $subscription = Subscription::factory()->forSubscriber($reseller)->for(Plan::factory())->create([
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDays(20),
        'ends_at'   => now()->addDays(3),
    ]);

    app(EnforceSubscriptionsAction::class)->execute();
    app(EnforceSubscriptionsAction::class)->execute();

    Notification::assertSentToTimes($reseller, SubscriptionExpiringNotification::class, 1);
    expect($subscription->refresh()->expiry_reminder_sent_at)->not->toBeNull();
});

it('notifies the owner when properties are suspended', function (): void {
    Notification::fake();

    $reseller = Reseller::factory()->create();
    Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->graceDays(0))->create([
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(2),
        'ends_at'   => now()->subDays(2),
    ]);
    createTestTenant(['reseller_id' => $reseller->getKey(), 'active' => true]);

    app(EnforceSubscriptionsAction::class)->execute();
    app(EnforceSubscriptionsAction::class)->execute();

    Notification::assertSentToTimes($reseller, StoresSuspendedNotification::class, 1);
});

it('queues subscription notifications off the request lifecycle', function (string $notification): void {
    expect(new ReflectionClass($notification))
        ->implementsInterface(ShouldQueueAfterCommit::class)->toBeTrue()
        ->and((new ReflectionClass($notification))->implementsInterface(NotTenantAware::class))->toBeTrue();
})->with([
    SubscriptionActivatedNotification::class,
    SubscriptionExpiringNotification::class,
    StoresSuspendedNotification::class,
]);

it('sends subscription notifications on the transactional-email queue', function (): void {
    Notification::fake();

    $reseller = Reseller::factory()->create();

    app(SubscribeAction::class)->execute($reseller, Plan::factory()->create());

    Notification::assertSentTo(
        $reseller,
        SubscriptionActivatedNotification::class,
        fn(SubscriptionActivatedNotification $notification): bool => 'transactional-email' === $notification->queue,
    );
});

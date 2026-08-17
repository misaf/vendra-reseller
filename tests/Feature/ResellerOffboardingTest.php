<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Misaf\VendraReseller\Actions\OffboardResellerAction;
use Misaf\VendraReseller\Events\ResellerOffboarded;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Actions\SubscribeAction;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Exceptions\SubscriptionLimitException;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraTenant\Models\Tenant;
use Misaf\VendraTenant\Models\TenantDomain;

it('blocks changing to a plan that cannot hold the current properties', function (): void {
    $reseller = Reseller::factory()->create();
    Tenant::factory()->count(2)->create(['reseller_id' => $reseller->getKey()]);

    app(SubscribeAction::class)->execute($reseller, Plan::factory()->maxUnits(1)->create());
})->throws(SubscriptionLimitException::class);

it('allows renewing the same plan while at capacity', function (): void {
    $reseller = Reseller::factory()->create();
    Tenant::factory()->count(2)->create(['reseller_id' => $reseller->getKey()]);

    $subscription = app(SubscribeAction::class)->execute($reseller, Plan::factory()->maxUnits(2)->create());

    expect($subscription->isActive())->toBeTrue();
});

it('offboards a reseller transactionally with an audit reason and one domain event', function (): void {
    Event::fake([ResellerOffboarded::class]);

    $reseller = Reseller::factory()->create();
    $activeSubscription = Subscription::factory()->forSubscriber($reseller)->for(Plan::factory())->create();
    $pendingSubscription = Subscription::factory()->forSubscriber($reseller)->for(Plan::factory())->create([
        'status' => SubscriptionStatus::PendingPayment,
    ]);
    $expiredSubscription = Subscription::factory()->forSubscriber($reseller)->for(Plan::factory())->create([
        'status' => SubscriptionStatus::Expired,
    ]);
    $property = Tenant::factory()->create(['reseller_id' => $reseller->getKey(), 'active' => true]);
    $domain = TenantDomain::factory()->for($property)->create(['active' => true]);

    app(OffboardResellerAction::class)->execute($reseller, 'Customer requested account closure.');

    $offboardedReseller = Reseller::query()->withTrashed()->findOrFail($reseller->getKey());

    expect(Tenant::query()->whereKey($property->getKey())->exists())->toBeFalse()
        ->and(TenantDomain::query()->whereKey($domain->getKey())->exists())->toBeFalse()
        ->and($activeSubscription->refresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($pendingSubscription->refresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($expiredSubscription->refresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($offboardedReseller->trashed())->toBeTrue()
        ->and($offboardedReseller->active)->toBeFalse()
        ->and($offboardedReseller->offboarding_reason)->toBe('Customer requested account closure.')
        ->and($offboardedReseller->offboarded_at)->not->toBeNull();

    Event::assertDispatched(
        ResellerOffboarded::class,
        fn(ResellerOffboarded $event): bool => $event->resellerId === $reseller->getKey()
            && 'Customer requested account closure.' === $event->reason
            && 2 === $event->cancelledSubscriptionCount
            && 1 === $event->offboardedTenantCount,
    );
    Event::assertDispatchedTimes(ResellerOffboarded::class, 1);
});

it('rejects deleting a reseller without the explicit offboarding workflow', function (): void {
    $reseller = Reseller::factory()->create();
    $property = Tenant::factory()->create(['reseller_id' => $reseller->getKey()]);

    expect(fn(): ?bool => $reseller->delete())
        ->toThrow(LogicException::class);

    expect($reseller->fresh()?->trashed())->toBeFalse()
        ->and($property->fresh()?->trashed())->toBeFalse();
});

it('can be retried without repeating side effects or replacing the audit reason', function (): void {
    Event::fake([ResellerOffboarded::class]);

    $reseller = Reseller::factory()->create();
    Tenant::factory()->create(['reseller_id' => $reseller->getKey()]);

    $action = app(OffboardResellerAction::class);
    $action->execute($reseller, 'Original reason.');
    $action->execute($reseller, 'Retry reason.');

    $offboardedReseller = Reseller::query()->withTrashed()->findOrFail($reseller->getKey());

    expect($offboardedReseller->offboarding_reason)->toBe('Original reason.');
    Event::assertDispatchedTimes(ResellerOffboarded::class, 1);
});

it('rolls back the complete offboarding workflow and discards its event', function (): void {
    Event::fake([ResellerOffboarded::class]);

    $reseller = Reseller::factory()->create();
    $subscription = Subscription::factory()->forSubscriber($reseller)->for(Plan::factory())->create();
    $property = Tenant::factory()->create(['reseller_id' => $reseller->getKey()]);

    expect(fn() => DB::transaction(function () use ($reseller): never {
        app(OffboardResellerAction::class)->execute($reseller, 'This transaction must roll back.');

        throw new RuntimeException('Roll back the outer transaction.');
    }))->toThrow(RuntimeException::class);

    Event::assertNotDispatched(ResellerOffboarded::class);

    expect($reseller->refresh()->trashed())->toBeFalse()
        ->and($reseller->offboarding_reason)->toBeNull()
        ->and($reseller->offboarded_at)->toBeNull()
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($property->refresh()->trashed())->toBeFalse();
});

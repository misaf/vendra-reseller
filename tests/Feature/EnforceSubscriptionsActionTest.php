<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Actions\EnforceSubscriptionsAction;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;

/**
 * Create a reseller whose only subscription is active but past its end date.
 */
function lapsedReseller(int $graceDays, Carbon $endsAt): Reseller
{
    $reseller = Reseller::factory()->create();
    $plan = Plan::factory()->graceDays($graceDays)->create();

    Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(2),
        'ends_at'   => $endsAt,
    ]);

    return $reseller;
}

it('expires subscriptions whose period has lapsed', function (): void {
    $reseller = lapsedReseller(graceDays: 0, endsAt: now()->subDay());
    $subscription = $reseller->subscriptions()->sole();

    app(EnforceSubscriptionsAction::class)->execute();

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired);
});

it('suspends properties once the grace period has passed', function (): void {
    $reseller = lapsedReseller(graceDays: 0, endsAt: now()->subDays(2));
    $property = createTestTenant(['reseller_id' => $reseller->getKey(), 'active' => true]);

    $result = app(EnforceSubscriptionsAction::class)->execute();

    expect($property->refresh()->active)->toBeTrue()
        ->and($property->billing_suspended_at)->not->toBeNull()
        ->and($result['grace_expired'])->toBe(1);
});

it('keeps properties live while still within the grace period', function (): void {
    $reseller = lapsedReseller(graceDays: 10, endsAt: now()->subDay());
    $property = createTestTenant(['reseller_id' => $reseller->getKey(), 'active' => true]);

    app(EnforceSubscriptionsAction::class)->execute();

    expect($property->refresh()->active)->toBeTrue()
        ->and($property->billing_suspended_at)->toBeNull();
});

it('leaves properties of resellers with an active subscription untouched', function (): void {
    $reseller = Reseller::factory()->create();
    Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->graceDays(0))->create();
    $property = createTestTenant(['reseller_id' => $reseller->getKey(), 'active' => true]);

    $result = app(EnforceSubscriptionsAction::class)->execute();

    expect($property->refresh()->active)->toBeTrue()
        ->and($property->billing_suspended_at)->toBeNull()
        ->and($result['grace_expired'])->toBe(0);
});

it('does not convert manual disablement into billing suspension', function (): void {
    $reseller = lapsedReseller(graceDays: 0, endsAt: now()->subDays(2));
    $property = createTestTenant([
        'reseller_id' => $reseller->getKey(),
        'active'      => false,
    ]);

    app(EnforceSubscriptionsAction::class)->execute();

    expect($property->refresh()->active)->toBeFalse()
        ->and($property->billing_suspended_at)->toBeNull();
});

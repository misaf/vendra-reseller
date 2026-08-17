<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Actions\SubscribeAction;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;

it('cancels the previous active subscription when changing plans', function (): void {
    $reseller = Reseller::factory()->create();
    $old = Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->maxUnits(1))->create();
    $newPlan = Plan::factory()->maxUnits(5)->create();

    $new = app(SubscribeAction::class)->execute($reseller, $newPlan);

    expect($old->refresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($new->status)->toBe(SubscriptionStatus::Active)
        ->and($new->plan_id)->toBe($newPlan->getKey())
        ->and($reseller->activeSubscription()?->getKey())->toBe($new->getKey())
        ->and($reseller->subscriptions()->active()->count())->toBe(1);
});

it('renews by creating a fresh active subscription for the same plan', function (): void {
    $reseller = Reseller::factory()->create();
    $plan = Plan::factory()->maxUnits(2)->create();
    Subscription::factory()->expired()->forSubscriber($reseller)->for($plan)->create();

    $renewed = app(SubscribeAction::class)->execute($reseller, $plan);

    expect($renewed->isActive())->toBeTrue()
        ->and($reseller->activeSubscription()?->getKey())->toBe($renewed->getKey());
});

it('clears only billing suspension when the reseller resubscribes', function (): void {
    $reseller = Reseller::factory()->create();
    $property = createTestTenant([
        'reseller_id'          => $reseller->getKey(),
        'active'               => false,
        'billing_suspended_at' => now(),
    ]);

    app(SubscribeAction::class)->execute($reseller, Plan::factory()->create());

    expect($property->refresh()->active)->toBeFalse()
        ->and($property->billing_suspended_at)->toBeNull();
});

it('rejects a second active subscription for the same reseller', function (): void {
    $reseller = Reseller::factory()->create();

    Subscription::factory()->forSubscriber($reseller)->create();

    expect(fn(): Subscription => Subscription::factory()->forSubscriber($reseller)->create())
        ->toThrow(QueryException::class);
});

it('allows historical subscriptions and replacing a soft-deleted active subscription', function (): void {
    $reseller = Reseller::factory()->create();

    Subscription::factory()->cancelled()->forSubscriber($reseller)->create();
    $deleted = Subscription::factory()->forSubscriber($reseller)->create();
    $deleted->delete();

    $replacement = Subscription::factory()->forSubscriber($reseller)->create();

    expect($replacement->status)->toBe(SubscriptionStatus::Active);
});

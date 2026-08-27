<?php

declare(strict_types=1);

use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraSubscription\Actions\ReactivateSubscriptionAction;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;

it('reactivates a cancelled reseller subscription as a new period and restores billing-suspended stores', function (): void {
    $reseller = Reseller::factory()->create();
    $plan = Plan::factory()->maxUnits(2)->create();
    $cancelled = Subscription::factory()->forSubscriber($reseller)->for($plan)->cancelled()->create();
    $store = Store::factory()->active()->suspended()->create(['reseller_id' => $reseller->id]);

    $reactivated = app(ReactivateSubscriptionAction::class)->execute($cancelled);

    expect($reactivated->status)->toBe(SubscriptionStatus::Active)
        ->and($reactivated->plan_id)->toBe($plan->id)
        ->and($cancelled->refresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($store->refresh()->billing_suspended_at)->toBeNull();
});

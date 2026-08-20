<?php

declare(strict_types=1);

use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;

it('expires subscriptions and suspends properties via the command', function (): void {
    $reseller = Reseller::factory()->create();
    Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->graceDays(0))->create([
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(2),
        'ends_at'   => now()->subDays(2),
    ]);
    $store = createTestTenant(['reseller_id' => $reseller->getKey(), 'active' => true]);

    $this->artisan('vendra-subscription:enforce-subscriptions')->assertSuccessful();

    expect($store->refresh()->active)->toBeTrue()
        ->and($store->billing_suspended_at)->not->toBeNull()
        ->and($reseller->subscriptions()->sole()->status)->toBe(SubscriptionStatus::Expired);
});

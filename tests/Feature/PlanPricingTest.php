<?php

declare(strict_types=1);

use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Models\ResellerUser;
use Misaf\VendraSubscription\Actions\SubscribeAction;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraTransaction\Database\Factories\TransactionGatewayFactory;

it('stores a plan price and currency', function (): void {
    $plan = Plan::factory()->priced(1500, 'USD')->create();

    expect($plan->price)->toBe(1500)
        ->and($plan->currency_code)->toBe('USD')
        ->and($plan->isFree())->toBeFalse();
});

it('treats a zero-price plan as free', function (): void {
    $plan = Plan::factory()->create();

    expect($plan->isFree())->toBeTrue();
});

it('snapshots the plan price onto the subscription when subscribing', function (): void {
    $reseller = Reseller::factory()->create();
    makeCurrentTestTenant();
    TransactionGatewayFactory::new()->internal()->create();
    ResellerUser::factory()->forReseller($reseller)->create();
    $plan = Plan::factory()->priced(2999, 'EUR')->trialDays(1)->create();

    $subscription = app(SubscribeAction::class)->execute($reseller, $plan);

    expect($subscription->price)->toBe(2999)
        ->and($subscription->currency_code)->toBe('EUR');
});

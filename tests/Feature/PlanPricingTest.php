<?php

declare(strict_types=1);

use Misaf\VendraReseller\Actions\CreateResellerUserAction;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Actions\SubscribeAction;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraTransaction\Database\Factories\TransactionGatewayFactory;
use Misaf\VendraUser\Models\User;

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
    $user = User::factory()->create(['tenant_id' => null]);
    $reseller->users()->attach($user->getKey());
    $plan = Plan::factory()->priced(2999, 'EUR')->trialDays(1)->create();

    $subscription = resolve(SubscribeAction::class)->execute($reseller, $plan);

    expect($subscription->price)->toBe(2999)
        ->and($subscription->currency_code)->toBe('EUR');
});

it('keeps the reseller user free of any tenant, even inside a tenant context', function (): void {
    makeCurrentTestTenant();
    $reseller = Reseller::factory()->create();

    $user = resolve(CreateResellerUserAction::class)->execute(
        $reseller,
        'tenant_free',
        'tenant-free@reseller.test',
        'Secure123',
    );

    expect($user->tenant_id)->toBeNull()
        ->and(Reseller::forUser($user)?->is($reseller))->toBeTrue();
});

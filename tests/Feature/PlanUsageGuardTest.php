<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Models\StoreDomain;
use Misaf\VendraSubscription\Actions\ChangeSubscriptionPlanAction;
use Misaf\VendraSubscription\Actions\SubscribeAction;
use Misaf\VendraSubscription\Exceptions\SubscriptionLimitException;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSubscription\Support\PlanCoverage;
use Misaf\VendraSupport\Enums\PlanLimit;
use Misaf\VendraSupport\Tenancy\TenantUsageRegistry;

beforeEach(function (): void {
    Queue::fake();
    Config::set('vendra-store.storefront.base_domain', 'vendra.test');
    $this->travelTo(Date::parse('2026-04-11 00:00:00'));

    $this->reseller = Reseller::factory()->active()->create();
    Subscription::factory()->forSubscriber($this->reseller)
        ->for(Plan::factory()->active()->priced(6_000)->maxUnits(5)->withFeatures(['custom_domain'])->create())
        ->create([
            'price' => 6_000,
            'starts_at' => Date::parse('2026-04-01 00:00:00'),
            'ends_at' => Date::parse('2026-05-01 00:00:00'),
        ]);

    $this->store = Store::factory()->create(['reseller_id' => $this->reseller->getKey()]);
    StoreDomain::factory()->for($this->store)->primary()->create(['name' => 'shop.vendra.test']);
    StoreDomain::factory()->for($this->store)->active()->create(['name' => 'alias.vendra.test']);
});

it('refuses a plan below the domains a store uses', function (): void {
    $plan = Plan::factory()->active()->maxUnits(5)->withLimits([PlanLimit::DomainsPerStore->value => 1])->create();

    expect(fn () => resolve(SubscribeAction::class)->execute($this->reseller, $plan))
        ->toThrow(SubscriptionLimitException::class, 'uses 2 of [Domains per store]');
});

it('refuses to schedule a downgrade below a registered usage', function (): void {
    resolve(TenantUsageRegistry::class)->register(
        PlanLimit::StorageMegabytesPerStore,
        fn (Model $store): int => 3 * 1024 * 1024,
    );
    $cheaper = Plan::factory()->active()->priced(3_000)->maxUnits(5)->withFeatures(['custom_domain'])
        ->withLimits([PlanLimit::StorageMegabytesPerStore->value => 2])
        ->create();

    expect(fn () => resolve(ChangeSubscriptionPlanAction::class)->execute($this->reseller, $cheaper))
        ->toThrow(SubscriptionLimitException::class, 'uses 3 of [Storage per store (MB)]');
});

it('refuses a plan without custom domains while a store uses one', function (): void {
    StoreDomain::factory()->for($this->store)->active()->create(['name' => 'shop.example.com']);

    expect(fn () => resolve(SubscribeAction::class)->execute($this->reseller, Plan::factory()->active()->maxUnits(5)->create()))
        ->toThrow(SubscriptionLimitException::class, '[Custom domain]');
});

it('accepts a plan that covers every store', function (): void {
    $plan = Plan::factory()->active()->maxUnits(5)->withLimits([PlanLimit::DomainsPerStore->value => 2])->create();

    expect(resolve(SubscribeAction::class)->execute($this->reseller, $plan)->plan_id)->toBe($plan->id);
});

it('tells a plan picker which plans the stores have outgrown without refusing anything', function (): void {
    $tooSmall = Plan::factory()->active()->maxUnits(5)->withFeatures(['custom_domain'])->withLimits([PlanLimit::DomainsPerStore->value => 1])->create();
    $roomy = Plan::factory()->active()->maxUnits(5)->withFeatures(['custom_domain'])->withLimits([PlanLimit::DomainsPerStore->value => 2])->create();

    expect(resolve(PlanCoverage::class)->covers($this->reseller, $tooSmall))->toBeFalse()
        ->and(resolve(PlanCoverage::class)->covers($this->reseller, $roomy))->toBeTrue();
});

<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Misaf\VendraReseller\Actions\CreateResellerAction;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Actions\CreateStoreAction;
use Misaf\VendraSubscription\Enums\PeriodUnit;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraUser\Models\User;

it('creates a reseller subscribed to a plan for its period', function (): void {
    $plan = Plan::factory()->active()->period(PeriodUnit::Month, 1)->create();

    $result = resolve(CreateResellerAction::class)->execute(
        plan: $plan,
        username: 'acme_owner',
        email: 'admin@acme.test',
        password: 'Secure123',
    );

    expect(Arr::get($result, 'reseller'))->toBeInstanceOf(Reseller::class)
        ->and(Arr::get($result, 'reseller')->exists)->toBeTrue()
        ->and(Arr::get($result, 'user'))->toBeInstanceOf(User::class)
        ->and(Reseller::forUser(Arr::get($result, 'user'))?->is(Arr::get($result, 'reseller')))->toBeTrue()
        ->and(Arr::get($result, 'subscription')->status)->toBe(SubscriptionStatus::Active)
        ->and(Arr::get($result, 'subscription')->plan_id)->toBe($plan->getKey())
        ->and(Arr::get($result, 'subscription')->ends_at->toDateString())
        ->toBe(Arr::get($result, 'subscription')->starts_at->copy()->addMonth()->toDateString())
        ->and(Arr::get($result, 'reseller')->activeSubscription()?->getKey())->toBe(Arr::get($result, 'subscription')->getKey());
});

it('creates a reseller with the requested active state', function (): void {
    $result = resolve(CreateResellerAction::class)->execute(
        plan: Plan::factory()->active()->create(),
        username: 'paused_owner',
        email: 'admin@paused.test',
        password: 'Secure123',
        active: false,
    );

    expect(Arr::get($result, 'reseller')->active)->toBeFalse();
});

it('keeps store administrators separate from the reseller user account', function (): void {
    $reseller = Reseller::factory()->active()->create();
    Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->active()->maxUnits(3))->create();
    $user = User::factory()->create(['tenant_id' => null]);
    $reseller->user()->associate($user)->save();

    $first = resolve(CreateStoreAction::class)->execute(
        name: 'First Store',
        domain: 'first.test',
        username: 'admin_first',
        email: 'admin@first.test',
        password: 'secret-password',
        reseller: $reseller,
    );

    $second = resolve(CreateStoreAction::class)->execute(
        name: 'Second Store',
        domain: 'second.test',
        username: 'admin_second',
        email: 'admin@second.test',
        password: 'secret-password',
        reseller: $reseller,
    );

    expect(Reseller::forUser($user)?->is($reseller))->toBeTrue()
        ->and(Arr::get($first, 'user'))->toBeInstanceOf(User::class)
        ->and(Arr::get($second, 'user'))->toBeInstanceOf(User::class)
        ->and($reseller->stores()->count())->toBe(2);
});

it('rejects making one user the main account of two resellers', function (): void {
    $reseller = Reseller::factory()->active()->create();

    expect(fn (): Reseller => Reseller::factory()->active()->create(['user_id' => $reseller->user_id]))
        ->toThrow(QueryException::class);
});

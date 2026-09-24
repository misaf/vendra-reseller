<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Enums\PeriodUnit;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Exceptions\PlanInUseException;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;

it('relates a reseller to its properties and subscriptions', function (): void {
    $reseller = Reseller::factory()
        ->has(Subscription::factory())
        ->create();

    createTestTenant(['reseller_id' => $reseller->getKey()]);
    createTestTenant(['reseller_id' => $reseller->getKey()]);

    expect($reseller->stores)->toHaveCount(2)
        ->and($reseller->stores->first())->toBeInstanceOf(testTenantModel())
        ->and($reseller->subscriptions)->toHaveCount(1);
});

it('stores and resolves the stable reseller morph alias', function (): void {
    $reseller = Reseller::factory()->create();
    $subscription = Subscription::factory()->forSubscriber($reseller)->create();

    expect($reseller->getMorphClass())->toBe('reseller')
        ->and($subscription->subscriber_type)->toBe('reseller')
        ->and($subscription->subscriber)->toBeInstanceOf(Reseller::class)
        ->and($subscription->subscriber->is($reseller))->toBeTrue();
});

it('does not require a reseller morph follow-up migration', function (): void {
    $followUpMigrations = glob(database_path('migrations/*_normalize_reseller_subscription_morph_type.php')) ?: [];

    expect($followUpMigrations)->toBeEmpty();
});

it('returns the active subscription and ignores expired or cancelled ones', function (): void {
    $reseller = Reseller::factory()->create();

    Subscription::factory()->expired()->forSubscriber($reseller)->create();
    Subscription::factory()->cancelled()->forSubscriber($reseller)->create();
    $active = Subscription::factory()->forSubscriber($reseller)->create();

    expect($reseller->activeSubscription()?->getKey())->toBe($active->getKey());
});

it('reports no active subscription when none are active', function (): void {
    $reseller = Reseller::factory()->create();

    Subscription::factory()->expired()->forSubscriber($reseller)->create();

    expect($reseller->activeSubscription())->toBeNull();
});

it('filters resellers by the state of their subscriptions', function (): void {
    $active = Reseller::factory()->create();
    Subscription::factory()->forSubscriber($active)->create(['ends_at' => now()->addMonth()]);
    $endingSoon = Reseller::factory()->create();
    Subscription::factory()->forSubscriber($endingSoon)->create(['ends_at' => now()->addDays(3)]);
    $pastDue = Reseller::factory()->create();
    Subscription::factory()->forSubscriber($pastDue)->create(['status' => SubscriptionStatus::PastDue]);
    $expired = Reseller::factory()->create();
    Subscription::factory()->expired()->forSubscriber($expired)->create();

    $ids = fn (Builder $query): array => $query->orderBy('id')->pluck('id')->all();

    expect($ids(Reseller::query()->withActiveSubscription()))->toBe([$active->id, $endingSoon->id])
        ->and($ids(Reseller::query()->withSubscriptionEndingWithin(7)))->toBe([$endingSoon->id])
        ->and($ids(Reseller::query()->withPastDueSubscription()))->toBe([$pastDue->id])
        ->and($ids(Reseller::query()->withoutActiveSubscription()))->toBe([$pastDue->id, $expired->id]);
});

it('treats a subscription without an end date as active', function (): void {
    $subscription = Subscription::factory()->neverExpires()->create();

    expect($subscription->isActive())->toBeTrue();
});

it('treats an expired subscription as inactive', function (): void {
    $subscription = Subscription::factory()->expired()->create();

    expect($subscription->isActive())->toBeFalse();
});

it('resolves the plan end date from its period', function (): void {
    $plan = Plan::factory()->period(PeriodUnit::Month, 3)->create();

    $start = Date::parse('2026-01-01 00:00:00');

    expect($plan->resolveEndDate($start)->toDateString())->toBe('2026-04-01');
});

it('prevents deleting a plan referenced by a subscription', function (): void {
    $plan = Plan::factory()->create();
    Subscription::factory()->for($plan)->create();

    $plan->delete();
})->throws(PlanInUseException::class);

it('hides the generated active-reseller guard', function (): void {
    expect((new Subscription)->getHidden())->toContain('active_subscriber_guard');
});

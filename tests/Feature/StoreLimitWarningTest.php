<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Notifications\StoreLimitApproachedNotification;
use Misaf\VendraStore\Actions\AddStoreDomainAliasAction;
use Misaf\VendraStore\Events\StoreLimitApproached;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Models\StoreDomain;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSupport\Enums\PlanLimit;
use Misaf\VendraUser\Models\User;

beforeEach(function (): void {
    Queue::fake();
    Notification::fake();
    Config::set('vendra-store.storefront.base_domain', 'vendra.test');
    $this->travelTo(Date::parse('2026-04-11 00:00:00'));

    $this->reseller = Reseller::factory()->active()->create();
    $this->reseller->user()->associate(User::factory()->create(['tenant_id' => null]))->save();
    Subscription::factory()->forSubscriber($this->reseller)
        ->for(Plan::factory()->active()->maxUnits(5)->withLimits([PlanLimit::DomainsPerStore->value => 5])->create())
        ->create([
            'starts_at' => Date::parse('2026-04-01 00:00:00'),
            'ends_at' => Date::parse('2026-05-01 00:00:00'),
        ]);

    $this->store = Store::factory()->create(['reseller_id' => $this->reseller->getKey(), 'name' => 'Corner Shop']);
    StoreDomain::factory()->for($this->store)->primary()->create(['name' => 'shop.vendra.test']);
});

function sentLimitSubjects(Reseller $reseller): array
{
    return Notification::sent($reseller->user, StoreLimitApproachedNotification::class)
        ->map(fn (StoreLimitApproachedNotification $notification): string => $notification->toMail($reseller->user)->subject)
        ->all();
}

it('warns the reseller once as a store crosses 80% and again at the limit', function (): void {
    $addAlias = resolve(AddStoreDomainAliasAction::class);

    $addAlias->execute($this->store, 'two.vendra.test');
    $addAlias->execute($this->store, 'three.vendra.test');

    expect(sentLimitSubjects($this->reseller))->toBeEmpty();

    $addAlias->execute($this->store, 'four.vendra.test');

    expect(sentLimitSubjects($this->reseller))->toBe(['Corner Shop is nearing its plan limit']);

    $addAlias->execute($this->store, 'five.vendra.test');

    expect(sentLimitSubjects($this->reseller))->toBe([
        'Corner Shop is nearing its plan limit',
        'Corner Shop reached its plan limit',
    ]);
});

it('warns about each threshold only once in a subscription period', function (): void {
    event(new StoreLimitApproached($this->store, PlanLimit::DomainsPerStore, 80));
    event(new StoreLimitApproached($this->store, PlanLimit::DomainsPerStore, 80));
    event(new StoreLimitApproached($this->store, PlanLimit::ProductsPerStore, 80));

    expect(sentLimitSubjects($this->reseller))->toHaveCount(2);
});

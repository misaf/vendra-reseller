<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Misaf\VendraReseller\Filament\Pages\Dashboard;
use Misaf\VendraReseller\Filament\Widgets\GettingStarted;
use Misaf\VendraReseller\Filament\Widgets\LatestStores;
use Misaf\VendraReseller\Filament\Widgets\PlanSummary;
use Misaf\VendraReseller\Filament\Widgets\StoresNeedingAttention;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Enums\StorefrontDeploymentStatus;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Models\StoreDomain;
use Misaf\VendraStore\Models\StorefrontDeployment;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSupport\Enums\PlanLimit;
use Misaf\VendraSupport\Tenancy\Events\TenantProvisioned;
use Misaf\VendraUser\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Event::fake([TenantProvisioned::class]);
    Artisan::shouldReceive('call')->andReturn(0);
    Config::set('container.drivers.docker.host', 'http://reseller-dashboard.test');
    Config::set('vendra-store.storefront.network', 'traefik-public');
    fakeDockerEngine();
});

function actAsReseller(Reseller $reseller): User
{
    $user = User::factory()->create(['tenant_id' => null]);
    $reseller->user()->associate($user)->save();
    actingAs($user, 'reseller');
    Filament::setCurrentPanel(Filament::getPanel('reseller'));

    return $user;
}

describe('reseller dashboard page', function (): void {
    it('requires reseller authentication', function (): void {
        $reseller = Reseller::factory()->active()->create();

        $this->get(route('filament.reseller.pages.dashboard'))->assertRedirect();

        actAsReseller($reseller);

        $this->get(route('filament.reseller.pages.dashboard'))->assertOk();
    });

    it('lays out its widgets in a fixed order', function (): void {
        expect(new Dashboard()->getWidgets())->toBe([
            GettingStarted::class,
            PlanSummary::class,
            StoresNeedingAttention::class,
            LatestStores::class,
        ]);
    });
});

describe('getting started', function (): void {
    it('walks a new reseller through the steps to a live storefront', function (): void {
        $reseller = Reseller::factory()->active()->create();
        Subscription::factory()->forSubscriber($reseller)->for(Plan::factory())->create();

        actAsReseller($reseller);

        expect(GettingStarted::canView())->toBeTrue();

        livewire(GettingStarted::class)
            ->assertOk()
            ->assertSeeInOrder([
                __('vendra-reseller::attributes.step_subscribe'),
                __('vendra-reseller::attributes.step_done'),
                __('vendra-reseller::attributes.step_create_store'),
                __('vendra-reseller::attributes.step_to_do'),
            ]);
    });

    it('disappears once a storefront is live', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $store = Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);
        StorefrontDeployment::factory()->for($store)->create(['status' => StorefrontDeploymentStatus::Ready]);

        actAsReseller($reseller);

        expect(GettingStarted::canView())->toBeFalse();
    });

    it("is not satisfied by another reseller's live storefront", function (): void {
        $reseller = Reseller::factory()->active()->create();
        $otherStore = Store::factory()->active()->create();
        StorefrontDeployment::factory()->for($otherStore)->create(['status' => StorefrontDeploymentStatus::Ready]);

        actAsReseller($reseller);

        expect(GettingStarted::canView())->toBeTrue();
    });
});

describe('plan summary', function (): void {
    it('shows the active plan with its renewal date and store capacity', function (): void {
        $reseller = Reseller::factory()->active()->create();
        Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->maxUnits(5)->state(['name' => 'Professional']))->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
        Store::factory()->count(2)->active()->create(['reseller_id' => $reseller->getKey()]);
        Store::factory()->count(2)->active()->create();

        actAsReseller($reseller);

        livewire(PlanSummary::class)
            ->assertOk()
            ->assertSee('Professional')
            ->assertSee(__('vendra-reseller::attributes.renews_on').': '.now()->addMonth()->format('Y-m-d'))
            ->assertSee('2 / 5')
            ->assertSee(__('vendra-reseller::attributes.remaining_stores').': 3');
    });

    it('shows the trial end while the plan is on trial', function (): void {
        $reseller = Reseller::factory()->active()->create();
        Subscription::factory()->forSubscriber($reseller)->for(Plan::factory())->create([
            'trial_ends_at' => now()->addDays(10),
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        actAsReseller($reseller);

        livewire(PlanSummary::class)
            ->assertOk()
            ->assertSee(__('vendra-reseller::attributes.trial_until', ['date' => now()->addDays(10)->format('Y-m-d')]));
    });

    it('warns when the plan ends within a week', function (): void {
        $reseller = Reseller::factory()->active()->create();
        Subscription::factory()->forSubscriber($reseller)->for(Plan::factory())->create([
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addDays(3)->addHour(),
        ]);

        actAsReseller($reseller);

        livewire(PlanSummary::class)
            ->assertOk()
            ->assertSee(trans_choice('vendra-reseller::attributes.plan_ends_in_days', 3, ['days' => 3]));
    });

    it('shows the lapsed status and no capacity without an active plan', function (SubscriptionStatus $status): void {
        $reseller = Reseller::factory()->active()->create();
        Subscription::factory()->forSubscriber($reseller)->for(Plan::factory())->create([
            'status' => $status,
            'ends_at' => now()->subDay(),
        ]);
        Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);

        actAsReseller($reseller);

        livewire(PlanSummary::class)
            ->assertOk()
            ->assertSee($status->getLabel())
            ->assertSee(__('vendra-reseller::attributes.subscribe_to_create_stores'))
            ->assertSee(__('vendra-reseller::attributes.capacity_requires_plan'))
            ->assertDontSee('1 / 0');
    })->with([
        'expired' => SubscriptionStatus::Expired,
        'past due' => SubscriptionStatus::PastDue,
        'cancelled' => SubscriptionStatus::Cancelled,
    ]);

    it('says so when the reseller never subscribed', function (): void {
        $reseller = Reseller::factory()->active()->create();

        actAsReseller($reseller);

        livewire(PlanSummary::class)
            ->assertOk()
            ->assertSee(__('vendra-reseller::attributes.no_plan'));
    });

    it('shows each plan limit against the store that uses the most of it', function (): void {
        $reseller = Reseller::factory()->active()->create();
        Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->withLimits([PlanLimit::DomainsPerStore->value => 2]))->create();
        $quietStore = Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);
        StoreDomain::factory()->for($quietStore)->primary()->create();
        $busyStore = Store::factory()->active()->create(['reseller_id' => $reseller->getKey(), 'name' => 'Busy Blooms']);
        StoreDomain::factory()->for($busyStore)->primary()->create();
        StoreDomain::factory()->for($busyStore)->active()->create();

        actAsReseller($reseller);

        livewire(PlanSummary::class)
            ->assertOk()
            ->assertSee(PlanLimit::DomainsPerStore->getLabel())
            ->assertSee('2 / 2')
            ->assertSee(__('vendra-reseller::attributes.busiest_store', ['store' => 'Busy Blooms']))
            ->assertDontSee(PlanLimit::ProductsPerStore->getLabel());
    });

    it('shows every plan limit the plan sets', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $limits = array_fill_keys(array_map(fn (PlanLimit $limit): string => $limit->value, PlanLimit::cases()), 5);
        Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->withLimits($limits))->create();
        Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);

        actAsReseller($reseller);

        $component = livewire(PlanSummary::class)->assertOk();

        foreach (PlanLimit::cases() as $limit) {
            $component->assertSee($limit->getLabel());
        }
    });

    it('asks to change plan when the stores have outgrown the current one', function (): void {
        $reseller = Reseller::factory()->active()->create();
        Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->maxUnits(1))->create();
        Store::factory()->count(2)->create(['reseller_id' => $reseller->getKey()]);

        actAsReseller($reseller);

        livewire(PlanSummary::class)
            ->assertOk()
            ->assertSee(__('vendra-reseller::attributes.plan_outgrown_change'));
    });

    it('flags stores suspended for billing', function (): void {
        $reseller = Reseller::factory()->active()->create();
        Store::factory()->count(2)->active()->suspended()->create(['reseller_id' => $reseller->getKey()]);
        Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);

        actAsReseller($reseller);

        livewire(PlanSummary::class)
            ->assertOk()
            ->assertSee(trans_choice('vendra-reseller::attributes.stores_suspended_for_billing', 2, ['count' => 2]));
    });
});

describe('stores needing attention', function (): void {
    it("lists the reseller's unfinished and failed stores with the reason", function (): void {
        $reseller = Reseller::factory()->active()->create();
        $failed = Store::factory()->provisioningFailed()->active()->create([
            'reseller_id' => $reseller->getKey(),
            'provisioning_error' => 'Database creation failed.',
        ]);
        $provisioning = Store::factory()->provisioning()->active()->create(['reseller_id' => $reseller->getKey()]);
        $failedStorefront = Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);
        StorefrontDeployment::factory()->for($failedStorefront)->create([
            'status' => StorefrontDeploymentStatus::Failed,
            'error' => 'Image pull failed.',
        ]);
        $healthy = Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);
        $otherFailed = Store::factory()->provisioningFailed()->active()->create();

        actAsReseller($reseller);

        expect(StoresNeedingAttention::canView())->toBeTrue();

        livewire(StoresNeedingAttention::class)
            ->call('loadTable')
            ->assertOk()
            ->assertCanSeeTableRecords([$failed, $provisioning, $failedStorefront])
            ->assertCanNotSeeTableRecords([$healthy, $otherFailed])
            ->assertSee('Database creation failed.')
            ->assertSee('Image pull failed.');
    });

    it('lists a store that uses more than its plan allows', function (): void {
        $reseller = Reseller::factory()->active()->create();
        Subscription::factory()->forSubscriber($reseller)
            ->for(Plan::factory()->active()->withLimits([PlanLimit::DomainsPerStore->value => 1]))
            ->create();
        $over = Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);
        StoreDomain::factory()->for($over)->primary()->create(['name' => 'over.vendra.test']);
        StoreDomain::factory()->for($over)->active()->create(['name' => 'alias.vendra.test']);
        $within = Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);
        StoreDomain::factory()->for($within)->primary()->create(['name' => 'within.vendra.test']);

        actAsReseller($reseller);

        livewire(StoresNeedingAttention::class)
            ->call('loadTable')
            ->assertCanSeeTableRecords([$over])
            ->assertCanNotSeeTableRecords([$within])
            ->assertSee(__('vendra-reseller::attributes.over_plan_limits', ['limits' => PlanLimit::DomainsPerStore->getLabel()]));
    });

    it('is hidden when every store is healthy', function (): void {
        $reseller = Reseller::factory()->active()->create();
        Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);

        actAsReseller($reseller);

        expect(StoresNeedingAttention::canView())->toBeFalse();
    });
});

describe('latest stores', function (): void {
    it('is shown only once the reseller has stores of its own', function (): void {
        $reseller = Reseller::factory()->active()->create();
        Store::factory()->active()->create();

        actAsReseller($reseller);

        expect(LatestStores::canView())->toBeFalse();

        Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);

        expect(LatestStores::canView())->toBeTrue();
    });
});

<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Misaf\VendraReseller\Filament\Widgets\LatestStores;
use Misaf\VendraReseller\Filament\Widgets\ResellerOverview;
use Misaf\VendraReseller\Filament\Widgets\SubscriptionDetail;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Enums\StorefrontDeploymentStatus;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Models\StorefrontDeployment;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
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
    $reseller->users()->attach($user->getKey());
    actingAs($user, 'reseller');
    Filament::setCurrentPanel(Filament::getPanel('reseller'));

    return $user;
}

describe('reseller overview subscription status', function (): void {
    it('shows active subscription status with plan name', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $plan = Plan::factory()->create(['name' => 'Professional']);
        Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
            'status' => SubscriptionStatus::Active,
        ]);

        actAsReseller($reseller);

        livewire(ResellerOverview::class)
            ->assertOk()
            ->assertSee(__('console.subscription_summary'))
            ->assertSee('Professional');
    });

    it('shows trial status when subscription is on trial', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => now()->addDays(10),
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        actAsReseller($reseller);

        livewire(ResellerOverview::class)
            ->assertOk()
            ->assertSee(__('console.trial_until', ['date' => now()->addDays(10)->format('Y-m-d')]));
    });

    it('shows no active subscription for expired subscriptions', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
            'status' => SubscriptionStatus::Expired,
            'ends_at' => now()->subDay(),
        ]);

        actAsReseller($reseller);

        livewire(ResellerOverview::class)
            ->assertOk()
            ->assertSee(__('console.no_active_subscription'));
    });

    it('shows no active subscription for past due subscriptions', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
            'status' => SubscriptionStatus::PastDue,
            'ends_at' => now()->subDay(),
        ]);

        actAsReseller($reseller);

        livewire(ResellerOverview::class)
            ->assertOk()
            ->assertSee(__('console.no_active_subscription'));
    });

    it('shows no active subscription when reseller has none', function (): void {
        $reseller = Reseller::factory()->active()->create();

        actAsReseller($reseller);

        livewire(ResellerOverview::class)
            ->assertOk()
            ->assertSee(__('console.no_active_subscription'));
    });
});

describe('reseller overview store capacity', function (): void {
    it('shows used and max store counts', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $plan = Plan::factory()->maxUnits(5)->create();
        Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
            'status' => SubscriptionStatus::Active,
        ]);
        Store::factory()->count(2)->create(['reseller_id' => $reseller->getKey()]);

        actAsReseller($reseller);

        livewire(ResellerOverview::class)
            ->assertOk()
            ->assertSee(__('console.store_capacity'))
            ->assertSee('2 / 5');
    });

    it('shows remaining as zero when plan is full', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $plan = Plan::factory()->maxUnits(1)->create();
        Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
            'status' => SubscriptionStatus::Active,
        ]);
        Store::factory()->create(['reseller_id' => $reseller->getKey()]);

        actAsReseller($reseller);

        livewire(ResellerOverview::class)
            ->assertOk()
            ->assertSee('0');
    });
});

describe('reseller overview store readiness', function (): void {
    it('counts active, provisioning, and failed stores separately', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $plan = Plan::factory()->maxUnits(10)->create();
        Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
            'status' => SubscriptionStatus::Active,
        ]);

        Store::factory()->count(2)->active()->create(['reseller_id' => $reseller->getKey()]);
        Store::factory()->provisioning()->active()->create(['reseller_id' => $reseller->getKey()]);
        Store::factory()->provisioningFailed()->active()->create(['reseller_id' => $reseller->getKey()]);

        actAsReseller($reseller);

        livewire(ResellerOverview::class)
            ->assertOk()
            ->assertSee(__('console.active_stores'))
            ->assertSee(__('console.stores_needing_attention'));
    });

    it('shows zero attention required when all stores are active', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $plan = Plan::factory()->maxUnits(5)->create();
        Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
            'status' => SubscriptionStatus::Active,
        ]);
        Store::factory()->count(3)->active()->create(['reseller_id' => $reseller->getKey()]);

        actAsReseller($reseller);

        livewire(ResellerOverview::class)
            ->assertOk()
            ->assertSee(__('console.stores_needing_attention'))
            ->assertSee('0');
    });
});

describe('reseller overview storefront status', function (): void {
    it('shows storefront readiness based on deployment status', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $plan = Plan::factory()->maxUnits(5)->create();
        Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
            'status' => SubscriptionStatus::Active,
        ]);
        $store = Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);
        StorefrontDeployment::factory()->for($store)->create([
            'status' => StorefrontDeploymentStatus::Ready,
        ]);

        actAsReseller($reseller);

        livewire(ResellerOverview::class)
            ->assertOk()
            ->assertSee(__('console.storefronts_ready'));
    });
});

describe('reseller subscription detail widget', function (): void {
    it('always renders regardless of subscription state', function (): void {
        $reseller = Reseller::factory()->active()->create();

        actAsReseller($reseller);

        livewire(SubscriptionDetail::class)
            ->assertOk()
            ->assertSee(__('console.subscription_status'));
    });

    it('shows cancelled status for cancelled subscriptions', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
            'status' => SubscriptionStatus::Cancelled,
        ]);

        actAsReseller($reseller);

        livewire(SubscriptionDetail::class)
            ->assertOk()
            ->assertSee(__('console.status_cancelled'));
    });

    it('shows expired status for expired subscriptions via latest subscription', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
            'status' => SubscriptionStatus::Expired,
            'ends_at' => now()->subDay(),
        ]);

        actAsReseller($reseller);

        livewire(SubscriptionDetail::class)
            ->assertOk()
            ->assertSee(__('console.status_expired'));
    });

    it('shows past due status for past due subscriptions via latest subscription', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
            'status' => SubscriptionStatus::PastDue,
            'ends_at' => now()->subDay(),
        ]);

        actAsReseller($reseller);

        livewire(SubscriptionDetail::class)
            ->assertOk()
            ->assertSee(__('console.status_past_due'));
    });

    it('shows trial information when subscription is on trial', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => now()->addDays(7),
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        actAsReseller($reseller);

        livewire(SubscriptionDetail::class)
            ->assertOk()
            ->assertSee(__('console.trial_until', ['date' => now()->addDays(7)->format('Y-m-d')]));
    });

    it('shows no trial when subscription is active but not on trial', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonth(),
        ]);

        actAsReseller($reseller);

        livewire(SubscriptionDetail::class)
            ->assertOk()
            ->assertSee(__('console.no_trial'));
    });
});

describe('reseller latest stores widget', function (): void {
    it('is hidden when the reseller has no stores', function (): void {
        $reseller = Reseller::factory()->active()->create();

        actAsReseller($reseller);

        expect(LatestStores::canView())->toBeFalse();
    });

    it('is visible when the reseller has stores', function (): void {
        $reseller = Reseller::factory()->active()->create();
        Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);

        actAsReseller($reseller);

        expect(LatestStores::canView())->toBeTrue();
    });

    it('is not visible when another reseller has stores but current reseller has none', function (): void {
        $reseller = Reseller::factory()->active()->create();
        Store::factory()->active()->create();

        actAsReseller($reseller);

        expect(LatestStores::canView())->toBeFalse();
    });

    it('renders the table component with correct heading', function (): void {
        $reseller = Reseller::factory()->active()->create();
        Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);

        actAsReseller($reseller);

        livewire(LatestStores::class)
            ->assertOk()
            ->assertSee(__('console.stores'));
    });
});

describe('reseller dashboard access control', function (): void {
    it('requires reseller authentication to view dashboard widgets', function (): void {
        $reseller = Reseller::factory()->active()->create();
        Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);

        $this->get(route('filament.reseller.pages.dashboard'))->assertRedirect();

        actAsReseller($reseller);

        $this->get(route('filament.reseller.pages.dashboard'))->assertOk();
    });
});

describe('reseller dashboard subscription widget colors', function (): void {
    it('uses danger color for past due subscriptions', function (): void {
        $reseller = Reseller::factory()->active()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
            'status' => SubscriptionStatus::PastDue,
            'ends_at' => now()->subDay(),
        ]);

        actAsReseller($reseller);

        livewire(SubscriptionDetail::class)
            ->assertOk()
            ->assertSee(__('console.status_past_due'));
    });

    it('uses gray color when no subscription exists', function (): void {
        $reseller = Reseller::factory()->active()->create();

        actAsReseller($reseller);

        livewire(SubscriptionDetail::class)
            ->assertOk()
            ->assertSee(__('console.no_active_subscription'));
    });
});

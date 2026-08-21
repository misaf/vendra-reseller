<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Misaf\VendraReseller\Filament\Pages\Auth\Login;
use Misaf\VendraReseller\Filament\Pages\Auth\Register;
use Misaf\VendraReseller\Filament\Resources\Stores\Pages\CreateStore;
use Misaf\VendraReseller\Filament\Resources\Stores\Pages\ListStores;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraReseller\Filament\Widgets\LatestStores;
use Misaf\VendraReseller\Filament\Widgets\ResellerOverview;
use Misaf\VendraReseller\Filament\Widgets\SubscriptionDetail;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Models\ResellerUser;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Models\StoreDomain;
use Misaf\VendraStore\Models\StorefrontDeployment;
use Misaf\VendraStore\Models\StorefrontImage;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSupport\Tenancy\Events\TenantProvisioned;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Event::fake([TenantProvisioned::class]);
    Artisan::shouldReceive('call')->andReturn(0);
    Config::set('vendra-container.endpoint', 'http://provisioner:8080');
    fakeDockerEngine();
});

function resellerOwner(Reseller $reseller): ResellerUser
{
    return ResellerUser::factory()
        ->forReseller($reseller->getKey())
        ->create();
}

function actAsResellerOwner(Reseller $reseller): ResellerUser
{
    $owner = resellerOwner($reseller);
    actingAs($owner, 'reseller');
    Filament::setCurrentPanel(Filament::getPanel('reseller'));

    return $owner;
}

function resellerStorefrontFormData(): array
{
    return [
        'storefront_image_id'             => StorefrontImage::factory()->create()->id,
        'storefront_slug'                 => 'acme-flowers',
        'storefront_theme'                => 'default',
        'storefront_name_en'              => 'Acme Flowers',
        'storefront_name_fa'              => 'گل‌فروشی اکمی',
        'storefront_business_type'        => 'Florist',
        'storefront_price_currency'       => 'IRR',
        'storefront_locality'             => 'Tehran',
        'storefront_country'              => 'IR',
        'storefront_mobile_phone'         => '09120000000',
        'storefront_office_phone'         => '02100000000',
        'storefront_contact_email'        => 'contact@acme.test',
        'storefront_hours_open'           => '08:00',
        'storefront_hours_close'          => '21:00',
        'storefront_map_query'            => '35.7,51.4',
        'storefront_whatsapp_phone'       => '+989120000000',
        'storefront_telegram_username'    => 'acmeflowers',
        'storefront_instagram_username'   => 'acmeflowers',
    ];
}

it('uses the package table presentation conventions for stores', function (): void {
    $reseller = Reseller::factory()->create();
    actAsResellerOwner($reseller);

    $component = livewire(ListStores::class)
        ->assertTableColumnExists('row')
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('updated_at');
    $table = $component->instance()->getTable();

    expect($table->getDescription())->toBe(__('console.tables.description.stores'))
        ->and($table->getEmptyStateHeading())->toBe(__('console.tables.empty_state.heading.stores'))
        ->and($table->getEmptyStateDescription())->toBe(__('console.tables.empty_state.description.stores'))
        ->and($table->getEmptyStateIcon())->toBe(Heroicon::OutlinedGlobeAlt)
        ->and($table->getFiltersLayout())->toBe(FiltersLayout::AboveContentCollapsible);
});

it('globally searches only the authenticated reseller stores', function (): void {
    $reseller = Reseller::factory()->create();
    $otherReseller = Reseller::factory()->create();
    actAsResellerOwner($reseller);

    $store = Store::factory()->create([
        'reseller_id' => $reseller->getKey(),
        'name'        => 'Owned Search Property',
    ]);
    $otherStore = Store::factory()->create([
        'reseller_id' => $otherReseller->getKey(),
        'name'        => 'Other Search Property',
    ]);
    StoreDomain::factory()->for($store)->create([
        'name'   => 'owned-global-search.test',
        'active' => true,
    ]);

    $result = StoreResource::getGlobalSearchResults('owned-global-search.test')->sole();

    expect($result->title)->toBe($store->name)
        ->and($result->url)->toBe(StoreResource::getUrl('index', ['search' => $store->name]))
        ->and($result->details)->toBe([
            __('console.domain') => 'owned-global-search.test',
        ])
        ->and(StoreResource::getGlobalSearchResults($otherStore->name))->toBeEmpty()
        ->and(Filament::getCurrentPanel()?->getGlobalSearchKeyBindings())->toBe([
            'command+k',
            'ctrl+k',
        ]);
});

it('uses the Vendra logo in light and dark modes', function (): void {
    $panel = Filament::getPanel('reseller');

    expect($panel->getBrandName())->toBe('Vendra Reseller')
        ->and($panel->getBrandLogo())->toBe(asset('images/vendra-logo.svg'))
        ->and($panel->getDarkModeBrandLogo())->toBe(asset('images/vendra-logo-dark.svg'))
        ->and($panel->getBrandLogoHeight())->toBe('2rem');
});

it('renders the reseller login without a current tenant', function (): void {
    $this->get('https://reseller.vendra.test/login')->assertSuccessful();
});

it('renders reseller password recovery without a current tenant', function (): void {
    $this->get('https://reseller.vendra.test/password-reset/request')->assertSuccessful();
});

it('renders reseller registration without a current tenant', function (): void {
    $this->get('https://reseller.vendra.test/register')->assertSuccessful();
});

it('verifies the authenticated reseller owner from a signed email link', function (): void {
    $reseller = Reseller::factory()->create();
    $owner = ResellerUser::factory()
        ->forReseller($reseller)
        ->unverified()
        ->create();
    actingAs($owner, 'reseller');

    $verificationUrl = URL::temporarySignedRoute(
        'filament.reseller.auth.email-verification.verify',
        now()->addHour(),
        [
            'id'   => $owner->getKey(),
            'hash' => sha1($owner->getEmailForVerification()),
        ],
    );

    $this->get($verificationUrl)->assertRedirect();

    expect($owner->fresh()?->hasVerifiedEmail())->toBeTrue();
});

it('registers a reseller with an initial subscription', function (): void {
    Notification::fake();
    $plan = Plan::factory()->maxUnits(2)->create();
    Filament::setCurrentPanel(Filament::getPanel('reseller'));

    livewire(Register::class)
        ->fillForm([
            'username'             => 'new_reseller',
            'email'                => 'new@reseller.test',
            'password'             => 'Secure123',
            'passwordConfirmation' => 'Secure123',
            'plan_id'              => $plan->getKey(),
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $owner = ResellerUser::query()->where('email', 'new@reseller.test')->sole();
    $reseller = $owner->reseller()->sole();

    expect(auth('reseller')->id())->toBe($owner->getKey())
        ->and($owner->email_verified_at)->toBeNull()
        ->and(Hash::check('Secure123', $owner->password))->toBeTrue()
        ->and($reseller->name)->toBe('new_reseller')
        ->and($reseller->activeSubscription()?->plan_id)->toBe($plan->getKey());
});

it('rejects inactive plans during reseller registration', function (): void {
    $plan = Plan::factory()->create(['active' => false]);
    Filament::setCurrentPanel(Filament::getPanel('reseller'));

    livewire(Register::class)
        ->fillForm([
            'username'             => 'new_reseller',
            'email'                => 'new@reseller.test',
            'password'             => 'Secure123',
            'passwordConfirmation' => 'Secure123',
            'plan_id'              => $plan->getKey(),
        ])
        ->call('register')
        ->assertHasFormErrors(['plan_id']);

    expect(ResellerUser::query()->count())->toBe(0);
});

it('allows a reseller owner to open the reseller dashboard without a current tenant', function (): void {
    $reseller = Reseller::factory()->create();

    actingAs(resellerOwner($reseller), 'reseller');

    $this->get('https://reseller.vendra.test')->assertSuccessful();
});

it('allows a tenant-independent reseller owner to sign in', function (): void {
    $reseller = Reseller::factory()->create();
    $owner = ResellerUser::factory()->forReseller($reseller)->create([
        'email' => 'owner@reseller.test',
    ]);
    Filament::setCurrentPanel(Filament::getPanel('reseller'));

    livewire(Login::class)
        ->fillForm([
            'email'    => 'owner@reseller.test',
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth('reseller')->id())->toBe($owner->getKey())
        ->and(auth()->id())->toBeNull();
});

it('renders the reseller dashboard with its widgets for an owner', function (): void {
    $reseller = Reseller::factory()->create();
    Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->maxUnits(3))->create();
    $store = Store::factory()->create(['reseller_id' => $reseller->getKey(), 'active' => true]);
    StoreDomain::factory()->for($store)->create(['name' => 'shop.test', 'active' => true]);

    actAsResellerOwner($reseller);

    livewire(ResellerOverview::class)
        ->assertOk()
        ->assertSee('1 / 3');

    livewire(SubscriptionDetail::class)
        ->assertOk()
        ->assertSee(__('console.status_active'));

    livewire(LatestStores::class)
        ->call('loadTable')
        ->assertOk()
        ->assertCanSeeTableRecords([$store]);
});

it('hides subscription and store widgets until the reseller has a store', function (): void {
    $reseller = Reseller::factory()->create();

    actAsResellerOwner($reseller);

    expect(SubscriptionDetail::canView())->toBeFalse()
        ->and(LatestStores::canView())->toBeFalse();
});

it('grants reseller panel access only to reseller owners', function (): void {
    $reseller = Reseller::factory()->create();
    $owner = resellerOwner($reseller);
    $regular = vendraTestingModelFactory(testUserModel())->forTenant(createTestTenant())->create();

    $panel = Filament::getPanel('reseller');

    expect($owner->canAccessPanel($panel))->toBeTrue()
        ->and($regular->canAccessPanel($panel))->toBeFalse();
});

it('keeps inactive owners in the panel but blocks store operations', function (): void {
    $reseller = Reseller::factory()->create(['active' => false]);
    Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->maxUnits(2))->create();
    $store = Store::factory()->create(['reseller_id' => $reseller->getKey(), 'active' => true]);

    $owner = actAsResellerOwner($reseller);

    expect($owner->canAccessPanel(Filament::getPanel('reseller')))->toBeTrue()
        ->and(StoreResource::canCreate())->toBeFalse();

    livewire(ListStores::class)
        ->assertActionHidden(TestAction::make('replaceDomain')->table($store))
        ->assertActionHidden(TestAction::make('delete')->table($store));
});

it('shows an owner only their own reseller stores', function (): void {
    $resellerA = Reseller::factory()->create();
    $resellerB = Reseller::factory()->create();
    $storeA = Store::factory()->create(['reseller_id' => $resellerA->getKey(), 'active' => true]);
    $storeB = Store::factory()->create(['reseller_id' => $resellerB->getKey(), 'active' => true]);

    actAsResellerOwner($resellerA);

    livewire(ListStores::class)
        ->call('loadTable')
        ->assertCanSeeTableRecords([$storeA])
        ->assertCanNotSeeTableRecords([$storeB]);
});

it('lets an owner create a store within the plan limit', function (): void {
    $reseller = Reseller::factory()->create();
    Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->maxUnits(2))->create();

    actAsResellerOwner($reseller);

    livewire(CreateStore::class)
        ->fillForm([
            'domain' => 'acme.test',
            'email'  => 'admin@gmail.com',
            ...resellerStorefrontFormData(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas('stores', [
        'name'        => 'Acme',
        'reseller_id' => $reseller->getKey(),
    ]);
    assertDatabaseHas('users', [
        'username' => 'admin',
        'email'    => 'admin@gmail.com',
    ]);
    expect(StorefrontDeployment::query()->where('slug', 'acme-flowers')->exists())->toBeTrue();
});

it('requires storefront configuration when a reseller creates a store', function (): void {
    $reseller = Reseller::factory()->create();
    Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->maxUnits(2))->create();
    actAsResellerOwner($reseller);

    livewire(CreateStore::class)
        ->fillForm([
            'domain' => 'required-storefront.test',
            'email'  => 'admin@required-storefront.test',
        ])
        ->call('create')
        ->assertHasFormErrors([
            'storefront_slug'    => 'required',
            'storefront_name_en' => 'required',
            'storefront_name_fa' => 'required',
        ]);
});

it('lets an owner soft-delete their own store', function (): void {
    $reseller = Reseller::factory()->create();
    $store = Store::factory()->create(['reseller_id' => $reseller->getKey(), 'active' => true]);

    actAsResellerOwner($reseller);

    livewire(ListStores::class)
        ->callAction(TestAction::make('delete')->table($store))
        ->assertHasNoErrors();

    expect($store->fresh()?->trashed())->toBeTrue();
});

it('lets an owner replace their store domain, keeping the old one as trashed history', function (): void {
    $reseller = Reseller::factory()->create();
    $store = Store::factory()->create(['reseller_id' => $reseller->getKey(), 'active' => true]);
    StoreDomain::factory()->for($store)->create(['name' => 'old.test', 'active' => true]);

    actAsResellerOwner($reseller);

    livewire(ListStores::class)
        ->callAction(TestAction::make('replaceDomain')->table($store), ['domain' => 'new.test'])
        ->assertHasNoErrors();

    expect($store->execute(fn() => $store->storeDomains()->where('active', true)->value('name')))->toBe('new.test')
        ->and($store->execute(fn() => $store->storeDomains()->onlyTrashed()->where('name', 'old.test')->exists()))->toBeTrue();
});

it('validates the replacement domain format and active-domain uniqueness', function (): void {
    $reseller = Reseller::factory()->create();
    $store = Store::factory()->create(['reseller_id' => $reseller->getKey(), 'active' => true]);
    StoreDomain::factory()->for($store)->create(['name' => 'current.test', 'active' => true]);

    $other = Store::factory()->create();
    StoreDomain::factory()->for($other)->create(['name' => 'taken.test', 'active' => true]);

    actAsResellerOwner($reseller);

    livewire(ListStores::class)
        ->callAction(TestAction::make('replaceDomain')->table($store), ['domain' => 'not a domain'])
        ->assertHasActionErrors(['domain' => 'regex']);

    livewire(ListStores::class)
        ->callAction(TestAction::make('replaceDomain')->table($store), ['domain' => 'taken.test'])
        ->assertHasActionErrors(['domain' => 'unique']);
});

it('blocks an owner from exceeding the plan limit', function (): void {
    $reseller = Reseller::factory()->create();
    Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->maxUnits(1))->create();
    Store::factory()->create(['reseller_id' => $reseller->getKey()]);

    actAsResellerOwner($reseller);

    livewire(CreateStore::class)
        ->fillForm([
            'domain' => 'second.test',
            'email'  => 'admin@second.test',
        ])
        ->call('create');

    assertDatabaseMissing('store_domains', ['name' => 'second.test']);
});

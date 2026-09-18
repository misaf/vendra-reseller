<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Providers;

use Composer\InstalledVersions;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Misaf\VendraReseller\Auth\ResellerPanelAccessResolver;
use Misaf\VendraReseller\Console\Commands\ProvisionStoreCommand;
use Misaf\VendraReseller\Listeners\NotifyActivatedSubscriber;
use Misaf\VendraReseller\Listeners\RemindExpiringSubscriber;
use Misaf\VendraReseller\Listeners\SuspendSubscriberStores;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Support\EloquentStoreResellerResolver;
use Misaf\VendraStore\Contracts\StoreResellerResolver;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraSubscription\Events\SubscriptionActivated;
use Misaf\VendraSubscription\Events\SubscriptionCancelled;
use Misaf\VendraSubscription\Events\SubscriptionExpiringSoon;
use Misaf\VendraSubscription\Events\SubscriptionGraceExpired;
use Misaf\VendraUser\Support\PanelAccessRegistry;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Registers the reseller package's console command, its subscription event
 * reactions, and both sides of the reseller/store relationship.
 *
 * The subscription engine only raises generic lifecycle events; the reseller
 * domain reacts by notifying contacts and suspending expired stores, so those
 * listeners are wired here instead of the host app.
 *
 * The store's billing reseller is wired here too, and only here:
 * `misaf/vendra-store` sits below this package and holds nothing but the
 * reseller key, so this provider
 * supplies the {@see StoreResellerResolver} adapter and registers `$store->reseller()`
 * on the Store model. That is what keeps the dependency arrow pointing one way,
 * reseller → store, with no cycle.
 */
final class ResellerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vendra-reseller')
            ->hasTranslations()
            ->hasMigrations([
                'create_resellers_table',
            ])
            ->hasCommand(ProvisionStoreCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(StoreResellerResolver::class, EloquentStoreResellerResolver::class);
    }

    public function packageBooted(): void
    {
        AboutCommand::add('Vendra Reseller', fn (): array => [
            'Version' => InstalledVersions::getPrettyVersion('misaf/vendra-reseller'),
        ]);

        /*
        | Reseller authorization lives here: the resolver gates panel entry
        | on being the main account of an active reseller, and the reseller guard
        | resolves only platform identities (tenant_id IS NULL) through the
        | shared platform provider, so a tenant row can never win a lookup.
        */
        $this->app->make(PanelAccessRegistry::class)->register(new ResellerPanelAccessResolver);

        $this->useResellerUserProvider();

        /*
        | The inverse of Reseller::stores(). Registered from this side so the
        | store package never names the reseller domain. The keys and the
        | relation name are explicit because Eloquent would otherwise infer
        | them from this closure rather than from "reseller".
        */
        Store::resolveRelationUsing(
            'reseller',
            fn (Store $store): BelongsTo => $store->belongsTo(Reseller::class, 'reseller_id', 'id', 'reseller'),
        );

        Event::listen(SubscriptionActivated::class, NotifyActivatedSubscriber::class);
        Event::listen(SubscriptionCancelled::class, SuspendSubscriberStores::class);
        Event::listen(SubscriptionExpiringSoon::class, RemindExpiringSubscriber::class);
        Event::listen(SubscriptionGraceExpired::class, SuspendSubscriberStores::class);
    }

    /**
     * Point the reseller guard at the reseller user provider, which resolves
     * platform identities only, so a tenant row sharing an email can never
     * win a lookup. The provider and its password broker are declared in
     * `config/auth.php`.
     */
    private function useResellerUserProvider(): void
    {
        Config::set('auth.guards.reseller.provider', 'reseller');
    }
}

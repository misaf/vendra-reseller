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
use Misaf\VendraReseller\Listeners\NotifyDroppedPlanChange;
use Misaf\VendraReseller\Listeners\NotifyInvoiceIssued;
use Misaf\VendraReseller\Listeners\RemindExpiringSubscriber;
use Misaf\VendraReseller\Listeners\RenewAfterWalletDeposit;
use Misaf\VendraReseller\Listeners\SuspendSubscriberStores;
use Misaf\VendraReseller\Listeners\WarnResellerOfStoreLimit;
use Misaf\VendraReseller\Listeners\WarnResellersOfOutgrownPlan;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Support\EloquentStoreResellerResolver;
use Misaf\VendraReseller\Support\ResellerPlanUsageGuard;
use Misaf\VendraReseller\Support\ResellerStoreSuspender;
use Misaf\VendraStore\Contracts\StoreResellerResolver;
use Misaf\VendraStore\Events\StoreLimitApproached;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraSubscription\Contracts\PlanUsageGuard;
use Misaf\VendraSubscription\Contracts\SubscriptionUnitSuspender;
use Misaf\VendraSubscription\Events\PlanEntitlementsChanged;
use Misaf\VendraSubscription\Events\ScheduledPlanChangeDropped;
use Misaf\VendraSubscription\Events\SubscriptionActivated;
use Misaf\VendraSubscription\Events\SubscriptionCancelled;
use Misaf\VendraSubscription\Events\SubscriptionExpiringSoon;
use Misaf\VendraSubscription\Events\SubscriptionGraceExpired;
use Misaf\VendraSubscription\Events\SubscriptionInvoiceIssued;
use Misaf\VendraTransaction\Events\TransactionApproved;
use Misaf\VendraUser\Support\PanelAccessRegistry;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * The {@see StoreResellerResolver} binding and `$store->reseller()` live here so
 * the store package never depends on this one.
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
            ->hasConsoleCommand(ProvisionStoreCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(StoreResellerResolver::class, EloquentStoreResellerResolver::class);
        $this->app->singleton(SubscriptionUnitSuspender::class, ResellerStoreSuspender::class);
        $this->app->singleton(PlanUsageGuard::class, ResellerPlanUsageGuard::class);
    }

    public function packageBooted(): void
    {
        AboutCommand::add('Vendra Reseller', fn (): array => [
            'Version' => InstalledVersions::getPrettyVersion('misaf/vendra-reseller'),
        ]);

        /*
        | Reseller authorization lives here: the resolver gates panel entry
        | on being the main account of an active reseller, and the reseller guard
        | resolves only tenantless identities (tenant_id IS NULL) through
        | the shared tenantless provider, so a tenant row can never win a lookup.
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

        Event::listen(PlanEntitlementsChanged::class, WarnResellersOfOutgrownPlan::class);
        Event::listen(ScheduledPlanChangeDropped::class, NotifyDroppedPlanChange::class);
        Event::listen(StoreLimitApproached::class, WarnResellerOfStoreLimit::class);
        Event::listen(SubscriptionActivated::class, NotifyActivatedSubscriber::class);
        Event::listen(SubscriptionCancelled::class, SuspendSubscriberStores::class);
        Event::listen(SubscriptionExpiringSoon::class, RemindExpiringSubscriber::class);
        Event::listen(SubscriptionGraceExpired::class, SuspendSubscriberStores::class);
        Event::listen(SubscriptionInvoiceIssued::class, NotifyInvoiceIssued::class);
        Event::listen(TransactionApproved::class, RenewAfterWalletDeposit::class);
    }

    /**
     * Point the reseller guard at a provider that only resolves tenantless users.
     */
    private function useResellerUserProvider(): void
    {
        Config::set('auth.guards.reseller.provider', 'reseller');
    }
}

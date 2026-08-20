<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Providers;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Misaf\VendraReseller\Console\Commands\ProvisionStoreCommand;
use Misaf\VendraReseller\Listeners\NotifyActivatedSubscriber;
use Misaf\VendraReseller\Listeners\RemindExpiringSubscriber;
use Misaf\VendraReseller\Listeners\SuspendSubscriberStores;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Support\ResellerStoreOwnerResolver;
use Misaf\VendraStore\Contracts\StoreOwnerResolver;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraSubscription\Events\SubscriptionActivated;
use Misaf\VendraSubscription\Events\SubscriptionExpiringSoon;
use Misaf\VendraSubscription\Events\SubscriptionGraceExpired;

/**
 * Registers the reseller package's console command, its subscription event
 * reactions, and both sides of the reseller/store relationship.
 *
 * The subscription engine only raises generic lifecycle events; the reseller
 * domain reacts by notifying owners and suspending expired stores, so those
 * listeners are wired here instead of the host app.
 *
 * Store ownership is wired here too, and only here: `misaf/vendra-store` sits
 * below this package and holds nothing but the owner key, so this provider
 * supplies the {@see StoreOwnerResolver} adapter and registers `$store->reseller()`
 * on the Store model. That is what keeps the dependency arrow pointing one way,
 * reseller → store, with no cycle.
 */
final class ResellerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([
            ProvisionStoreCommand::class,
        ]);

        $this->app->bind(StoreOwnerResolver::class, ResellerStoreOwnerResolver::class);
    }

    public function boot(): void
    {
        /*
         | The inverse of Reseller::stores(). Registered from this side so the
         | store package never names the reseller domain. The keys and the
         | relation name are explicit because Eloquent would otherwise infer
         | them from this closure rather than from "reseller".
         */
        Store::resolveRelationUsing(
            'reseller',
            fn(Store $store): BelongsTo => $store->belongsTo(Reseller::class, 'reseller_id', 'id', 'reseller'),
        );

        Event::listen(SubscriptionActivated::class, NotifyActivatedSubscriber::class);
        Event::listen(SubscriptionExpiringSoon::class, RemindExpiringSubscriber::class);
        Event::listen(SubscriptionGraceExpired::class, SuspendSubscriberStores::class);
    }
}

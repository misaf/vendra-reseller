<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Misaf\VendraReseller\Console\Commands\ProvisionPropertyCommand;
use Misaf\VendraReseller\Listeners\NotifyActivatedSubscriber;
use Misaf\VendraReseller\Listeners\RemindExpiringSubscriber;
use Misaf\VendraReseller\Listeners\SuspendSubscriberProperties;
use Misaf\VendraSubscription\Events\SubscriptionActivated;
use Misaf\VendraSubscription\Events\SubscriptionExpiringSoon;
use Misaf\VendraSubscription\Events\SubscriptionGraceExpired;

/**
 * Registers the reseller package's console command and its subscription event
 * reactions. The subscription engine only raises generic lifecycle events; the
 * reseller domain reacts by notifying owners and suspending expired
 * properties, so those listeners are wired here instead of the host app.
 */
final class ResellerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([
            ProvisionPropertyCommand::class,
        ]);
    }

    public function boot(): void
    {
        Event::listen(SubscriptionActivated::class, NotifyActivatedSubscriber::class);
        Event::listen(SubscriptionExpiringSoon::class, RemindExpiringSubscriber::class);
        Event::listen(SubscriptionGraceExpired::class, SuspendSubscriberProperties::class);
    }
}

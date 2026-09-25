<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Notifications\StoreLimitApproachedNotification;
use Misaf\VendraStore\Events\StoreLimitApproached;

final class WarnResellerOfStoreLimit
{
    /**
     * Warn once per store, limit and threshold for each subscription period.
     *
     * A store that deletes and re-adds around a threshold would otherwise be
     * warned on every crossing.
     */
    public function handle(StoreLimitApproached $event): void
    {
        $reseller = Reseller::query()->find($event->store->reseller_id);

        if ($reseller === null) {
            return;
        }

        $subscription = $reseller->activeSubscription();
        $period = $subscription->id ?? 'none';
        $warningKey = "store-limit-warning:{$event->store->id}:{$event->limit->value}:{$event->percent}:{$period}";

        $expiresAt = $subscription?->ends_at?->isFuture() === true ? $subscription->ends_at : Date::now()->addMonth();

        if (! Cache::add($warningKey, true, $expiresAt)) {
            return;
        }

        $reseller->notifyContact(new StoreLimitApproachedNotification(
            $event->store->name,
            $event->limit->getLabel(),
            $event->percent,
        ));
    }
}

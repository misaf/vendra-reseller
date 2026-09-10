<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Misaf\VendraReseller\Events\ResellerOffboarded;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Support\SubscriptionRegistry;

final readonly class OffboardResellerAction
{
    public const int MAX_REASON_LENGTH = 2000;

    public function __construct(private SubscriptionRegistry $subscriptionRegistry) {}

    public function execute(Reseller $reseller, string $reason): Reseller
    {
        $reason = mb_trim($reason);

        throw_if($reason === '', InvalidArgumentException::class, 'An offboarding reason is required.');

        throw_if(Str::length($reason) > self::MAX_REASON_LENGTH, InvalidArgumentException::class, 'The offboarding reason may not exceed '.self::MAX_REASON_LENGTH.' characters.');

        return DB::transaction(function () use ($reseller, $reason): Reseller {
            $lockedReseller = Reseller::query()
                ->withTrashed()
                ->whereKey($reseller->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReseller->offboarded_at !== null) {
                return $lockedReseller;
            }

            $tenants = $lockedReseller->stores()
                ->lockForUpdate()
                ->get();
            $cancelledSubscriptionCount = $this->subscriptionRegistry->cancelOpen($lockedReseller);
            $offboardedAt = now();

            $lockedReseller->forceFill([
                'active' => false,
                'offboarded_at' => $offboardedAt,
                'offboarding_reason' => $reason,
            ])->save();

            $tenants->each->delete();
            $lockedReseller->delete();

            event(new ResellerOffboarded(resellerId: $lockedReseller->id, reason: $reason, offboardedAt: $offboardedAt->toImmutable(), cancelledSubscriptionCount: $cancelledSubscriptionCount, offboardedTenantCount: $tenants->count()));

            return $lockedReseller;
        }, attempts: 5);
    }
}

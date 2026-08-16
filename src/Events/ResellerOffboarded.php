<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class ResellerOffboarded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $resellerId,
        public readonly string $reason,
        public readonly CarbonImmutable $offboardedAt,
        public readonly int $cancelledSubscriptionCount,
        public readonly int $offboardedTenantCount,
    ) {}
}

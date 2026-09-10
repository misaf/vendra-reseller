<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ResellerOffboarded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $resellerId,
        public string $reason,
        public CarbonImmutable $offboardedAt,
        public int $cancelledSubscriptionCount,
        public int $offboardedTenantCount,
    ) {}
}

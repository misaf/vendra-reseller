<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Observers;

use LogicException;
use Misaf\VendraReseller\Models\Reseller;

/**
 * Guard the offboarding invariant by aborting an invalid delete.
 */
final class ResellerObserver
{
    public function deleting(Reseller $reseller): void
    {
        if ($reseller->offboarded_at === null) {
            throw new LogicException("Reseller [{$reseller->id}] must be offboarded through OffboardResellerAction before deletion.");
        }
    }
}

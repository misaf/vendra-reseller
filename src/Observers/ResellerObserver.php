<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Observers;

use LogicException;
use Misaf\VendraReseller\Models\Reseller;

/**
 * Guards the offboarding invariant. Synchronous because the throw is the point:
 * it has to abort the delete rather than report on one that already happened.
 */
final class ResellerObserver
{
    public function deleting(Reseller $reseller): void
    {
        if (null === $reseller->offboarded_at) {
            throw new LogicException("Reseller [{$reseller->id}] must be offboarded through OffboardResellerAction before deletion.");
        }
    }
}

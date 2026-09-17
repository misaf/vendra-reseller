<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Support\Facades\DB;
use LogicException;
use Misaf\VendraReseller\Models\Reseller;

final class SetResellerActiveAction
{
    /**
     * @throws LogicException
     */
    public function execute(Reseller $reseller, bool $active): Reseller
    {
        return DB::transaction(function () use ($reseller, $active): Reseller {
            $lockedReseller = $reseller->refreshForUpdate();

            /*
            | Offboarding is final: it cancels subscriptions and retires the
            | stores, so reactivating the row would hand back a reseller with
            | nothing behind it.
            */
            if ($lockedReseller->offboarded_at !== null) {
                throw new LogicException("Reseller [{$lockedReseller->getKey()}] is offboarded and cannot change its active state.");
            }

            $lockedReseller->forceFill(['active' => $active])->save();

            return $lockedReseller;
        });
    }
}

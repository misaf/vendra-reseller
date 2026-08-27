<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Support\Facades\DB;
use LogicException;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Models\ResellerUser;

final class SetResellerOwnerAccountEnabledAction
{
    public function execute(ResellerUser $owner, bool $enabled): ResellerUser
    {
        return DB::transaction(function () use ($owner, $enabled): ResellerUser {
            Reseller::query()
                ->whereKey($owner->reseller_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedOwner = ResellerUser::query()
                ->withTrashed()
                ->whereKey($owner->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($enabled) {
                $hasAnotherOwner = ResellerUser::query()
                    ->where('reseller_id', $lockedOwner->reseller_id)
                    ->whereKeyNot($lockedOwner->getKey())
                    ->exists();

                if ($hasAnotherOwner) {
                    throw new LogicException("Reseller [{$lockedOwner->reseller_id}] already has an enabled owner account.");
                }

                $lockedOwner->restore();
            } elseif ( ! $lockedOwner->trashed()) {
                $lockedOwner->delete();
            }

            return $lockedOwner;
        });
    }
}

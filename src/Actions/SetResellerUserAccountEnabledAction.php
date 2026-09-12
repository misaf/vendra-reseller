<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Support\Facades\DB;
use LogicException;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraUser\Models\User;

final class SetResellerUserAccountEnabledAction
{
    public function execute(Reseller $reseller, User $user, bool $enabled): User
    {
        return DB::transaction(function () use ($reseller, $user, $enabled): User {
            $lockedReseller = Reseller::query()
                ->whereKey($reseller->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedOwner = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
            | Disabling retires the membership, not the identity: the user can
            | no longer reach the reseller panel but keeps any tenant access.
            | Enabling restores the membership, refusing when another active
            | user has taken the seat in the meantime.
            */
            if ($enabled) {
                $hasAnotherOwner = DB::table('reseller_users')
                    ->where('reseller_id', $lockedReseller->getKey())
                    ->whereNull('deleted_at')
                    ->where('user_id', '!=', $lockedOwner->getKey())
                    ->exists();

                if ($hasAnotherOwner) {
                    throw new LogicException("Reseller [{$lockedReseller->getKey()}] already has an enabled user account.");
                }

                DB::table('reseller_users')
                    ->where('reseller_id', $lockedReseller->getKey())
                    ->where('user_id', $lockedOwner->getKey())
                    ->whereNotNull('deleted_at')
                    ->update(['deleted_at' => null]);
            } else {
                DB::table('reseller_users')
                    ->where('reseller_id', $lockedReseller->getKey())
                    ->where('user_id', $lockedOwner->getKey())
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now()]);
            }

            return $lockedOwner;
        });
    }
}

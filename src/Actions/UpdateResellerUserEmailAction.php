<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Support\Facades\DB;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraUser\Models\User;

final class UpdateResellerUserEmailAction
{
    /**
     * The caller validates the email (format and uniqueness among active
     * users); the users table's unique guard still rejects a duplicate.
     */
    public function execute(Reseller $reseller, User $user, string $email, bool $verified = true): User
    {
        return DB::transaction(function () use ($reseller, $user, $email, $verified): User {
            $lockedReseller = Reseller::query()
                ->whereKey($reseller->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedOwner = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedOwner->forceFill([
                'email' => $email,
                'email_verified_at' => $verified ? now() : null,
            ])->save();

            $lockedReseller->update(['email' => $lockedOwner->email]);

            return $lockedOwner;
        });
    }
}

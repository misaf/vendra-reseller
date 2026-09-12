<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraUser\Models\User;

final class CreateResellerUserAction
{
    public function execute(
        Reseller $reseller,
        string $username,
        string $email,
        string $password,
        bool $emailVerified = true,
    ): User {
        return DB::transaction(function () use ($reseller, $username, $email, $password, $emailVerified): User {
            $lockedReseller = Reseller::query()
                ->whereKey($reseller->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReseller->users()->exists()) {
                throw new LogicException("Reseller [{$lockedReseller->id}] already has a user account.");
            }

            /*
            | The user is a canonical user with no tenant of its own: the
            | reseller link lives in the membership pivot, never on the
            | identity. Email and username uniqueness across platform-level
            | users is enforced by the users table's global guards.
            */
            $user = User::query()->create([
                'tenant_id' => null,
                'username' => $username,
                'email' => $email,
                'email_verified_at' => $emailVerified ? Date::now() : null,
                'password' => Hash::make($password),
            ]);

            if ($user->tenant_id !== null) {
                /*
                | The tenant hook stamps the current tenant when there is one
                | (tests, future callers inside tenant middleware). A reseller
                | user is a platform-level identity and must never belong to
                | a tenant, so the stamp is reverted on the same transaction.
                */
                $user->forceFill(['tenant_id' => null])->save();
            }

            $lockedReseller->users()->attach($user->getKey());

            $lockedReseller->update(['email' => $email]);

            return $user;
        });
    }
}

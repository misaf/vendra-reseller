<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Support\Facades\DB;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraUser\Models\User;

final readonly class ReplaceResellerUserAction
{
    public function __construct(private CreateResellerUserAction $createOwner) {}

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

            /*
            | Only the memberships are retired: the former user's canonical
            | identity stays intact, since it may hold tenant or other
            | reseller access beyond this reseller. History is preserved as
            | soft-deleted membership rows.
            */
            DB::table('reseller_users')
                ->where('reseller_id', $lockedReseller->getKey())
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now()]);

            return $this->createOwner->execute(
                $lockedReseller,
                $username,
                $email,
                $password,
                $emailVerified,
            );
        });
    }
}

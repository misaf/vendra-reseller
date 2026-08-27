<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Support\Facades\DB;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Models\ResellerUser;

final class ReplaceResellerOwnerAction
{
    public function __construct(private readonly CreateResellerOwnerAction $createOwner) {}

    public function execute(
        Reseller $reseller,
        string $username,
        string $email,
        string $password,
        bool $emailVerified = true,
    ): ResellerUser {
        return DB::transaction(function () use ($reseller, $username, $email, $password, $emailVerified): ResellerUser {
            $lockedReseller = Reseller::query()
                ->whereKey($reseller->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedReseller->ownerUser()->lockForUpdate()->first()?->delete();

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

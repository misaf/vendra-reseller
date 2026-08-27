<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Misaf\VendraReseller\Models\ResellerUser;

final class UpdateResellerOwnerPasswordAction
{
    public function execute(ResellerUser $owner, string $password): ResellerUser
    {
        return DB::transaction(function () use ($owner, $password): ResellerUser {
            $lockedOwner = ResellerUser::query()
                ->whereKey($owner->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedOwner->forceFill([
                'password'       => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            return $lockedOwner;
        });
    }
}

<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Misaf\VendraUser\Models\User;

final class UpdateResellerUserPasswordAction
{
    public function execute(User $user, string $password): User
    {
        return DB::transaction(function () use ($user, $password): User {
            $lockedOwner = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedOwner->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            return $lockedOwner;
        });
    }
}

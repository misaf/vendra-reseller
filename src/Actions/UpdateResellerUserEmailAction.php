<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraUser\Models\User;

final class UpdateResellerUserEmailAction
{
    /**
     * The caller validates the email; the unique index still rejects a duplicate.
     */
    public function execute(Reseller $reseller, string $email, bool $verified = true): User
    {
        return DB::transaction(function () use ($reseller, $email, $verified): User {
            $lockedReseller = $reseller->refreshForUpdate();

            throw_if($lockedReseller->trashed(), (new ModelNotFoundException)->setModel(Reseller::class));

            $lockedUser = User::query()
                ->whereKey($lockedReseller->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedUser->forceFill([
                'email' => $email,
                'email_verified_at' => $verified ? now() : null,
            ])->save();

            return $lockedUser;
        });
    }
}

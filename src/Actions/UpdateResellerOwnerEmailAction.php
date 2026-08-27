<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Models\ResellerUser;

final class UpdateResellerOwnerEmailAction
{
    public function execute(ResellerUser $owner, string $email, bool $verified = true): ResellerUser
    {
        return DB::transaction(function () use ($owner, $email, $verified): ResellerUser {
            $lockedReseller = Reseller::query()
                ->whereKey($owner->reseller_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedOwner = ResellerUser::query()
                ->whereKey($owner->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Validator::make(['email' => $email], [
                'email' => [
                    'required',
                    'email',
                    Rule::unique(ResellerUser::class, 'email')
                        ->withoutTrashed()
                        ->ignore($lockedOwner->getKey()),
                ],
            ])->validate();

            $lockedOwner->forceFill([
                'email'             => $email,
                'email_verified_at' => $verified ? now() : null,
            ])->save();

            $lockedReseller->update(['email' => $lockedOwner->email]);

            return $lockedOwner;
        });
    }
}

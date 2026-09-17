<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraUser\Actions\CreateUserAction;
use Misaf\VendraUser\Models\User;

final readonly class ReplaceResellerUserAction
{
    public function __construct(private CreateUserAction $createUserAction) {}

    /**
     * Only the reseller's pointer moves: the former main account's canonical
     * identity stays intact, since it may hold access beyond this reseller.
     */
    public function execute(
        Reseller $reseller,
        string $username,
        string $email,
        string $password,
        bool $emailVerified = true,
    ): User {
        return DB::transaction(function () use ($reseller, $username, $email, $password, $emailVerified): User {
            $lockedReseller = $reseller->refreshForUpdate();

            throw_if($lockedReseller->trashed(), (new ModelNotFoundException)->setModel(Reseller::class));

            $user = $this->createUserAction->execute(
                tenant: null,
                username: $username,
                email: $email,
                password: $password,
                isVerified: $emailVerified,
            );

            $lockedReseller->forceFill(['user_id' => $user->getKey()])->save();

            return $user;
        });
    }
}

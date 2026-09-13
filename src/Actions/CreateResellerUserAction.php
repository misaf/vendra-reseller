<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Support\Facades\DB;
use LogicException;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraUser\Actions\CreateUserAction;
use Misaf\VendraUser\Models\User;

final readonly class CreateResellerUserAction
{
    public function __construct(private CreateUserAction $createUserAction) {}

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
            $user = $this->createUserAction->execute(
                tenant: null,
                username: $username,
                email: $email,
                password: $password,
                isVerified: $emailVerified,
            );

            $lockedReseller->users()->attach($user->getKey());

            $lockedReseller->update(['email' => $email]);

            return $user;
        });
    }
}

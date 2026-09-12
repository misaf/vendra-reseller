<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Auth;

use Illuminate\Support\Facades\DB;
use Misaf\VendraUser\Contracts\PanelAccessResolver;
use Misaf\VendraUser\Models\User;

/**
 * Reseller panel authorization, owned by the reseller domain.
 *
 * Membership rows live in the `reseller_users` pivot and are written only
 * from here; an active (non-deleted) membership grants panel access. An
 * offboarded reseller keeps its users' memberships, so they can still
 * sign in but resolve no reseller and see nothing. Revoking the
 * membership removes panel access without deleting the identity.
 */
final class ResellerPanelAccessResolver implements PanelAccessResolver
{
    public function panelId(): string
    {
        return 'reseller';
    }

    public function canAccess(User $user): bool
    {
        return DB::table('reseller_users')
            ->where('user_id', $user->getKey())
            ->whereNull('deleted_at')
            ->exists();
    }
}

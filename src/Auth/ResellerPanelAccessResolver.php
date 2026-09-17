<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Auth;

use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraUser\Contracts\PanelAccessResolver;
use Misaf\VendraUser\Models\User;

/**
 * Reseller panel authorization, owned by the reseller domain.
 *
 * A user may enter the panel only while it is the main account of an active
 * reseller. Deactivating the reseller is how its account is locked out, and an
 * offboarded (soft-deleted) reseller grants nothing; either way the canonical
 * identity is never deleted.
 */
final class ResellerPanelAccessResolver implements PanelAccessResolver
{
    public function panelId(): string
    {
        return 'reseller';
    }

    public function canAccess(User $user): bool
    {
        return Reseller::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->exists();
    }
}

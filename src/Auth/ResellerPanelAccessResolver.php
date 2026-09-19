<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Auth;

use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraUser\Contracts\PanelAccessResolver;
use Misaf\VendraUser\Models\User;

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

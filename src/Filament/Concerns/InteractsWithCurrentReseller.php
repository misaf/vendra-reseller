<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Concerns;

use Filament\Facades\Filament;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraUser\Models\User;

trait InteractsWithCurrentReseller
{
    protected static function currentReseller(): ?Reseller
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return self::resellerFor($user);
    }

    /**
     * Resolve the user's reseller, memoized per request.
     */
    private static function resellerFor(User $user): ?Reseller
    {
        return once(fn (): ?Reseller => Reseller::forUser($user));
    }
}

<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Concerns;

use Filament\Facades\Filament;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraUser\Models\User;

trait InteractsWithCurrentReseller
{
    /**
     * The billing reseller of the currently authenticated user, if any.
     */
    protected static function currentReseller(): ?Reseller
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return self::resellerFor($user);
    }

    /**
     * Resolved once per request and user: the store query scope, the create
     * gate, the list page and the dashboard widgets each ask while rendering.
     */
    private static function resellerFor(User $user): ?Reseller
    {
        return once(fn (): ?Reseller => Reseller::forUser($user));
    }
}

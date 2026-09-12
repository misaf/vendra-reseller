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
    protected function currentReseller(): ?Reseller
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return Reseller::forUser($user);
    }
}

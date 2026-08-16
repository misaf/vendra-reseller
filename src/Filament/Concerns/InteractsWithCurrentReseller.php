<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Concerns;

use Filament\Facades\Filament;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Models\ResellerUser;

trait InteractsWithCurrentReseller
{
    /**
     * The billing reseller of the currently authenticated owner, if any.
     */
    protected function currentReseller(): ?Reseller
    {
        $user = Filament::auth()->user();

        if ( ! $user instanceof ResellerUser) {
            return null;
        }

        return Reseller::query()->find($user->reseller_id);
    }
}

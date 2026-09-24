<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Actions;

use Closure;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraStore\Filament\Actions\RemoveDomainAliasTableAction as BaseRemoveDomainAliasTableAction;

final class RemoveDomainAliasTableAction extends BaseRemoveDomainAliasTableAction
{
    /**
     * @return Closure(): bool
     */
    protected function authorizationCallback(): Closure
    {
        return StoreResource::canManageStores(...);
    }
}

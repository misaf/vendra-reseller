<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Actions;

use Closure;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraStore\Filament\Actions\MakeDomainPrimaryTableAction as BaseMakeDomainPrimaryTableAction;

final class MakeDomainPrimaryTableAction extends BaseMakeDomainPrimaryTableAction
{
    /**
     * @return Closure(): bool
     */
    protected function authorizationCallback(): Closure
    {
        return StoreResource::canManageStores(...);
    }
}

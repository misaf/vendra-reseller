<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Actions;

use Closure;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraStore\Filament\Actions\ReplaceDomainAction as BaseReplaceDomainAction;

final class ReplaceDomainAction extends BaseReplaceDomainAction
{
    /**
     * @return Closure(): bool
     */
    protected function authorizationCallback(): Closure
    {
        return fn(): bool => StoreResource::canCreate();
    }
}

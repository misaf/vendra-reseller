<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Properties\Actions;

use Closure;
use Misaf\VendraProperty\Filament\Actions\ReplaceDomainAction as BaseReplaceDomainAction;
use Misaf\VendraReseller\Filament\Resources\Properties\PropertyResource;

final class ReplaceDomainAction extends BaseReplaceDomainAction
{
    /**
     * @return Closure(): bool
     */
    protected function authorizationCallback(): Closure
    {
        return fn(): bool => PropertyResource::canCreate();
    }
}

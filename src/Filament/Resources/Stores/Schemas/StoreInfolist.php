<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Schemas;

use Filament\Schemas\Schema;
use Misaf\VendraStore\Filament\Resources\Stores\Schemas\StoreInfolist as BaseStoreInfolist;

final class StoreInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return BaseStoreInfolist::configure($schema);
    }
}

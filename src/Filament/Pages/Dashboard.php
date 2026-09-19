<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Misaf\VendraReseller\Filament\Widgets\GettingStarted;
use Misaf\VendraReseller\Filament\Widgets\LatestStores;
use Misaf\VendraReseller\Filament\Widgets\PlanSummary;
use Misaf\VendraReseller\Filament\Widgets\StoresNeedingAttention;

final class Dashboard extends BaseDashboard
{
    /**
     * @return list<class-string>
     */
    public function getWidgets(): array
    {
        return [
            GettingStarted::class,
            PlanSummary::class,
            StoresNeedingAttention::class,
            LatestStores::class,
        ];
    }

    public function getColumns(): int
    {
        return 1;
    }
}

<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Misaf\VendraReseller\Filament\Widgets\GettingStarted;
use Misaf\VendraReseller\Filament\Widgets\LatestStores;
use Misaf\VendraReseller\Filament\Widgets\PlanSummary;
use Misaf\VendraReseller\Filament\Widgets\StoresNeedingAttention;

/**
 * The reseller's home: what to do next, the plan they are on, the stores that
 * need them, and their latest stores — in that order.
 */
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

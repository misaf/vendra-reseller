<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Actions;

use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Misaf\VendraReseller\Actions\OffboardResellerAction as DomainOffboardResellerAction;
use Misaf\VendraReseller\Models\Reseller;

final class OffboardResellerAction extends DeleteAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('console.offboard_reseller'))
            ->modalHeading(__('console.offboard_reseller'))
            ->modalDescription(__('console.offboard_reseller_description'))
            ->schema([
                Textarea::make('offboarding_reason')
                    ->label(__('console.offboarding_reason'))
                    ->required()
                    ->maxLength(DomainOffboardResellerAction::MAX_REASON_LENGTH),
            ])
            ->using(function (
                Reseller $record,
                array $data,
                DomainOffboardResellerAction $offboardReseller,
            ): bool {
                $reason = $data['offboarding_reason'] ?? null;

                if ( ! is_string($reason)) {
                    return false;
                }

                $offboardReseller->execute($record, $reason);

                return true;
            });
    }
}

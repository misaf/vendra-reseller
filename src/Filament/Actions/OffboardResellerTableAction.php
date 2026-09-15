<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Actions;

use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Arr;
use Misaf\VendraReseller\Actions\OffboardResellerAction as DomainOffboardResellerAction;
use Misaf\VendraReseller\Models\Reseller;

final class OffboardResellerTableAction extends DeleteAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('vendra-reseller::actions.offboard_reseller'))
            ->modalHeading(__('vendra-reseller::actions.offboard_reseller'))
            ->modalDescription(__('vendra-reseller::messages.offboard_reseller_description'))
            ->schema([
                Textarea::make('offboarding_reason')
                    ->label(__('vendra-reseller::attributes.offboarding_reason'))
                    ->required()
                    ->maxLength(DomainOffboardResellerAction::MAX_REASON_LENGTH),
            ])
            ->using(function (
                Reseller $record,
                array $data,
                DomainOffboardResellerAction $offboardReseller,
            ): bool {
                $reason = Arr::get($data, 'offboarding_reason', null);

                if (! is_string($reason)) {
                    return false;
                }

                $offboardReseller->execute($record, mb_trim($reason));

                return true;
            });
    }
}

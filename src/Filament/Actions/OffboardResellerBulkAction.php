<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Actions;

use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Misaf\VendraReseller\Actions\OffboardResellerAction;
use Misaf\VendraReseller\Models\Reseller;

final class OffboardResellerBulkAction extends DeleteBulkAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('console.offboard_resellers'))
            ->modalHeading(__('console.offboard_resellers'))
            ->modalDescription(__('console.offboard_resellers_description'))
            ->schema([
                Textarea::make('offboarding_reason')
                    ->label(__('console.offboarding_reason'))
                    ->required()
                    ->maxLength(OffboardResellerAction::MAX_REASON_LENGTH),
            ])
            ->using(function (
                Collection $records,
                array $data,
                OffboardResellerAction $offboardReseller,
            ): void {
                $reason = $data['offboarding_reason'] ?? null;

                if ( ! is_string($reason)) {
                    return;
                }

                $records->each(function (Model $record) use ($offboardReseller, $reason): void {
                    if ( ! $record instanceof Reseller) {
                        return;
                    }

                    $offboardReseller->execute($record, $reason);
                });
            });
    }
}

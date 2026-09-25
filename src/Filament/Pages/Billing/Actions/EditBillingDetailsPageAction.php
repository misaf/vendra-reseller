<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Pages\Billing\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Misaf\VendraReseller\Actions\UpdateResellerBillingDetailsAction;
use Misaf\VendraReseller\Filament\Pages\Billing\Actions\Concerns\InteractsWithResellerBilling;

final class EditBillingDetailsPageAction extends Action
{
    use InteractsWithResellerBilling;

    public static function getDefaultName(): string
    {
        return 'editBillingDetails';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('vendra-reseller::attributes.edit_billing_details'))
            ->icon(Heroicon::OutlinedIdentification)
            ->color('gray')
            ->slideOver()
            ->fillForm(fn (): array => [
                'billing_name' => self::reseller()->billing_name,
                'billing_address' => self::reseller()->billing_address,
                'tax_id' => self::reseller()->tax_id,
            ])
            ->schema([
                TextInput::make('billing_name')
                    ->label(__('vendra-reseller::attributes.billing_name'))
                    ->helperText(__('vendra-reseller::attributes.billing_name_hint'))
                    ->maxLength(255),
                Textarea::make('billing_address')
                    ->label(__('vendra-reseller::attributes.billing_address'))
                    ->rows(3)
                    ->maxLength(1000),
                TextInput::make('tax_id')
                    ->label(__('vendra-reseller::attributes.tax_id'))
                    ->maxLength(64),
            ])
            ->action(function (array $data): void {
                resolve(UpdateResellerBillingDetailsAction::class)->execute(
                    self::reseller(),
                    self::nullableTrimmed(Arr::get($data, 'billing_name', null)),
                    self::nullableTrimmed(Arr::get($data, 'billing_address', null)),
                    self::nullableTrimmed(Arr::get($data, 'tax_id', null)),
                );

                Notification::make()->success()->title(__('vendra-reseller::attributes.billing_details_saved'))->send();
            });
    }

    private static function nullableTrimmed(mixed $value): ?string
    {
        $value = is_string($value) ? mb_trim($value) : '';

        return $value === '' ? null : $value;
    }
}

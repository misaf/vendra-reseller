<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Livewire\Component as Livewire;
use Misaf\LaravelEmailVerification\Rules\EmailValidation;
use Misaf\VendraStore\Filament\Forms\Components\StoreDomainInput;
use Misaf\VendraStore\Filament\Resources\Stores\Schemas\StoreForm as BaseStoreForm;

final class StoreForm
{
    public static function configure(Schema $schema): Schema
    {
        return BaseStoreForm::configure(
            $schema,
            creationFields: [
                StoreDomainInput::make(),

                TextInput::make('email')
                    ->afterStateUpdated(fn (Livewire $livewire) => $livewire->validateOnly('data.email'))
                    ->label(__('vendra-reseller::attributes.email'))
                    ->email()
                    ->extraAttributes(['dir' => 'ltr'])
                    ->live(onBlur: true)
                    ->maxLength(255)
                    ->required()
                    ->rules([
                        'bail',
                        'email:rfc,strict,spoof,filter,filter_unicode',
                        new EmailValidation,
                    ])
                    ->visibleOn('create'),
            ],
            storefrontIsOptional: false,
        );
    }
}
